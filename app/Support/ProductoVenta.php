<?php

namespace App\Support;

use App\Models\Producto;
use Illuminate\Database\Eloquent\Builder;

/**
 * Datos de un producto tal como los usan las pantallas de venta y cotización
 * (precios, unidades, stock vigente del local). Una sola fuente para ambos formularios.
 */
class ProductoVenta
{
    /** Productos activos con su stock vigente (lotes no vencidos) en la sucursal. */
    public static function consulta(int $sucursalId): Builder
    {
        return Producto::query()
            ->where('activo', true)
            ->with('laboratorio:id,nombre')
            ->withSum(['lotes as stock' => fn ($q) => $q
                ->where('sucursal_id', $sucursalId)
                ->whereDate('fecha_vencimiento', '>=', now()->toDateString())], 'cantidad');
    }

    public static function datos(Producto $p): array
    {
        return [
            ...$p->only([
                'id', 'codigo', 'nombre', 'concentracion', 'presentacion', 'unidad_venta', 'tipo_afectacion_igv',
                'condicion_venta', 'controlado', 'cadena_frio', 'fraccionable', 'unidades_por_presentacion', 'unidad_fraccion',
            ]),
            'descripcion' => $p->descripcionCompleta(),
            'laboratorio' => $p->laboratorio?->nombre,
            'precio_venta' => (float) $p->precio_venta,
            'precio_fraccion' => $p->precio_fraccion !== null ? (float) $p->precio_fraccion : null,
            'costo' => (float) $p->costo,
            'stock' => (float) ($p->stock ?? 0),
        ];
    }
}