<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\Comprobante;
use App\Models\ComprobantePago;
use App\Services\CobranzaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cuentas por cobrar: ventas al crédito con saldo, sus cuotas y el registro de cobros.
 */
class CobranzaController extends Controller
{
    /** Suma de las cuotas ya vencidas a la fecha (para saber cuánto está atrasado). */
    private const CUOTAS_VENCIDAS_SQL = '(select coalesce(sum(cc.monto), 0) from comprobante_cuotas cc
        where cc.comprobante_id = comprobantes.id and cc.fecha_vencimiento < ?)';

    public function index(Request $request): Response
    {
        $hoy = today()->toDateString();
        $estado = $request->input('estado', 'pendientes');
        $vencidas = self::CUOTAS_VENCIDAS_SQL;

        // Ventas al crédito válidas de la sucursal
        $base = fn () => Comprobante::query()
            ->where('sucursal_id', $request->user()->sucursal_id)
            ->where('forma_pago', 'credito')
            ->validos();

        $cuentas = $base()
            ->with(['cliente:id,razon_social,numero_documento,telefono', 'cuotas'])
            ->when($estado === 'pendientes', fn ($q) => $q->where('saldo', '>', 0))
            // Vencida: lo que ya debió pagarse supera lo pagado
            ->when($estado === 'vencidas', fn ($q) => $q->where('saldo', '>', 0)
                ->whereRaw("{$vencidas} > comprobantes.total - comprobantes.saldo + 0.009", [$hoy]))
            ->when($estado === 'canceladas', fn ($q) => $q->where('saldo', '<=', 0))
            ->when($request->filled('buscar'), function ($q) use ($request) {
                $b = trim($request->string('buscar')->toString());
                $q->where(function ($w) use ($b) {
                    if (preg_match('/^([A-Z0-9]{4})-(\d+)$/i', $b, $m)) {
                        $w->where('serie', strtoupper($m[1]))->where('correlativo', (int) $m[2]);
                    } else {
                        $w->whereHas('cliente', fn ($c) => $c->where('razon_social', 'like', "%{$b}%")->orWhere('numero_documento', 'like', "{$b}%"));
                    }
                });
            })
            // Pendientes: primero las que vencen antes; canceladas y todas: las más recientes
            ->when(in_array($estado, ['pendientes', 'vencidas'], true),
                fn ($q) => $q->orderBy('fecha_vencimiento')->orderBy('id'),
                fn ($q) => $q->latest('id'))
            ->paginate(20)
            ->withQueryString()
            ->through(function (Comprobante $c) {
                $cuotas = collect(CobranzaService::cuotas($c));

                return [
                    'id' => $c->id,
                    'numero' => $c->numero,
                    'tipo_nombre' => $c->tipo_nombre,
                    'fecha_emision' => $c->fecha_emision->toDateString(),
                    'cliente' => $c->cliente,
                    'total' => (float) $c->total,
                    'saldo' => (float) $c->saldo,
                    'vencido' => round($cuotas->where('estado', 'vencida')->sum('pendiente'), 2),
                    'proxima' => $cuotas->first(fn ($q) => $q['pendiente'] > 0),
                    'cuotas' => $cuotas->count(),
                ];
            });

        // Resumen de toda la cartera pendiente (sin filtros)
        $r = $base()->where('saldo', '>', 0)
            ->selectRaw("count(*) as cuentas, count(distinct cliente_id) as clientes, coalesce(sum(saldo), 0) as por_cobrar,
                coalesce(sum(greatest(0, least(saldo, {$vencidas} - (total - saldo)))), 0) as vencido", [$hoy])
            ->toBase()
            ->first();

        return Inertia::render('Cobranzas/Index', [
            'cuentas' => $cuentas,
            'filtros' => ['estado' => $estado, 'buscar' => $request->input('buscar')],
            'resumen' => [
                'cuentas' => (int) $r->cuentas,
                'clientes' => (int) $r->clientes,
                'por_cobrar' => round((float) $r->por_cobrar, 2),
                'vencido' => round((float) $r->vencido, 2),
            ],
        ]);
    }

    public function show(Request $request, Comprobante $comprobante): Response|RedirectResponse
    {
        $usuario = $request->user();

        if (! $comprobante->esCredito()) {
            return redirect("/comprobantes/{$comprobante->id}")->with('error', 'Esta venta fue al contado: no tiene saldo por cobrar.');
        }
        abort_if((int) $comprobante->sucursal_id !== (int) $usuario->sucursal_id && ! $usuario->tieneRol('admin'), 403);

        CobranzaService::recalcularSaldo($comprobante);
        $comprobante->load(['cliente', 'cuotas', 'vendedor:id,name']);
        $cuotas = CobranzaService::cuotas($comprobante);

        // Otras ventas del mismo cliente que aún debe
        $otras = Comprobante::query()
            ->where('cliente_id', $comprobante->cliente_id)
            ->where('forma_pago', 'credito')
            ->validos()
            ->where('saldo', '>', 0)
            ->whereKeyNot($comprobante->id)
            ->orderBy('fecha_vencimiento')
            ->get(['id', 'tipo_comprobante', 'serie', 'correlativo', 'fecha_emision', 'fecha_vencimiento', 'total', 'saldo']);

        return Inertia::render('Cobranzas/Show', [
            'comprobante' => $comprobante,
            'cuotas' => $cuotas,
            'pagos' => ComprobantePago::query()
                ->with('usuario:id,name')
                ->where('comprobante_id', $comprobante->id)
                ->orderBy('id')
                ->get(),
            'notas' => $comprobante->notas()->get(['id', 'tipo_comprobante', 'serie', 'correlativo', 'fecha_emision', 'total', 'estado']),
            'otras' => $otras,
            'impedimento' => CobranzaService::impedimento($comprobante, $usuario),
            'cajaAbierta' => (bool) Caja::abiertaDe($usuario),
            'mediosPago' => ComprobantePago::MEDIOS,
            // Monto sugerido: lo pendiente de la próxima cuota (o todo el saldo)
            'sugerido' => collect($cuotas)->first(fn ($q) => $q['pendiente'] > 0)['pendiente'] ?? (float) $comprobante->saldo,
        ]);
    }

    public function store(Request $request, Comprobante $comprobante, CobranzaService $cobranzas): RedirectResponse
    {
        $datos = $request->validate([
            'monto' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'medio' => ['required', Rule::in(array_keys(ComprobantePago::MEDIOS))],
            'recibido' => ['nullable', 'numeric', 'gt:0'],
            'referencia' => ['nullable', 'string', 'max:50'],
        ], [
            'monto.required' => 'Indica cuánto se está cobrando.',
            'monto.gt' => 'El monto debe ser mayor a cero.',
        ]);

        $pago = $cobranzas->cobrar($comprobante, $datos, $request->user());
        $saldo = (float) $comprobante->fresh()->saldo;

        $mensaje = 'Cobro de S/ '.number_format((float) $pago->monto, 2).' registrado.';
        $mensaje .= $saldo <= 0 ? ' La venta quedó cancelada.' : ' Saldo pendiente: S/ '.number_format($saldo, 2).'.';
        if ($pago->vuelto > 0) {
            $mensaje .= ' Vuelto: S/ '.number_format($pago->vuelto, 2).'.';
        }

        return back()->with('success', $mensaje);
    }
}