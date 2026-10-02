<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\ComprobantePago;
use App\Models\User;
use App\Services\CajaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Caja por usuario con cierre ciego: el cajero cuenta el efectivo sin ver cuánto debería haber.
 */
class CajaController extends Controller
{
    /** Pantalla principal: abrir caja, o ver la caja abierta (movimientos y cierre). */
    public function actual(Request $request, CajaService $cajas): Response
    {
        $usuario = $request->user();
        $caja = Caja::abiertaDe($usuario);
        $esAdmin = $usuario->tieneRol('admin');

        return Inertia::render('Caja/Actual', [
            'caja' => $caja,
            'resumen' => $caja ? $this->resumenVisible($cajas->resumen($caja), $esAdmin) : null,
            'movimientos' => $caja ? $caja->movimientos()->latest('id')->get() : [],
            'ultimaCerrada' => $caja ? null : Caja::query()->where('user_id', $usuario->id)->where('estado', 'cerrada')
                ->latest('id')->first(['id', 'cerrada_at', 'diferencia']),
            'mediosPago' => ComprobantePago::MEDIOS,
            'denominaciones' => CajaService::DENOMINACIONES,
            'esAdmin' => $esAdmin,
        ]);
    }

    public function abrir(Request $request, CajaService $cajas): RedirectResponse
    {
        $datos = $request->validate([
            'monto_inicial' => ['required', 'numeric', 'min:0', 'max:100000'],
        ], [
            'monto_inicial.required' => 'Indica con cuánto efectivo empiezas (puede ser 0).',
        ]);

        $cajas->abrir($request->user(), (float) $datos['monto_inicial']);

        return redirect('/caja')->with('success', 'Caja abierta. ¡Buen turno!');
    }

    public function movimiento(Request $request, CajaService $cajas): RedirectResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', 'in:ingreso,egreso'],
            'monto' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'concepto' => ['required', 'string', 'max:150'],
            'medio' => ['required', Rule::in(array_keys(ComprobantePago::MEDIOS))],
        ], [
            'concepto.required' => 'Indica el motivo (ej. "Pago delivery", "Compra de útiles").',
        ]);

        $usuario = $request->user();
        $caja = $cajas->requerirAbierta($usuario, 'monto');
        $cajas->registrarMovimiento($caja, $usuario, $datos['tipo'], (float) $datos['monto'], $datos['concepto'], $datos['medio']);

        return back()->with('success', ($datos['tipo'] === 'ingreso' ? 'Ingreso' : 'Egreso').' registrado.');
    }

    public function cerrar(Request $request, CajaService $cajas): RedirectResponse
    {
        $datos = $request->validate([
            'efectivo_contado' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'conteo' => ['nullable', 'array'],
            'conteo.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ], [
            'efectivo_contado.required' => 'Cuenta el efectivo e ingrésalo para cerrar la caja.',
        ]);

        $usuario = $request->user();
        $caja = $cajas->requerirAbierta($usuario, 'efectivo_contado');
        $caja = $cajas->cerrar($caja, $usuario, (float) $datos['efectivo_contado'], $datos['conteo'] ?? [], $datos['observaciones'] ?? null);

        $diferencia = (float) $caja->diferencia;
        $mensaje = match (true) {
            abs($diferencia) < 0.01 => 'Caja cerrada. ¡Cuadró exacto!',
            $diferencia > 0 => 'Caja cerrada con un sobrante de S/ '.number_format($diferencia, 2).'.',
            default => 'Caja cerrada con un faltante de S/ '.number_format(abs($diferencia), 2).'.',
        };

        return redirect("/cajas/{$caja->id}")->with(abs($diferencia) < 0.01 ? 'success' : 'error', $mensaje);
    }

    /** Reporte de una caja: el cajero ve las suyas cerradas; el admin ve todas. */
    public function show(Request $request, Caja $caja, CajaService $cajas): Response|RedirectResponse
    {
        $usuario = $request->user();
        $esAdmin = $usuario->tieneRol('admin');
        abort_unless($esAdmin || (int) $caja->user_id === (int) $usuario->id, 403);

        // Cierre ciego: el cajero no ve su caja abierta en detalle hasta cerrarla
        if ($caja->estaAbierta() && ! $esAdmin) {
            return redirect('/caja');
        }

        return Inertia::render('Caja/Show', $this->datosReporte($caja, $cajas));
    }

    public function imprimir(Request $request, Caja $caja, CajaService $cajas): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->tieneRol('admin') || (int) $caja->user_id === (int) $usuario->id, 403);
        abort_if($caja->estaAbierta() && ! $usuario->tieneRol('admin'), 403, 'Cierra la caja para imprimir su reporte.');

        return Inertia::render('Caja/Imprimir', [
            ...$this->datosReporte($caja, $cajas),
            'autoImprimir' => $request->boolean('auto'),
        ]);
    }

    /** Historial de cajas (solo administrador). */
    public function index(Request $request): Response
    {
        $cajas = Caja::query()
            ->with('usuario:id,name')
            ->where('sucursal_id', $request->user()->sucursal_id)
            ->when($request->filled('usuario'), fn ($q) => $q->where('user_id', $request->integer('usuario')))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')->toString()))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('abierta_at', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('abierta_at', '<=', $request->date('hasta')))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Caja/Index', [
            'cajas' => $cajas,
            'filtros' => $request->only('usuario', 'estado', 'desde', 'hasta'),
            'usuarios' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function datosReporte(Caja $caja, CajaService $cajas): array
    {
        $caja->load('usuario:id,name', 'sucursal:id,nombre');

        // Ventas y cobranzas cobradas en esta caja (una fila por comprobante y medio)
        $pagos = ComprobantePago::query()
            ->with('comprobante:id,tipo_comprobante,serie,correlativo,estado,cliente_id,total', 'comprobante.cliente:id,razon_social')
            ->where('caja_id', $caja->id)
            ->orderBy('id')
            ->get();

        return [
            'caja' => $caja,
            // Caja cerrada: se muestra la foto guardada al cerrar; abierta (admin): se calcula al momento
            'resumen' => $caja->estaAbierta() ? $cajas->resumen($caja) : $caja->resumen,
            'movimientos' => $caja->movimientos()->with('usuario:id,name')->get(),
            'pagos' => $pagos,
        ];
    }

    /**
     * Cierre ciego: mientras la caja está abierta, el cajero no ve el efectivo esperado
     * (así cuenta de verdad al cerrar). Tampoco las ventas ni cobranzas en efectivo,
     * porque con ellas podría calcularlo. El admin sí lo ve todo.
     */
    private function resumenVisible(array $resumen, bool $esAdmin): array
    {
        if ($esAdmin) {
            return $resumen;
        }

        $resumen['efectivo_esperado'] = null;
        $resumen['total_ventas'] = null;
        $resumen['total_cobranzas'] = null;
        $resumen['medios'] = array_map(
            fn ($m) => $m['medio'] === 'efectivo' ? [...$m, 'ventas' => null, 'cobranzas' => null, 'neto' => null] : $m,
            $resumen['medios'],
        );

        return $resumen;
    }
}