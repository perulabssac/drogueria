<?php

namespace App\Http\Controllers;

use App\Models\Compra;
use App\Models\Producto;
use App\Services\InventarioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CompraController extends Controller
{
    public const TASA_IGV = 0.18;

    public function index(Request $request): Response
    {
        $compras = Compra::query()
            ->with('proveedor:id,ruc,razon_social')
            ->withCount('items')
            ->where('sucursal_id', $request->user()->sucursal_id)
            ->when($request->filled('buscar'), function ($q) use ($request) {
                $b = $request->string('buscar')->toString();
                $q->where(fn ($w) => $w
                    ->where('numero', 'like', "%{$b}%")
                    ->orWhereHas('proveedor', fn ($p) => $p->where('razon_social', 'like', "%{$b}%")->orWhere('ruc', 'like', "{$b}%")));
            })
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha_emision', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha_emision', '<=', $request->date('hasta')))
            ->latest('fecha_emision')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Compras/Index', [
            'compras' => $compras,
            'filtros' => $request->only('buscar', 'desde', 'hasta'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Compras/Create', [
            'tiposDocumento' => Compra::TIPOS_DOCUMENTO,
        ]);
    }

    public function store(Request $request, InventarioService $inventario): RedirectResponse
    {
        $datos = $request->validate([
            'proveedor_id' => ['required', 'exists:proveedores,id'],
            'tipo_documento' => ['required', Rule::in(array_keys(Compra::TIPOS_DOCUMENTO))],
            'serie' => ['required', 'string', 'max:4'],
            'numero' => ['required', 'digits_between:1,10'],
            'fecha_emision' => ['required', 'date', 'before_or_equal:today'],
            'forma_pago' => ['required', 'in:contado,credito'],
            'fecha_vencimiento' => ['nullable', 'required_if:forma_pago,credito', 'date', 'after_or_equal:fecha_emision'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'exists:productos,id'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.numero_lote' => ['required', 'string', 'max:50'],
            'items.*.fecha_vencimiento' => ['required', 'date', 'after:today'],
            'items.*.bonificacion' => ['boolean'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'min:0'],
        ], [
            'items.required' => 'Agrega al menos un producto.',
            'items.*.numero_lote.required' => 'Falta el lote.',
            'items.*.fecha_vencimiento.required' => 'Falta el vencimiento.',
            'items.*.fecha_vencimiento.after' => 'Producto vencido.',
            'fecha_vencimiento.required_if' => 'Indica la fecha de pago del crédito.',
        ]);

        $datos['serie'] = mb_strtoupper(trim($datos['serie']));
        $datos['numero'] = ltrim($datos['numero'], '0') ?: '0';

        $duplicada = Compra::query()
            ->where('proveedor_id', $datos['proveedor_id'])
            ->where('tipo_documento', $datos['tipo_documento'])
            ->where('serie', $datos['serie'])
            ->where('numero', $datos['numero'])
            ->exists();
        if ($duplicada) {
            throw ValidationException::withMessages(['numero' => 'Este documento del proveedor ya fue registrado.']);
        }

        $usuario = $request->user();

        $compra = DB::transaction(function () use ($datos, $usuario, $inventario) {
            $compra = Compra::create([
                ...collect($datos)->except('items')->all(),
                'sucursal_id' => $usuario->sucursal_id,
                'user_id' => $usuario->id,
                'fecha_vencimiento' => $datos['forma_pago'] === 'credito' ? $datos['fecha_vencimiento'] : null,
            ]);

            foreach ($datos['items'] as $i => $linea) {
                $producto = Producto::findOrFail($linea['producto_id']);
                $bonificacion = (bool) ($linea['bonificacion'] ?? false);
                $cantidad = round((float) $linea['cantidad'], 2);
                $precio = $bonificacion ? 0 : round((float) ($linea['precio_unitario'] ?? 0), 3);

                if (! $bonificacion && $precio <= 0) {
                    throw ValidationException::withMessages(["items.{$i}.precio_unitario" => 'Falta el precio.']);
                }

                // Los precios de la factura incluyen IGV: separamos la base imponible
                $total = round($cantidad * $precio, 2);
                $gravado = $producto->tipo_afectacion_igv === '10';
                $valor = $gravado ? round($total / (1 + self::TASA_IGV), 2) : $total;
                $costoSinIgv = $bonificacion ? 0 : round($valor / $cantidad, 4); // por presentación

                $item = $compra->items()->create([
                    'producto_id' => $producto->id,
                    'numero_lote' => mb_strtoupper(trim($linea['numero_lote'])),
                    'fecha_vencimiento' => $linea['fecha_vencimiento'],
                    'cantidad' => $cantidad,
                    'bonificacion' => $bonificacion,
                    'precio_unitario' => $precio,
                    'tipo_afectacion_igv' => $producto->tipo_afectacion_igv,
                    'valor' => $valor,
                    'igv' => round($total - $valor, 2),
                    'total' => $total,
                ]);

                // Ingreso al almacén: la cantidad se guarda en unidad mínima
                $lote = $inventario->ingresarLote([
                    'producto_id' => $producto->id,
                    'sucursal_id' => $compra->sucursal_id,
                    'numero_lote' => $item->numero_lote,
                    'fecha_vencimiento' => $item->fecha_vencimiento->toDateString(),
                    'cantidad' => $cantidad * $producto->factor(),
                    'costo_unitario' => $costoSinIgv,
                ], $usuario->id, $bonificacion ? 'bonificacion' : 'compra', $compra);

                $item->update(['lote_id' => $lote->id]);

                // El último costo de compra queda como costo del producto
                if (! $bonificacion) {
                    $producto->update(['costo' => $costoSinIgv]);
                }
            }

            $items = $compra->items()->get();
            $compra->update([
                'op_gravadas' => $items->where('tipo_afectacion_igv', '10')->sum('valor'),
                'op_exoneradas' => $items->where('tipo_afectacion_igv', '!=', '10')->sum('valor'),
                'igv' => $items->sum('igv'),
                'total' => $items->sum('total'),
                // Al crédito se le debe todo al proveedor hasta registrar sus pagos (Cuentas por pagar)
                'saldo' => $compra->forma_pago === 'credito' ? $items->sum('total') : 0,
            ]);

            return $compra;
        });

        return redirect("/compras/{$compra->id}")->with('success', "Compra {$compra->documento} registrada. El stock ya está disponible.");
    }

    public function show(Compra $compra): Response
    {
        $compra->load([
            'proveedor',
            'usuario:id,name',
            'items.producto:id,codigo,nombre,concentracion,presentacion,unidad_venta',
            'pagos.usuario:id,name',
        ]);

        return Inertia::render('Compras/Show', [
            'compra' => $compra,
            'tiposDocumento' => Compra::TIPOS_DOCUMENTO,
        ]);
    }

    /** Anula la compra y retira del almacén lo que ingresó (si aún está). */
    public function anular(Request $request, Compra $compra, InventarioService $inventario): RedirectResponse
    {
        if ($compra->estado === 'anulada') {
            return back()->with('error', 'Esta compra ya está anulada.');
        }
        if ($compra->pagos()->where('estado', 'activo')->exists()) {
            return back()->with('error', 'Esta compra tiene pagos registrados al proveedor: anúlalos primero en Cuentas por pagar.');
        }

        DB::transaction(function () use ($compra, $request, $inventario) {
            $inventario->revertirEntradas($compra, $request->user()->id, 'anulacion_compra');
            $compra->update(['estado' => 'anulada', 'saldo' => 0]);
        });

        return back()->with('success', "Compra {$compra->documento} anulada y stock retirado.");
    }
}