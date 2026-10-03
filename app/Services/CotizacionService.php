<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\Producto;
use App\Models\User;
use App\Support\ProductoVenta;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cotizaciones: se guardan con sus totales (mismo cálculo que la venta), se anulan
 * y se convierten en venta. No tocan el stock ni se envían a SUNAT.
 */
class CotizacionService
{
    /** Datos del cliente que necesitan los formularios de cotización y venta. */
    public const CAMPOS_CLIENTE = [
        'id', 'tipo_documento', 'numero_documento', 'razon_social', 'direccion',
        'dias_credito', 'estado_sunat', 'condicion_sunat', 'activo',
    ];

    /**
     * Crea o actualiza (si se pasa $cotizacion) una cotización.
     *
     * @param  array  $datos  cliente_id, vendedor_id, validez_dias, forma_pago, condiciones, observaciones, items[]
     *                        items[]: producto_id, cantidad, por_fraccion, precio_unitario, bonificacion
     */
    public function guardar(array $datos, User $usuario, ?Cotizacion $cotizacion = null): Cotizacion
    {
        if ($cotizacion && ! $cotizacion->vigente()) {
            throw ValidationException::withMessages(['cotizacion' => 'Solo se puede editar una cotización pendiente y vigente.']);
        }

        $cliente = Cliente::findOrFail($datos['cliente_id']);
        if (! $cliente->activo) {
            throw ValidationException::withMessages(['cliente_id' => 'Ese cliente está desactivado. Actívalo en Clientes si corresponde.']);
        }

        return DB::transaction(function () use ($datos, $usuario, $cotizacion, $cliente) {
            $validez = (int) $datos['validez_dias'];
            $fecha = $cotizacion?->fecha ?? today();

            $cabecera = [
                'cliente_id' => $cliente->id,
                'vendedor_id' => $datos['vendedor_id'] ?? $usuario->id,
                'validez_dias' => $validez,
                'fecha_vencimiento' => $fecha->copy()->addDays($validez),
                'forma_pago' => $datos['forma_pago'] ?? 'contado',
                'condiciones' => $datos['condiciones'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
            ];

            if ($cotizacion) {
                $cotizacion->update($cabecera);
                $cotizacion->items()->delete();
            } else {
                $cotizacion = Cotizacion::create([
                    ...$cabecera,
                    'sucursal_id' => $usuario->sucursal_id,
                    'user_id' => $usuario->id,
                    'fecha' => $fecha,
                    'estado' => 'pendiente',
                ]);
            }

            $productos = Producto::whereIn('id', collect($datos['items'])->pluck('producto_id'))->get()->keyBy('id');
            foreach ($datos['items'] as $linea) {
                $this->agregarLinea($cotizacion, $productos[$linea['producto_id']], $linea);
            }

            $this->totalizar($cotizacion);

            if ((float) $cotizacion->total <= 0) {
                throw ValidationException::withMessages(['items' => 'La cotización no puede tener total cero.']);
            }

            return $cotizacion;
        });
    }

    public function anular(Cotizacion $cotizacion): void
    {
        if ($cotizacion->estado !== 'pendiente') {
            throw ValidationException::withMessages(['cotizacion' => 'Solo se puede anular una cotización pendiente.']);
        }

        $cotizacion->update(['estado' => 'anulada']);
    }

    /**
     * Cierra la cotización con la venta que se emitió a partir de ella.
     * Se llama dentro de la transacción de la venta.
     */
    public function marcarVendida(int $cotizacionId, Comprobante $comprobante): void
    {
        $cotizacion = Cotizacion::query()->lockForUpdate()->find($cotizacionId);

        if (! $cotizacion || (int) $cotizacion->sucursal_id !== (int) $comprobante->sucursal_id) {
            throw ValidationException::withMessages(['cotizacion_id' => 'La cotización no existe en tu local.']);
        }
        if (! $cotizacion->vigente()) {
            throw ValidationException::withMessages(['cotizacion_id' => "La cotización {$cotizacion->numero} ya no está vigente (vendida, vencida o anulada)."]);
        }

        $cotizacion->update(['estado' => 'vendida', 'comprobante_id' => $comprobante->id]);
    }

    /**
     * Líneas listas para cargar en un formulario (cotización o venta), con los datos actuales del producto.
     * $preciosActuales = true usa el precio de lista de hoy (para renovar una cotización vencida).
     */
    public function lineasParaFormulario(Cotizacion $cotizacion, int $sucursalId, bool $preciosActuales = false): array
    {
        $cotizacion->loadMissing('items');
        $productos = ProductoVenta::consulta($sucursalId)
            ->whereIn('id', $cotizacion->items->pluck('producto_id'))
            ->get()
            ->keyBy('id');

        return $cotizacion->items
            ->filter(fn (CotizacionItem $i) => $productos->has($i->producto_id)) // se omiten productos desactivados
            ->map(function (CotizacionItem $i) use ($productos, $preciosActuales) {
                $p = $productos[$i->producto_id];
                $porFraccion = $i->por_fraccion && $p->fraccionable;
                $precioLista = $porFraccion ? $p->precio_fraccion : $p->precio_venta;

                return [
                    'producto' => ProductoVenta::datos($p),
                    'cantidad' => (float) $i->cantidad,
                    'por_fraccion' => $porFraccion,
                    'bonificacion' => $i->bonificacion,
                    'precio_unitario' => $i->bonificacion ? null : (float) ($preciosActuales ? $precioLista : $i->precio_unitario),
                ];
            })
            ->values()
            ->all();
    }

    // ================= Apoyo =================

    private function agregarLinea(Cotizacion $cotizacion, Producto $producto, array $linea): void
    {
        $porFraccion = $producto->fraccionable && ! empty($linea['por_fraccion']);
        $bonificacion = ! empty($linea['bonificacion']);
        $cantidad = round((float) $linea['cantidad'], 2);
        $precio = $bonificacion ? 0 : round((float) ($linea['precio_unitario'] ?? 0), 4);

        if (! $bonificacion && $precio <= 0) {
            throw ValidationException::withMessages(['items' => "Falta el precio de {$producto->nombre}."]);
        }

        $cotizacion->items()->create([
            'producto_id' => $producto->id,
            'descripcion' => $producto->descripcionCompleta(),
            'unidad' => $porFraccion ? $producto->unidad_fraccion : $producto->unidad_venta,
            'cantidad' => $cantidad,
            'por_fraccion' => $porFraccion,
            'precio_unitario' => $precio,
            'bonificacion' => $bonificacion,
            'importe' => $bonificacion ? 0 : round($cantidad * $precio, 2),
        ]);
    }

    /** Mismo cálculo que la venta: los precios incluyen IGV; las bonificaciones van como gratuitas. */
    private function totalizar(Cotizacion $cotizacion): void
    {
        $t = ['op_gravadas' => 0, 'op_exoneradas' => 0, 'op_gratuitas' => 0, 'igv' => 0, 'total' => 0];

        $cotizacion->load('items.producto:id,tipo_afectacion_igv,precio_venta,precio_fraccion');
        foreach ($cotizacion->items as $item) {
            $gravado = $item->producto->tipo_afectacion_igv === '10';

            if ($item->bonificacion) {
                // Valor referencial de lo regalado: su precio de lista
                $lista = $item->por_fraccion ? (float) $item->producto->precio_fraccion : (float) $item->producto->precio_venta;
                $bruto = round((float) $item->cantidad * $lista, 2);
                $t['op_gratuitas'] += $gravado ? round($bruto / (1 + VentaService::TASA_IGV), 2) : $bruto;

                continue;
            }

            $bruto = (float) $item->importe;
            $valor = $gravado ? round($bruto / (1 + VentaService::TASA_IGV), 2) : $bruto;
            $t[$gravado ? 'op_gravadas' : 'op_exoneradas'] += $valor;
            $t['igv'] += $bruto - $valor;
            $t['total'] += $bruto;
        }

        $cotizacion->update(array_map(fn ($v) => round($v, 2), $t));
    }
}