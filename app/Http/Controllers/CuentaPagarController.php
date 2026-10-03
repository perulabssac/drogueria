<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\Compra;
use App\Models\CompraPago;
use App\Services\CuentaPagarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cuentas por pagar: deudas con proveedores por compras al crédito y sus pagos.
 * Consultan almacén y contador; pagan contador y administrador; anula pagos solo el administrador.
 */
class CuentaPagarController extends Controller
{
    /** Días para considerar una deuda "por vencer". */
    public const DIAS_POR_VENCER = 7;

    public const VISTAS = [
        'por_pagar' => 'Por pagar',
        'vencidas' => 'Vencidas',
        'por_vencer' => 'Vencen en '.self::DIAS_POR_VENCER.' días',
        'pagadas' => 'Pagadas',
    ];

    public function index(Request $request): Response
    {
        $sucursalId = $request->user()->sucursal_id;
        $vista = array_key_exists($request->string('vista')->toString(), self::VISTAS) ? $request->string('vista')->toString() : 'por_pagar';
        $buscar = trim($request->string('buscar')->toString());
        // "F001-9001" o "9001" → se busca por el número del documento
        $numero = str_contains($buscar, '-') ? ltrim(substr($buscar, strrpos($buscar, '-') + 1), '0') : $buscar;
        $hoy = today()->toDateString();

        // Compras al crédito vigentes de la sucursal
        $base = fn () => Compra::query()
            ->where('sucursal_id', $sucursalId)
            ->where('forma_pago', 'credito')
            ->where('estado', 'registrada');

        $compras = $base()
            ->with('proveedor:id,ruc,razon_social')
            ->withSum(['pagos as pagado' => fn ($q) => $q->where('estado', 'activo')], 'monto')
            ->when($vista === 'por_pagar', fn ($q) => $q->where('saldo', '>', 0))
            ->when($vista === 'vencidas', fn ($q) => $q->where('saldo', '>', 0)->whereDate('fecha_vencimiento', '<', $hoy))
            ->when($vista === 'por_vencer', fn ($q) => $q->where('saldo', '>', 0)
                ->whereDate('fecha_vencimiento', '>=', $hoy)
                ->whereDate('fecha_vencimiento', '<=', today()->addDays(self::DIAS_POR_VENCER)->toDateString()))
            ->when($vista === 'pagadas', fn ($q) => $q->where('saldo', '<=', 0))
            ->when($request->filled('proveedor'), fn ($q) => $q->where('proveedor_id', $request->integer('proveedor')))
            ->when($buscar, fn ($q) => $q->where(fn ($w) => $w
                ->where('numero', 'like', "%{$numero}%")
                ->orWhereHas('proveedor', fn ($p) => $p->where('razon_social', 'like', "%{$buscar}%")->orWhere('ruc', 'like', "{$buscar}%"))))
            ->orderByRaw($vista === 'pagadas' ? 'fecha_emision desc' : 'fecha_vencimiento asc')
            ->paginate(20)
            ->withQueryString();

        // Lo que se le debe a cada proveedor (para el resumen de la derecha)
        $porProveedor = $base()
            ->where('saldo', '>', 0)
            ->with('proveedor:id,razon_social')
            ->selectRaw('proveedor_id, SUM(saldo) as deuda, COUNT(*) as documentos, SUM(CASE WHEN fecha_vencimiento < ? THEN saldo ELSE 0 END) as vencido', [$hoy])
            ->groupBy('proveedor_id')
            ->orderByDesc('deuda')
            ->get()
            ->map(fn ($f) => [
                'proveedor_id' => $f->proveedor_id,
                'proveedor' => $f->proveedor?->razon_social,
                'deuda' => round((float) $f->deuda, 2),
                'vencido' => round((float) $f->vencido, 2),
                'documentos' => (int) $f->documentos,
            ]);

        $pendientes = $base()->where('saldo', '>', 0);

        return Inertia::render('CuentasPagar/Index', [
            'compras' => $compras,
            'porProveedor' => $porProveedor,
            'resumen' => [
                'por_pagar' => round((float) (clone $pendientes)->sum('saldo'), 2),
                'vencido' => round((float) (clone $pendientes)->whereDate('fecha_vencimiento', '<', $hoy)->sum('saldo'), 2),
                'por_vencer' => round((float) (clone $pendientes)->whereDate('fecha_vencimiento', '>=', $hoy)
                    ->whereDate('fecha_vencimiento', '<=', today()->addDays(self::DIAS_POR_VENCER)->toDateString())->sum('saldo'), 2),
                'pagado_mes' => round((float) CompraPago::query()
                    ->where('estado', 'activo')
                    ->whereHas('compra', fn ($q) => $q->where('sucursal_id', $sucursalId))
                    ->whereBetween('fecha', [today()->startOfMonth()->toDateString(), $hoy])
                    ->sum('monto'), 2),
            ],
            'vistas' => self::VISTAS,
            'filtros' => ['vista' => $vista, 'buscar' => $buscar, 'proveedor' => $request->integer('proveedor') ?: null],
            'diasPorVencer' => self::DIAS_POR_VENCER,
        ]);
    }

    /** Detalle de la deuda de una compra, con sus pagos y el formulario para pagar. */
    public function show(Request $request, Compra $compra): Response
    {
        $this->verificar($request, $compra);
        $compra->load('proveedor', 'pagos.usuario:id,name', 'pagos.anuladoPor:id,name');

        return Inertia::render('CuentasPagar/Show', [
            'compra' => $compra,
            'estadosPago' => Compra::ESTADOS_PAGO,
            'diasVencida' => $compra->diasVencida(),
            'medios' => CompraPago::MEDIOS,
            'puedePagar' => $request->user()->tieneRol('contador'),
            'puedeAnular' => $request->user()->tieneRol('admin'),
            'cajaAbierta' => (bool) Caja::abiertaDe($request->user()),
        ]);
    }

    public function pagar(Request $request, Compra $compra, CuentaPagarService $servicio): RedirectResponse
    {
        $this->verificar($request, $compra);

        $datos = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'medio' => ['required', Rule::in(array_keys(CompraPago::MEDIOS))],
            'monto' => ['required', 'numeric', 'gt:0'],
            'referencia' => [Rule::requiredIf(in_array($request->input('medio'), ['transferencia', 'deposito', 'cheque'], true)), 'nullable', 'string', 'max:50'],
            'observacion' => ['nullable', 'string', 'max:250'],
            'desde_caja' => ['boolean'],
        ], [
            'fecha.before_or_equal' => 'La fecha del pago no puede ser futura.',
            'referencia.required' => 'Indica el N° de operación o de cheque.',
            'monto.gt' => 'El monto debe ser mayor a cero.',
        ]);

        $pago = $servicio->registrarPago($compra, $datos, $request->user());
        $compra->refresh();

        $mensaje = (float) $compra->saldo <= 0
            ? "Pago de S/ {$pago->monto} registrado. La compra {$compra->documento} quedó pagada."
            : "Pago de S/ {$pago->monto} registrado. Saldo pendiente: S/ {$compra->saldo}.";

        return back()->with('success', $mensaje);
    }

    public function anularPago(Request $request, CompraPago $pago, CuentaPagarService $servicio): RedirectResponse
    {
        $this->verificar($request, $pago->compra);

        return back()->with('success', $servicio->anularPago($pago, $request->user()));
    }

    private function verificar(Request $request, Compra $compra): void
    {
        abort_if((int) $compra->sucursal_id !== (int) $request->user()->sucursal_id, 404);
        abort_unless($compra->esCredito(), 404);
    }
}