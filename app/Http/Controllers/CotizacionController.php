<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Empresa;
use App\Models\User;
use App\Services\CotizacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cotizaciones (proformas). Las hace el vendedor; el contador solo las consulta.
 */
class CotizacionController extends Controller
{
    /** Texto que se propone en una cotización nueva (se puede cambiar en cada una). */
    public const CONDICIONES_DEFECTO = 'Precios en soles, incluyen IGV. Sujeto a disponibilidad de stock al momento de la compra.';

    public function index(Request $request): Response
    {
        $buscar = trim($request->string('buscar')->toString());
        // "COT-000012", "cot 12" o "12" → cotización 12
        $numero = preg_match('/^(?:COT\s*-?\s*)?0*(\d{1,8})$/i', $buscar, $m) ? (int) $m[1] : null;
        $estado = $request->string('estado')->toString();

        $cotizaciones = Cotizacion::query()
            ->with('cliente:id,razon_social,numero_documento', 'comprobante:id,tipo_comprobante,serie,correlativo')
            ->withCount('items')
            ->where('sucursal_id', $request->user()->sucursal_id)
            ->when($estado === 'pendiente', fn ($q) => $q->where('estado', 'pendiente')->whereDate('fecha_vencimiento', '>=', today()))
            ->when($estado === 'vencida', fn ($q) => $q->where('estado', 'pendiente')->whereDate('fecha_vencimiento', '<', today()))
            ->when(in_array($estado, ['vendida', 'anulada'], true), fn ($q) => $q->where('estado', $estado))
            ->when($buscar, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('cliente', fn ($c) => $c
                    ->where('razon_social', 'like', "%{$buscar}%")
                    ->orWhere('numero_documento', 'like', "{$buscar}%"))
                ->when($numero, fn ($o) => $o->orWhere('id', $numero))))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Cotizaciones/Index', [
            'cotizaciones' => $cotizaciones,
            'filtros' => $request->only('estado', 'buscar'),
            'estados' => Cotizacion::ESTADOS,
        ]);
    }

    /** Nueva cotización. Con ?desde=ID copia otra (renovar una vencida o duplicar) con los precios de hoy. */
    public function create(Request $request, CotizacionService $servicio): Response
    {
        $base = $request->filled('desde') ? $this->buscarEnSucursal($request, $request->integer('desde')) : null;

        return $this->formulario($request, null, [
            'cliente' => $base?->cliente?->only(CotizacionService::CAMPOS_CLIENTE),
            'validez_dias' => $base->validez_dias ?? 7,
            'forma_pago' => $base->forma_pago ?? 'contado',
            'condiciones' => $base->condiciones ?? self::CONDICIONES_DEFECTO,
            'observaciones' => $base->observaciones ?? '',
            'vendedor_id' => $request->user()->id,
            'items' => $base ? $servicio->lineasParaFormulario($base, $request->user()->sucursal_id, true) : [],
        ], $base);
    }

    public function store(Request $request, CotizacionService $servicio): RedirectResponse
    {
        $cotizacion = $servicio->guardar($this->validar($request), $request->user());

        return redirect("/cotizaciones/{$cotizacion->id}")->with('success', "Cotización {$cotizacion->numero} registrada.");
    }

    public function edit(Request $request, Cotizacion $cotizacion, CotizacionService $servicio): Response|RedirectResponse
    {
        $this->verificarSucursal($request, $cotizacion);
        if (! $cotizacion->vigente()) {
            return redirect("/cotizaciones/{$cotizacion->id}")->with('error', 'Solo se puede editar una cotización pendiente y vigente.');
        }

        return $this->formulario($request, $cotizacion, [
            'cliente' => $cotizacion->cliente->only(CotizacionService::CAMPOS_CLIENTE),
            'validez_dias' => $cotizacion->validez_dias,
            'forma_pago' => $cotizacion->forma_pago,
            'condiciones' => $cotizacion->condiciones ?? '',
            'observaciones' => $cotizacion->observaciones ?? '',
            'vendedor_id' => $cotizacion->vendedor_id,
            'items' => $servicio->lineasParaFormulario($cotizacion, $request->user()->sucursal_id),
        ]);
    }

    public function update(Request $request, Cotizacion $cotizacion, CotizacionService $servicio): RedirectResponse
    {
        $this->verificarSucursal($request, $cotizacion);
        $servicio->guardar($this->validar($request), $request->user(), $cotizacion);

        return redirect("/cotizaciones/{$cotizacion->id}")->with('success', "Cotización {$cotizacion->numero} actualizada.");
    }

    public function show(Request $request, Cotizacion $cotizacion): Response
    {
        $this->verificarSucursal($request, $cotizacion);
        $cotizacion->load('items', 'cliente', 'vendedor:id,name', 'usuario:id,name', 'comprobante:id,tipo_comprobante,serie,correlativo,estado');

        return Inertia::render('Cotizaciones/Show', [
            'cotizacion' => $cotizacion,
            'estados' => Cotizacion::ESTADOS,
            'puedeGestionar' => $request->user()->tieneRol('vendedor'),
        ]);
    }

    public function imprimir(Request $request, Cotizacion $cotizacion): Response
    {
        $this->verificarSucursal($request, $cotizacion);
        $cotizacion->load('items', 'cliente', 'vendedor:id,name');

        return Inertia::render('Cotizaciones/Imprimir', [
            'cotizacion' => $cotizacion,
            'empresa' => Empresa::actual()->only('ruc', 'razon_social', 'nombre_comercial', 'direccion', 'telefono', 'email'),
        ]);
    }

    public function anular(Request $request, Cotizacion $cotizacion, CotizacionService $servicio): RedirectResponse
    {
        $this->verificarSucursal($request, $cotizacion);
        $servicio->anular($cotizacion);

        return back()->with('success', "Cotización {$cotizacion->numero} anulada.");
    }

    // ================= Apoyo =================

    private function formulario(Request $request, ?Cotizacion $cotizacion, array $inicial, ?Cotizacion $base = null): Response
    {
        return Inertia::render('Cotizaciones/Form', [
            'cotizacion' => $cotizacion?->only('id', 'numero', 'fecha'),
            'base' => $base?->only('id', 'numero'),
            'inicial' => $inicial,
            'clienteVarios' => Cliente::clientesVarios()->only(CotizacionService::CAMPOS_CLIENTE),
            'vendedores' => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'vendedor_id' => ['nullable', 'integer', 'exists:users,id'],
            'validez_dias' => ['required', 'integer', 'min:1', 'max:90'],
            'forma_pago' => ['required', 'in:contado,credito'],
            'condiciones' => ['nullable', 'string', 'max:500'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.producto_id' => ['required', 'integer', 'exists:productos,id'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.por_fraccion' => ['boolean'],
            'items.*.bonificacion' => ['boolean'],
            'items.*.precio_unitario' => ['nullable', 'required_unless:items.*.bonificacion,true', 'numeric', 'gt:0'],
        ], [
            'cliente_id.required' => 'Elige el cliente.',
            'validez_dias.max' => 'La validez máxima es de 90 días.',
            'items.required' => 'Agrega al menos un producto.',
            'items.*.precio_unitario.required_unless' => 'Falta el precio.',
        ]);
    }

    private function buscarEnSucursal(Request $request, int $id): Cotizacion
    {
        return Cotizacion::query()->where('sucursal_id', $request->user()->sucursal_id)->with('cliente')->findOrFail($id);
    }

    private function verificarSucursal(Request $request, Cotizacion $cotizacion): void
    {
        abort_if((int) $cotizacion->sucursal_id !== (int) $request->user()->sucursal_id, 404);
    }
}