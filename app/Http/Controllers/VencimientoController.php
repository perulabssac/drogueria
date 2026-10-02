<?php

namespace App\Http\Controllers;

use App\Models\CompraItem;
use App\Models\Lote;
use App\Services\AjusteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Control de vencimientos: lotes vencidos y por vencer, y baja de los vencidos
 * (genera un ajuste de salida con motivo "Producto vencido" y su acta para DIGEMID).
 */
class VencimientoController extends Controller
{
    /** Filtros: vencidos o "vencen en los próximos N días". */
    public const VISTAS = [
        'vencidos' => 'Vencidos',
        '30' => 'Vencen en 30 días',
        '60' => 'En 60 días',
        '90' => 'En 90 días',
        '180' => 'En 6 meses',
    ];

    public function index(Request $request): Response
    {
        $sucursalId = $request->user()->sucursal_id;
        $vista = array_key_exists($request->input('vista'), self::VISTAS) ? (string) $request->input('vista') : 'vencidos';

        $lotes = $this->consulta($sucursalId, $vista)
            ->with('producto:id,codigo,nombre,concentracion,presentacion,laboratorio_id,unidad_venta,fraccionable,unidades_por_presentacion,unidad_fraccion', 'producto.laboratorio:id,nombre')
            ->orderBy('fecha_vencimiento')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $proveedores = $this->proveedores($lotes->getCollection()->pluck('id'));

        $lotes->getCollection()->transform(function (Lote $l) use ($proveedores) {
            $p = $l->producto;
            $factor = $p->factor();

            return [
                'id' => $l->id,
                'numero_lote' => $l->numero_lote,
                'fecha_vencimiento' => $l->fecha_vencimiento->toDateString(),
                'cantidad' => (float) $l->cantidad,
                'costo_unitario' => (float) $l->costo_unitario,
                'valor' => round((float) $l->cantidad / $factor * (float) $l->costo_unitario, 2),
                'vencido' => $l->fecha_vencimiento->lt(today()),
                'proveedor' => $proveedores[$l->id] ?? null,
                'producto' => [
                    ...$p->only(['id', 'codigo', 'unidad_venta', 'fraccionable', 'unidades_por_presentacion', 'unidad_fraccion']),
                    'descripcion' => $p->descripcionCompleta(),
                    'laboratorio' => $p->laboratorio?->nombre,
                ],
            ];
        });

        return Inertia::render('Inventario/Vencimientos', [
            'lotes' => $lotes,
            'vista' => $vista,
            'vistas' => self::VISTAS,
            'resumen' => collect(['vencidos', '30', '90'])->mapWithKeys(fn ($v) => [$v => $this->resumen($sucursalId, $v)]),
        ]);
    }

    /** Da de baja (todo el saldo de) los lotes vencidos elegidos: un solo ajuste con su acta. */
    public function baja(Request $request, AjusteService $ajustes): RedirectResponse
    {
        $datos = $request->validate([
            'lotes' => ['required', 'array', 'min:1', 'max:100'],
            'lotes.*' => ['integer'],
            'observacion' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'lotes.required' => 'Marca los lotes vencidos que vas a dar de baja.',
            'observacion.required' => 'Indica qué se hará con los productos (ej. "Se entregan a empresa de residuos para su destrucción").',
        ]);

        $lotes = Lote::query()
            ->whereIn('id', $datos['lotes'])
            ->where('sucursal_id', $request->user()->sucursal_id)
            ->where('cantidad', '>', 0)
            ->whereDate('fecha_vencimiento', '<', today()->toDateString())
            ->get();

        if ($lotes->isEmpty()) {
            return back()->with('error', 'Ninguno de los lotes elegidos está vencido o ya no tienen stock.');
        }

        // Se saca todo el saldo del lote; "por_fraccion" hace que la cantidad vaya en unidad mínima
        $ajuste = $ajustes->registrar([
            'tipo' => 'salida',
            'motivo' => 'vencimiento',
            'observacion' => $datos['observacion'],
            'items' => $lotes->map(fn (Lote $l) => [
                'producto_id' => $l->producto_id,
                'lote_id' => $l->id,
                'cantidad' => (float) $l->cantidad,
                'por_fraccion' => true,
            ])->values()->all(),
        ], $request->user());

        return redirect("/ajustes/{$ajuste->id}")
            ->with('success', "Se dieron de baja {$lotes->count()} lote(s) vencido(s). Imprime el acta {$ajuste->numero} y guárdala firmada.");
    }

    // ================= Apoyo =================

    private function consulta(int $sucursalId, string $vista): Builder
    {
        $hoy = today()->toDateString();

        return Lote::query()
            ->where('sucursal_id', $sucursalId)
            ->where('cantidad', '>', 0)
            ->when(
                $vista === 'vencidos',
                fn ($q) => $q->whereDate('fecha_vencimiento', '<', $hoy),
                fn ($q) => $q->whereBetween('fecha_vencimiento', [$hoy, today()->addDays((int) $vista)->toDateString()]),
            );
    }

    /** Cantidad de lotes y valor a costo de un filtro. */
    private function resumen(int $sucursalId, string $vista): array
    {
        $fila = $this->consulta($sucursalId, $vista)
            ->join('productos', 'productos.id', '=', 'lotes.producto_id')
            ->toBase()
            ->selectRaw('COUNT(*) AS lotes')
            ->selectRaw('COALESCE(SUM(lotes.cantidad * lotes.costo_unitario / (CASE WHEN productos.fraccionable = 1 AND productos.unidades_por_presentacion > 1 THEN productos.unidades_por_presentacion ELSE 1 END)), 0) AS valor')
            ->first();

        return ['lotes' => (int) $fila->lotes, 'valor' => round((float) $fila->valor, 2)];
    }

    /** Proveedor que vendió cada lote (de su última compra no anulada), para pedir canje o devolución. */
    private function proveedores(Collection $loteIds): array
    {
        return CompraItem::query()
            ->whereIn('lote_id', $loteIds)
            ->whereHas('compra', fn ($q) => $q->where('estado', '!=', 'anulada'))
            ->with('compra:id,proveedor_id,serie,numero', 'compra.proveedor:id,razon_social')
            ->orderBy('id')
            ->get(['id', 'compra_id', 'lote_id'])
            ->mapWithKeys(fn (CompraItem $i) => [$i->lote_id => [
                'nombre' => $i->compra?->proveedor?->razon_social,
                'compra' => $i->compra?->documento,
                'compra_id' => $i->compra_id,
            ]])
            ->all();
    }
}