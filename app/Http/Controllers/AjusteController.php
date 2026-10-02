<?php

namespace App\Http\Controllers;

use App\Models\Ajuste;
use App\Models\Empresa;
use App\Models\Lote;
use App\Models\Producto;
use App\Services\AjusteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ajustes de inventario. Los registran almacén y administrador; el contador solo los consulta.
 * No se editan ni se anulan: un error se corrige con otro ajuste en sentido contrario.
 */
class AjusteController extends Controller
{
    public function index(Request $request): Response
    {
        $tipo = $request->input('tipo');
        $buscar = trim($request->string('buscar')->toString());

        $ajustes = Ajuste::query()
            ->with('usuario:id,name')
            ->withCount('items')
            ->where('sucursal_id', $request->user()->sucursal_id)
            ->when(in_array($tipo, ['entrada', 'salida'], true), fn ($q) => $q->where('tipo', $tipo))
            ->when($request->filled('motivo'), fn ($q) => $q->where('motivo', $request->string('motivo')->toString()))
            ->when($buscar, fn ($q) => $q->where(fn ($w) => $w
                ->where('observacion', 'like', "%{$buscar}%")
                ->orWhere('id', (int) preg_replace('/\D/', '', $buscar) ?: 0)
                ->orWhereHas('items.producto', fn ($p) => $p->buscar($buscar))))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Ajustes/Index', [
            'ajustes' => $ajustes,
            'filtros' => $request->only('tipo', 'motivo', 'buscar'),
            'motivos' => Ajuste::MOTIVOS,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Ajustes/Create', ['motivos' => Ajuste::MOTIVOS]);
    }

    public function store(Request $request, AjusteService $servicio): RedirectResponse
    {
        $tipo = $request->input('tipo');
        $esSalida = $tipo === 'salida';

        $datos = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Ajuste::TIPOS))],
            'motivo' => ['required', Rule::in(array_keys(Ajuste::MOTIVOS[$tipo] ?? []))],
            'observacion' => ['required', 'string', 'min:5', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.producto_id' => ['required', 'exists:productos,id'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.por_fraccion' => ['boolean'],
            'items.*.lote_id' => [$esSalida ? 'required' : 'nullable', 'integer'],
            'items.*.numero_lote' => [$esSalida ? 'nullable' : 'required', 'string', 'max:50'],
            'items.*.fecha_vencimiento' => [$esSalida ? 'nullable' : 'required', 'date'],
            'items.*.costo_unitario' => ['nullable', 'numeric', 'min:0'],
        ], [
            'motivo.in' => 'Elige el motivo del ajuste.',
            'observacion.required' => 'Explica qué pasó (ej. "Se cayó la caja al descargar").',
            'observacion.min' => 'Explica un poco más qué pasó.',
            'items.required' => 'Agrega al menos un producto.',
            'items.*.lote_id.required' => 'Elige el lote.',
            'items.*.numero_lote.required' => 'Escribe el lote.',
            'items.*.fecha_vencimiento.required' => 'Indica el vencimiento.',
        ]);

        $ajuste = $servicio->registrar($datos, $request->user());

        return redirect("/ajustes/{$ajuste->id}")->with('success', "Ajuste {$ajuste->numero} registrado.");
    }

    public function show(Request $request, Ajuste $ajuste): Response
    {
        abort_if((int) $ajuste->sucursal_id !== (int) $request->user()->sucursal_id, 404);

        $ajuste->load([
            'usuario:id,name',
            'sucursal:id,nombre',
            'items.producto:id,codigo,nombre,concentracion,presentacion,unidad_venta,fraccionable,unidades_por_presentacion,unidad_fraccion',
        ]);

        return Inertia::render('Ajustes/Show', [
            'ajuste' => $ajuste,
            'empresa' => Empresa::actual()?->only('razon_social', 'ruc', 'direccion'),
            'puedeRegistrar' => $request->user()->tieneRol('almacen'),
        ]);
    }

    /** Lotes con stock de un producto (incluye vencidos), para elegir de cuál sacar o a cuál sumar. */
    public function lotes(Request $request): JsonResponse
    {
        $producto = Producto::findOrFail($request->integer('producto'));

        return response()->json(
            Lote::query()
                ->where('producto_id', $producto->id)
                ->where('sucursal_id', $request->user()->sucursal_id)
                ->where('cantidad', '>', 0)
                ->orderBy('fecha_vencimiento')
                ->get(['id', 'numero_lote', 'fecha_vencimiento', 'cantidad', 'costo_unitario'])
                ->map(fn (Lote $l) => [
                    'id' => $l->id,
                    'numero_lote' => $l->numero_lote,
                    'fecha_vencimiento' => $l->fecha_vencimiento->toDateString(),
                    'cantidad' => (float) $l->cantidad,
                    'costo_unitario' => (float) $l->costo_unitario,
                    'vencido' => $l->fecha_vencimiento->isPast() && ! $l->fecha_vencimiento->isToday(),
                ])
        );
    }
}