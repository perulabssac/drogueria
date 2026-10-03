<?php

namespace App\Http\Controllers;

use App\Models\Laboratorio;
use App\Models\Producto;
use App\Support\ProductoVenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductoController extends Controller
{
    public function index(Request $request): Response
    {
        $sucursalId = $request->user()->sucursal_id;

        $productos = Producto::query()
            ->with('laboratorio:id,nombre')
            // Stock (en unidad mínima) = suma de los lotes vigentes de la sucursal del usuario
            ->withSum(['lotes as stock' => fn ($q) => $q
                ->where('sucursal_id', $sucursalId)
                ->whereDate('fecha_vencimiento', '>=', now()->toDateString())], 'cantidad')
            ->buscar($request->string('buscar')->toString())
            ->when($request->filled('laboratorio_id'), fn ($q) => $q->where('laboratorio_id', $request->integer('laboratorio_id')))
            ->when($request->filled('categoria'), fn ($q) => $q->where('categoria', $request->string('categoria')->toString()))
            ->orderBy('nombre')
            ->paginate($request->integer('por_pagina', 15))
            ->withQueryString();

        return Inertia::render('Productos/Index', [
            'productos' => $productos,
            'filtros' => $request->only('buscar', 'laboratorio_id', 'categoria'),
            'laboratorios' => Laboratorio::orderBy('nombre')->get(['id', 'nombre']),
            'categorias' => $this->categorias(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Productos/Form', [
            'producto' => null,
            ...$this->opcionesFormulario(),
        ]);
    }

    public function edit(Producto $producto): Response
    {
        return Inertia::render('Productos/Form', [
            'producto' => $producto,
            'tieneLotes' => $producto->lotes()->exists(),
            ...$this->opcionesFormulario(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $producto = Producto::create($this->prepararDatos($this->validar($request)));

        return redirect('/productos')->with('success', "Producto {$producto->nombre} registrado.");
    }

    public function update(Request $request, Producto $producto): RedirectResponse
    {
        $datos = $this->prepararDatos($this->validar($request, $producto));

        // El stock se guarda en unidades mínimas: cambiar el factor alteraría las cantidades existentes
        $factorNuevo = $datos['fraccionable'] ? (int) $datos['unidades_por_presentacion'] : 1;
        if ($factorNuevo !== $producto->factor() && $producto->lotes()->exists()) {
            throw ValidationException::withMessages([
                'fraccionable' => 'Este producto ya tiene lotes registrados: no se puede cambiar el fraccionamiento ni las unidades por presentación.',
            ]);
        }

        $producto->update($datos);

        return redirect('/productos')->with('success', "Producto {$producto->nombre} actualizado.");
    }

    /** No se borra (tiene historial de ventas y kárdex): solo se desactiva. */
    public function destroy(Producto $producto): RedirectResponse
    {
        $producto->update(['activo' => false]);

        return back()->with('success', 'Producto desactivado.');
    }

    /**
     * Búsqueda rápida (JSON) para compras y ventas: devuelve los datos
     * de venta del producto y su stock en la sucursal del usuario.
     */
    public function buscar(Request $request): JsonResponse
    {
        $productos = ProductoVenta::consulta($request->user()->sucursal_id)
            ->buscar(trim($request->string('q')->toString()))
            ->orderBy('nombre')
            ->limit(15)
            ->get();

        return response()->json($productos->map(fn (Producto $p) => ProductoVenta::datos($p)));
    }

    /** Listas que necesita el formulario de producto. */
    private function opcionesFormulario(): array
    {
        return [
            'laboratorios' => Laboratorio::orderBy('nombre')->get(['id', 'nombre']),
            'categorias' => $this->categorias(),
            'afectaciones' => Producto::AFECTACIONES_IGV,
            'condiciones' => Producto::CONDICIONES_VENTA,
            'unidadesVenta' => collect(Producto::UNIDADES_VENTA)->map(fn ($u) => $u['nombre']),
            'unidadesFraccion' => Producto::UNIDADES_FRACCION,
        ];
    }

    private function categorias()
    {
        return Producto::query()->whereNotNull('categoria')->distinct()->orderBy('categoria')->pluck('categoria');
    }

    private function validar(Request $request, ?Producto $producto = null): array
    {
        $fraccionable = $request->boolean('fraccionable');

        return $request->validate([
            'codigo' => ['required', 'string', 'max:30', Rule::unique('productos')->ignore($producto)],
            'codigo_barras' => ['nullable', 'string', 'max:50'],
            'nombre' => ['required', 'string', 'max:255'],
            'principio_activo' => ['nullable', 'string', 'max:255'],
            'concentracion' => ['nullable', 'string', 'max:100'],
            'forma_farmaceutica' => ['nullable', 'string', 'max:100'],
            'presentacion' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', 'string', 'max:60'],
            'laboratorio_id' => ['nullable', 'integer', 'exists:laboratorios,id'],
            'laboratorio_nuevo' => ['nullable', 'string', 'max:255'],
            'registro_sanitario' => ['nullable', 'string', 'max:30'],
            'condicion_venta' => ['required', Rule::in(array_keys(Producto::CONDICIONES_VENTA))],
            'controlado' => ['boolean'],
            'cadena_frio' => ['boolean'],
            'tipo_afectacion_igv' => ['required', Rule::in(array_keys(Producto::AFECTACIONES_IGV))],
            'unidad_venta' => ['required', Rule::in(array_keys(Producto::UNIDADES_VENTA))],
            'precio_venta' => ['required', 'numeric', 'gt:0'],
            'fraccionable' => ['boolean'],
            'unidades_por_presentacion' => [$fraccionable ? 'required' : 'nullable', 'integer', $fraccionable ? 'min:2' : 'min:1'],
            'unidad_fraccion' => [$fraccionable ? 'required' : 'nullable', Rule::in(array_keys(Producto::UNIDADES_FRACCION))],
            'precio_fraccion' => [$fraccionable ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'costo' => ['nullable', 'numeric', 'min:0'],
            'stock_minimo' => ['nullable', 'integer', 'min:0'],
            'activo' => ['boolean'],
        ], [], [
            'codigo' => 'código',
            'precio_venta' => 'precio de venta',
            'unidades_por_presentacion' => 'unidades por presentación',
            'unidad_fraccion' => 'unidad de fracción',
            'precio_fraccion' => 'precio por unidad suelta',
        ]);
    }

    private function prepararDatos(array $datos): array
    {
        // Si escribieron un laboratorio nuevo, lo crea y lo asigna
        if (! empty($datos['laboratorio_nuevo'])) {
            $datos['laboratorio_id'] = Laboratorio::firstOrCreate([
                'nombre' => mb_strtoupper(trim($datos['laboratorio_nuevo'])),
            ])->id;
        }
        unset($datos['laboratorio_nuevo']);

        // El código SUNAT se deduce de la unidad de venta
        $datos['unidad_sunat'] = Producto::unidadSunat($datos['unidad_venta']);

        $datos['fraccionable'] = (bool) ($datos['fraccionable'] ?? false);
        if (! $datos['fraccionable']) {
            $datos['unidades_por_presentacion'] = 1;
            $datos['unidad_fraccion'] = null;
            $datos['precio_fraccion'] = null;
        }

        $datos['categoria'] = isset($datos['categoria']) ? mb_strtoupper(trim($datos['categoria'])) ?: null : null;
        $datos['costo'] ??= 0;
        $datos['stock_minimo'] ??= 0;

        return $datos;
    }
}