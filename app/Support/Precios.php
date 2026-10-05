<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Producto;

/**
 * Cálculo de precios con margen de ganancia SOBRE EL COSTO (como se trabaja en boticas y droguerías).
 * Cada producto tiene su margen, elegido de una lista (10 % a 100 %) o escrito a mano.
 *   precio sin IGV = costo sin IGV × (1 + margen %)
 *   precio con IGV = precio sin IGV × 1.18   (solo si el producto es gravado)
 * y se redondea hacia arriba (ej. a S/ 0.10).
 *
 * También se informa el "margen sobre la venta" (ganancia ÷ precio sin IGV), que es el que usa contabilidad.
 * La pantalla de productos repite estas mismas fórmulas en JavaScript (resources/js/utils/precios.js).
 */
class Precios
{
    public const TASA_IGV = 0.18;

    /** Márgenes de la lista desplegable: 10 %, 15 %, 20 % ... 100 %. */
    public static function opcionesMargen(): array
    {
        return range(10, 100, 5);
    }

    /** Precio de venta con IGV sugerido para un costo sin IGV y un margen. */
    public static function sugerido(float $costo, float $margen, bool $gravado, float $redondeo = 0.10): float
    {
        if ($costo <= 0) {
            return 0;
        }
        $precio = $costo * (1 + $margen / 100) * ($gravado ? 1 + self::TASA_IGV : 1);

        return self::redondear($precio, $redondeo);
    }

    /**
     * Precio sugerido de la presentación y de la unidad suelta de un producto con su margen.
     * Devuelve null si el producto aún no tiene margen asignado.
     */
    public static function sugeridosProducto(Producto $producto, ?float $costo = null, ?Empresa $empresa = null): ?array
    {
        if ($producto->margen === null) {
            return null;
        }
        $empresa ??= Empresa::actual();
        $costo ??= (float) $producto->costo;
        $margen = (float) $producto->margen;
        $gravado = $producto->tipo_afectacion_igv === '10';
        $redondeo = (float) $empresa->redondeo_precio ?: 0.01;

        return [
            'margen' => $margen,
            'precio_venta' => self::sugerido($costo, $margen, $gravado, $redondeo),
            'precio_fraccion' => $producto->fraccionable && $producto->unidades_por_presentacion > 1
                ? self::sugerido($costo / $producto->unidades_por_presentacion, $margen, $gravado, 0.01)
                : null,
        ];
    }

    /**
     * Ganancia y márgenes de un precio con IGV frente a un costo sin IGV.
     *
     * @return array{precio_sin_igv: float, ganancia: float, margen_costo: ?float, margen_venta: ?float}
     */
    public static function analizar(float $precioConIgv, float $costo, bool $gravado): array
    {
        $sinIgv = $gravado ? $precioConIgv / (1 + self::TASA_IGV) : $precioConIgv;
        $ganancia = $sinIgv - $costo;

        return [
            'precio_sin_igv' => round($sinIgv, 4),
            'ganancia' => round($ganancia, 4),
            'margen_costo' => $costo > 0 ? round($ganancia / $costo * 100, 2) : null,
            'margen_venta' => $sinIgv > 0 ? round($ganancia / $sinIgv * 100, 2) : null,
        ];
    }

    /** Redondea hacia arriba al múltiplo indicado (0.10 → 12.31 pasa a 12.40). */
    public static function redondear(float $precio, float $redondeo): float
    {
        if ($redondeo <= 0) {
            return round($precio, 2);
        }
        // round() interno evita que 12.30 se convierta en 12.40 por decimales flotantes
        return round(ceil(round($precio / $redondeo, 6)) * $redondeo, 2);
    }
}