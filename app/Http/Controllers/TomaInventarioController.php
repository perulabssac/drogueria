<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Laboratorio;
use App\Models\Producto;
use App\Models\TomaInventario;
use App\Models\TomaItem;
use App\Services\TomaInventarioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Toma de inventario física. Almacén la abre y cuenta; solo el administrador la aprueba
 * (porque al aprobar se ajusta el stock). El contador solo la consulta.
 */
class TomaInventarioController extends Controller
{
    public function index(Request $request): Response
    {
        $sucursalId = $request->user()->sucursal_id;

        $tomas = TomaInventario::query()
            ->with('usuario:id,name', 'aprobador:id,name')
            ->withCount([
                'items',
                'items as contados_count' => fn ($q) => $q->whereNotNull('contado'),
                'items as diferencias_count' => fn ($q) => $q->whereNotNull('contado')->whereColumn('contado', '!=', 'stock_sistema'),
            ])
            ->where('sucursal_id', $sucursalId)
            ->latest('id')
            ->paginate(20);

        return Inertia::render('Tomas/Index', [
            'tomas' => $tomas,
            'laboratorios' => Laboratorio::orderBy('nombre')->get(['id', 'nombre']),
            'categorias' => Producto::query()->whereNotNull('categoria')->distinct()->orderBy('categoria')->pluck('categoria'),
            'hayAbierta' => TomaInventario::where('sucursal_id', $sucursalId)->where('estado', 'en_conteo')->exists(),
        ]);
    }

    public function store(Request $request, TomaInventarioService $servicio): RedirectResponse
    {
        $datos = $request->validate([
            'alcance' => ['required', Rule::in(['todo', 'laboratorio', 'categoria'])],
            'alcance_valor' => ['nullable', 'required_unless:alcance,todo', 'string', 'max:100'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ], [
            'alcance_valor.required_unless' => 'Elige el laboratorio o la categoría a contar.',
        ]);

        $toma = $servicio->abrir($datos, $request->user());

        return redirect("/tomas/{$toma->id}")->with('success', "Toma {$toma->numero} abierta: ya puedes contar.");
    }

    public function show(Request $request, TomaInventario $toma): Response
    {
        $this->verificarSucursal($request, $toma);

        return Inertia::render('Tomas/Show', [
            'toma' => $this->datosToma($toma),
            'items' => $this->datosItems($toma),
            'puedeContar' => $toma->enConteo() && $request->user()->tieneRol('almacen'),
            'puedeAprobar' => $toma->enConteo() && $request->user()->tieneRol('admin'),
        ]);
    }

    /** Hoja impresa para contar a mano (sin el stock del sistema). */
    public function hoja(Request $request, TomaInventario $toma): Response
    {
        $this->verificarSucursal($request, $toma);

        return Inertia::render('Tomas/Hoja', [
            'toma' => $this->datosToma($toma),
            'items' => $this->datosItems($toma),
            'empresa' => Empresa::actual()?->only('razon_social', 'ruc'),
        ]);
    }

    public function conteo(Request $request, TomaInventario $toma, TomaInventarioService $servicio): RedirectResponse
    {
        $this->verificarSucursal($request, $toma);

        $datos = $request->validate([
            'conteos' => ['required', 'array'],
            'conteos.*.id' => ['required', 'integer'],
            'conteos.*.contado' => ['nullable', 'numeric', 'min:0'],
        ]);

        $servicio->guardarConteo($toma, $datos['conteos']);

        return back()->with('success', 'Conteo guardado.');
    }

    public function agregarLote(Request $request, TomaInventario $toma, TomaInventarioService $servicio): RedirectResponse
    {
        $this->verificarSucursal($request, $toma);

        $datos = $request->validate([
            'producto_id' => ['required', 'exists:productos,id'],
            'numero_lote' => ['required', 'string', 'max:50'],
            'fecha_vencimiento' => ['required', 'date'],
            'contado' => ['required', 'numeric', 'gt:0'],
            'costo_unitario' => ['nullable', 'numeric', 'min:0'],
        ]);

        $servicio->agregarLote($toma, $datos);

        return back()->with('success', 'Lote agregado a la toma.');
    }

    public function aprobar(Request $request, TomaInventario $toma, TomaInventarioService $servicio): RedirectResponse
    {
        $this->verificarSucursal($request, $toma);
        $servicio->aprobar($toma, $request->user());

        return back()->with('success', "Toma {$toma->numero} aprobada: el stock quedó igual al conteo.");
    }

    public function anular(Request $request, TomaInventario $toma, TomaInventarioService $servicio): RedirectResponse
    {
        $this->verificarSucursal($request, $toma);
        $servicio->anular($toma);

        return redirect('/tomas')->with('success', "Toma {$toma->numero} anulada (no se cambió el stock).");
    }

    // ================= Apoyo =================

    private function verificarSucursal(Request $request, TomaInventario $toma): void
    {
        abort_if((int) $toma->sucursal_id !== (int) $request->user()->sucursal_id, 404);
    }

    private function datosToma(TomaInventario $toma): array
    {
        $toma->load('usuario:id,name', 'aprobador:id,name', 'ajusteSalida:id,valor', 'ajusteEntrada:id,valor');

        return [
            ...$toma->only(['id', 'numero', 'alcance_texto', 'estado', 'observacion', 'created_at', 'aprobada_at']),
            'usuario' => $toma->usuario?->name,
            'aprobador' => $toma->aprobador?->name,
            'ajuste_salida' => $toma->ajusteSalida?->only('id', 'numero', 'valor'),
            'ajuste_entrada' => $toma->ajusteEntrada?->only('id', 'numero', 'valor'),
        ];
    }

    private function datosItems(TomaInventario $toma): array
    {
        return $toma->items()
            ->with('producto:id,codigo,nombre,concentracion,presentacion,laboratorio_id,unidad_venta,fraccionable,unidades_por_presentacion,unidad_fraccion', 'producto.laboratorio:id,nombre')
            ->get()
            ->sortBy(fn (TomaItem $i) => [$i->producto->nombre, $i->fecha_vencimiento->toDateString()])
            ->map(fn (TomaItem $i) => [
                'id' => $i->id,
                'numero_lote' => $i->numero_lote,
                'fecha_vencimiento' => $i->fecha_vencimiento->toDateString(),
                'stock_sistema' => $i->stock_sistema,
                'contado' => $i->contado,
                'costo_unitario' => $i->costo_unitario,
                'nuevo' => $i->lote_id === null || $i->stock_sistema == 0,
                'producto' => [
                    ...$i->producto->only(['id', 'codigo', 'unidad_venta', 'fraccionable', 'unidades_por_presentacion', 'unidad_fraccion']),
                    'descripcion' => $i->producto->descripcionCompleta(),
                    'laboratorio' => $i->producto->laboratorio?->nombre,
                ],
            ])
            ->values()
            ->all();
    }
}