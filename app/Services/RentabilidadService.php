<?php

namespace App\Services;

use App\Models\Comprobante;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rentabilidad de las ventas de una sucursal en un rango de fechas.
 * - Venta: valor de venta SIN IGV (las bonificaciones no suman venta, pero sí costo).
 * - Costo: cantidad vendida × costo del lote que salió (si se vendió suelto, se divide entre las unidades).
 * - Las notas de crédito restan venta, costo y cantidad. Facturas, boletas o notas rechazadas o anuladas no cuentan.
 * - Margen sobre el costo = ganancia ÷ costo · Margen sobre la venta = ganancia ÷ venta.
 */
class RentabilidadService
{
    /** Por debajo de este margen sobre el costo, el producto se marca como "margen bajo". */
    public const MARGEN_BAJO = 10;

    private const SIGNO = "(CASE WHEN c.tipo_comprobante = '07' THEN -1 ELSE 1 END)";
    private const FACTOR = '(CASE WHEN i.es_fraccion = 1 AND p.unidades_por_presentacion > 0 THEN p.unidades_por_presentacion ELSE 1 END)';

    public function __construct(private int $sucursalId, private Carbon $desde, private Carbon $hasta) {}

    /** Totales del periodo. */
    public function resumen(): array
    {
        $fila = $this->lineas()
            ->selectRaw($this->sumas())
            ->selectRaw("COUNT(DISTINCT CASE WHEN c.tipo_comprobante <> '07' THEN c.id END) as documentos")
            ->first();

        return [
            ...$this->conMargenes((float) $fila->venta, (float) $fila->costo),
            'documentos' => (int) $fila->documentos,
            'sin_costo' => (int) $fila->sin_costo,
        ];
    }

    /** Rentabilidad de cada producto vendido, del que más ganancia deja al que menos. */
    public function porProducto(): Collection
    {
        return $this->lineas()
            ->groupBy('p.id', 'p.codigo', 'p.nombre', 'p.concentracion', 'p.presentacion', 'p.unidad_venta', 'p.margen')
            ->select('p.id', 'p.codigo', 'p.unidad_venta', 'p.margen as margen_asignado')
            ->selectRaw("TRIM(CONCAT_WS(' ', p.nombre, p.concentracion, p.presentacion)) as nombre")
            ->selectRaw($this->sumas())
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'codigo' => $f->codigo,
                'nombre' => $f->nombre,
                'unidad_venta' => $f->unidad_venta,
                'cantidad' => round((float) $f->cantidad, 2),
                'margen_asignado' => $f->margen_asignado !== null ? (float) $f->margen_asignado : null,
                'sin_costo' => (int) $f->sin_costo,
                ...$this->conMargenes((float) $f->venta, (float) $f->costo),
            ])
            ->filter(fn ($f) => abs($f['cantidad']) > 0.001 || abs($f['venta']) > 0.001)
            ->sortByDesc('ganancia')
            ->values();
    }

    /** Ganancia que generó cada vendedor. */
    public function porVendedor(): Collection
    {
        return $this->lineas()
            ->leftJoin('users as u', 'u.id', '=', 'c.vendedor_id')
            ->groupBy('c.vendedor_id', 'u.name')
            ->select('c.vendedor_id')
            ->selectRaw("COALESCE(u.name, 'Sin vendedor') as vendedor")
            ->selectRaw($this->sumas())
            ->selectRaw("COUNT(DISTINCT CASE WHEN c.tipo_comprobante <> '07' THEN c.id END) as documentos")
            ->get()
            ->map(fn ($f) => [
                'vendedor' => $f->vendedor,
                'documentos' => (int) $f->documentos,
                ...$this->conMargenes((float) $f->venta, (float) $f->costo),
            ])
            ->sortByDesc('ganancia')
            ->values();
    }

    /**
     * Productos que necesitan atención: vendidos a pérdida, con margen bajo o sin margen asignado.
     *
     * @return array{perdida: Collection, bajo: Collection, sin_margen: Collection}
     */
    public function alertas(Collection $productos): array
    {
        return [
            'perdida' => $productos->filter(fn ($p) => $p['ganancia'] < 0)->sortBy('ganancia')->values(),
            'bajo' => $productos->filter(fn ($p) => $p['ganancia'] >= 0 && $p['margen_costo'] !== null && $p['margen_costo'] < self::MARGEN_BAJO)->sortBy('margen_costo')->values(),
            'sin_margen' => $productos->filter(fn ($p) => $p['margen_asignado'] === null)->values(),
        ];
    }

    /** Líneas de venta y de notas de crédito válidas del periodo. */
    private function lineas(): Builder
    {
        return DB::table('comprobante_items as i')
            ->join('comprobantes as c', 'c.id', '=', 'i.comprobante_id')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->leftJoin('lotes as l', 'l.id', '=', 'i.lote_id')
            ->where('c.sucursal_id', $this->sucursalId)
            ->whereIn('c.tipo_comprobante', [...DashboardService::TIPOS_VENTA, '07'])
            ->whereNotIn('c.estado', Comprobante::ESTADOS_SIN_VALIDEZ)
            ->whereBetween('c.fecha_emision', [$this->desde->copy()->startOfDay(), $this->hasta->copy()->endOfDay()]);
    }

    /** Sumas de venta (sin IGV), costo y cantidad en presentaciones; las notas de crédito restan. */
    private function sumas(): string
    {
        $s = self::SIGNO;
        $f = self::FACTOR;

        return implode(', ', [
            "COALESCE(SUM({$s} * CASE WHEN i.bonificacion = 1 THEN 0 ELSE i.valor_venta END), 0) as venta",
            "COALESCE(SUM({$s} * i.cantidad * COALESCE(l.costo_unitario, 0) / {$f}), 0) as costo",
            "COALESCE(SUM({$s} * i.cantidad / {$f}), 0) as cantidad",
            // Líneas sin lote: no se sabe su costo (no debería pasar; se avisa para revisarlas)
            "COALESCE(SUM(CASE WHEN l.id IS NULL THEN 1 ELSE 0 END), 0) as sin_costo",
        ]);
    }

    private function conMargenes(float $venta, float $costo): array
    {
        $venta = round($venta, 2);
        $costo = round($costo, 2);
        $ganancia = round($venta - $costo, 2);

        return [
            'venta' => $venta,
            'costo' => $costo,
            'ganancia' => $ganancia,
            'margen_costo' => $costo > 0 ? round($ganancia / $costo * 100, 1) : null,
            'margen_venta' => $venta > 0 ? round($ganancia / $venta * 100, 1) : null,
        ];
    }
}