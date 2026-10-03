<?php

namespace App\Services;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\ComprobantePago;
use App\Models\Producto;
use App\Models\Serie;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra una venta: valida reglas de negocio y de SUNAT, descuenta stock por FEFO
 * (una línea del comprobante por cada lote) y calcula los montos.
 */
class VentaService
{
    public const TASA_IGV = 0.18;

    /** Si una boleta supera este monto, debe identificar al comprador (DNI). */
    public const BOLETA_MONTO_IDENTIFICAR = 700;

    /**
     * Afectación de IGV cuando el producto se entrega gratis (bonificación).
     * Es pública porque las notas de crédito la usan para recuperar la afectación original.
     */
    public const AFECTACION_GRATUITA = ['10' => '15', '20' => '21', '30' => '31'];

    public function __construct(
        private InventarioService $inventario,
        private CajaService $cajas,
        private CotizacionService $cotizaciones,
    ) {}

    /**
     * @param  array  $datos  serie_id, cliente_id, vendedor_id, forma_pago, cuotas[], pagos[], guia_remision,
     *                        orden_compra, observaciones, receta_verificada, cotizacion_id, items[]
     *                        items[]: producto_id, cantidad, por_fraccion, precio_unitario, bonificacion
     *                        pagos[]: medio, monto, recibido, referencia
     */
    public function registrar(array $datos, User $usuario): Comprobante
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $serie = Serie::query()->where('activo', true)->findOrFail($datos['serie_id']);
            $cliente = Cliente::findOrFail($datos['cliente_id']);
            $tipo = $serie->tipo_comprobante;
            $esFactura = $tipo === '01';
            $esBoleta = $tipo === '03';
            $esCredito = ($datos['forma_pago'] ?? 'contado') === 'credito';

            if (! in_array($tipo, ['01', '03', Comprobante::NOTA_VENTA], true)) {
                throw ValidationException::withMessages(['serie_id' => 'Esa serie no es de venta.']);
            }
            if ((int) $serie->sucursal_id !== (int) $usuario->sucursal_id) {
                throw ValidationException::withMessages(['serie_id' => 'Esa serie no pertenece a tu sucursal.']);
            }
            if ($esFactura && $cliente->tipo_documento !== '6') {
                throw ValidationException::withMessages(['cliente_id' => 'La factura requiere un cliente con RUC.']);
            }
            if ($esCredito && $esBoleta) {
                throw ValidationException::withMessages(['forma_pago' => 'Las boletas son solo al contado. Usa factura o nota de venta.']);
            }
            if ($esCredito && $cliente->numero_documento === '00000000') {
                throw ValidationException::withMessages(['cliente_id' => 'Para vender al crédito identifica al cliente (no "Clientes varios").']);
            }

            // ===== NUEVO: reglas del módulo de clientes =====
            if (! $cliente->activo) {
                throw ValidationException::withMessages(['cliente_id' => 'Ese cliente está desactivado. Actívalo en Clientes si corresponde.']);
            }
            // No se factura a un RUC dado de baja o no habido (según la última verificación con SUNAT)
            if ($esFactura && ($problema = $cliente->problemaSunat())) {
                throw ValidationException::withMessages(['cliente_id' => "No se puede emitir factura: {$problema}"]);
            }
            if ($esCredito && $cliente->tieneDeudaVencida()) {
                throw ValidationException::withMessages([
                    'forma_pago' => 'El cliente tiene cuotas vencidas sin pagar. Cobra lo vencido (menú Cobranzas) o véndele al contado.',
                ]);
            }

            // El dinero de las ventas al contado entra a la caja de quien cobra
            $caja = $esCredito ? null : $this->cajas->requerirAbierta($usuario, 'pagos');

            // Productos con receta: el vendedor debe confirmar que la verificó
            $productos = Producto::whereIn('id', collect($datos['items'])->pluck('producto_id'))->get()->keyBy('id');
            $conReceta = $productos->first(fn ($p) => $p->condicion_venta !== 'sin_receta');
            if ($conReceta && empty($datos['receta_verificada'])) {
                throw ValidationException::withMessages([
                    'receta_verificada' => "{$conReceta->nombre} requiere receta médica: confirma que fue verificada.",
                ]);
            }

            [$numSerie, $correlativo] = Serie::siguienteCorrelativo($serie->id);

            $comprobante = Comprobante::create([
                'sucursal_id' => $serie->sucursal_id,
                'user_id' => $usuario->id,
                'vendedor_id' => $datos['vendedor_id'] ?? $usuario->id,
                'cliente_id' => $cliente->id,
                'tipo_comprobante' => $serie->tipo_comprobante,
                'serie' => $numSerie,
                'correlativo' => $correlativo,
                'fecha_emision' => now(),
                // SUNAT rechaza el XML si no lleva la moneda (código 2070)
                'moneda' => 'PEN',
                'forma_pago' => $datos['forma_pago'] ?? 'contado',
                'guia_remision' => $datos['guia_remision'] ?? null,
                'orden_compra' => $datos['orden_compra'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
                // La nota de venta no se envía a SUNAT
                'estado' => $tipo === Comprobante::NOTA_VENTA ? 'interno' : 'pendiente',
            ]);

            foreach ($datos['items'] as $linea) {
                $this->agregarLinea($comprobante, $productos[$linea['producto_id']], $linea, $usuario);
            }

            self::totalizar($comprobante);

            if ($esBoleta && (float) $comprobante->total > self::BOLETA_MONTO_IDENTIFICAR && $cliente->numero_documento === '00000000') {
                throw ValidationException::withMessages([
                    'cliente_id' => 'Las boletas de más de S/ '.self::BOLETA_MONTO_IDENTIFICAR.' deben identificar al cliente con su DNI.',
                ]);
            }
            if ((float) $comprobante->total <= 0) {
                throw ValidationException::withMessages(['items' => 'El comprobante no puede tener total cero.']);
            }

            // ===== NUEVO: límite de crédito del cliente (0 = sin tope) =====
            if ($esCredito && (float) $cliente->limite_credito > 0) {
                $deudaTotal = $cliente->deuda() + (float) $comprobante->total; // esta venta aún tiene saldo 0
                if ($deudaTotal > (float) $cliente->limite_credito + 0.009) {
                    throw ValidationException::withMessages([
                        'forma_pago' => 'Con esta venta el cliente debería S/ '.number_format($deudaTotal, 2)
                            .' y su límite de crédito es S/ '.number_format((float) $cliente->limite_credito, 2).'.',
                    ]);
                }
            }

            if ($comprobante->esCredito()) {
                $this->registrarCuotas($comprobante, $datos['cuotas'] ?? []);
                // Al crédito: el cliente debe todo hasta que se registren sus cobros (módulo Cobranzas)
                $comprobante->update(['saldo' => $comprobante->total]);
            } else {
                $this->registrarPagos($comprobante, $datos['pagos'] ?? [], $usuario, $caja);
            }

            // Venta que nace de una cotización: la cotización queda como vendida (no se puede usar dos veces)
            if (! empty($datos['cotizacion_id'])) {
                $this->cotizaciones->marcarVendida((int) $datos['cotizacion_id'], $comprobante);
            }

            return $comprobante;
        });
    }

    /**
     * Descuenta el stock por FEFO y crea una línea del comprobante por cada lote usado.
     */
    private function agregarLinea(Comprobante $comprobante, Producto $producto, array $linea, User $usuario): void
    {
        $porFraccion = $producto->fraccionable && ! empty($linea['por_fraccion']);
        $factorLinea = $porFraccion ? 1 : $producto->factor(); // unidades mínimas por unidad vendida
        $cantidadVendida = round((float) $linea['cantidad'], 2);
        $bonificacion = ! empty($linea['bonificacion']);

        // En bonificaciones el precio sirve como "valor referencial" (SUNAT lo exige aunque no se cobre)
        $precioLista = $porFraccion ? (float) $producto->precio_fraccion : (float) $producto->precio_venta;
        $precio = $bonificacion ? $precioLista : round((float) ($linea['precio_unitario'] ?? $precioLista), 3);

        $salidas = $this->inventario->descontarFefo(
            $producto,
            $comprobante->sucursal_id,
            $cantidadVendida * $factorLinea,
            ! $porFraccion,
            $usuario->id,
            $bonificacion ? 'bonificacion' : 'venta',
            $comprobante,
        );

        foreach ($salidas as $salida) {
            $cantidad = round($salida['cantidad'] / $factorLinea, 2);

            $comprobante->items()->create([
                'producto_id' => $producto->id,
                'lote_id' => $salida['lote']->id,
                'numero_lote' => $salida['lote']->numero_lote,
                'fecha_vencimiento' => $salida['lote']->fecha_vencimiento,
                'codigo' => $producto->codigo,
                'descripcion' => mb_strtoupper($producto->descripcionCompleta()),
                'unidad' => $porFraccion ? $producto->unidad_fraccion : $producto->unidad_venta,
                'unidad_sunat' => $porFraccion ? 'NIU' : $producto->unidad_sunat,
                'es_fraccion' => $porFraccion,
                'cantidad' => $cantidad,
                'bonificacion' => $bonificacion,
                ...self::calcularMontos($producto->tipo_afectacion_igv, $cantidad, $precio, $bonificacion),
            ]);
        }
    }

    /**
     * Montos de una línea a partir del precio con IGV (así se negocia en la droguería).
     * En bonificaciones el total cobrado es 0, pero se informan el valor y el IGV referenciales.
     */
    public static function calcularMontos(string $afectacion, float $cantidad, float $precioConIgv, bool $bonificacion = false): array
    {
        $gravado = $afectacion === '10';

        $importe = round($cantidad * $precioConIgv, 2);
        $valorVenta = $gravado ? round($importe / (1 + self::TASA_IGV), 2) : $importe;
        $igv = round($importe - $valorVenta, 2);

        return [
            'tipo_afectacion_igv' => $bonificacion ? self::AFECTACION_GRATUITA[$afectacion] : $afectacion,
            'precio_unitario' => $precioConIgv,
            'valor_unitario' => $gravado ? round($precioConIgv / (1 + self::TASA_IGV), 6) : $precioConIgv,
            'valor_venta' => $valorVenta,
            'igv' => $igv,
            'total' => $bonificacion ? 0 : $importe,
        ];
    }

    /** Suma las líneas y guarda los totales del comprobante (también lo usan las notas de crédito). */
    public static function totalizar(Comprobante $comprobante): void
    {
        $items = $comprobante->items()->get();
        $cobrados = $items->where('bonificacion', false);

        $comprobante->update([
            'op_gravadas' => $cobrados->where('tipo_afectacion_igv', '10')->sum('valor_venta'),
            'op_exoneradas' => $cobrados->where('tipo_afectacion_igv', '20')->sum('valor_venta'),
            'op_inafectas' => $cobrados->where('tipo_afectacion_igv', '30')->sum('valor_venta'),
            'op_gratuitas' => $items->where('bonificacion', true)->sum('valor_venta'),
            'igv' => $cobrados->sum('igv'),
            'total' => $cobrados->sum('total'),
        ]);
    }

    /**
     * Venta al contado: los pagos (uno o varios medios) deben cubrir exactamente el total.
     * En efectivo se guarda lo recibido para calcular el vuelto.
     */
    private function registrarPagos(Comprobante $comprobante, array $pagos, User $usuario, Caja $caja): void
    {
        if (! $pagos) {
            throw ValidationException::withMessages(['pagos' => 'Indica con qué medio se pagó la venta.']);
        }

        $suma = round(collect($pagos)->sum(fn ($p) => (float) $p['monto']), 2);
        if (abs($suma - (float) $comprobante->total) > 0.009) {
            throw ValidationException::withMessages([
                'pagos' => "Los pagos suman S/ {$suma} y el total es S/ {$comprobante->total}: deben ser iguales.",
            ]);
        }

        foreach ($pagos as $pago) {
            $efectivo = $pago['medio'] === 'efectivo';
            $recibido = $efectivo && ! empty($pago['recibido']) ? round((float) $pago['recibido'], 2) : null;

            if ($recibido !== null && $recibido < (float) $pago['monto']) {
                throw ValidationException::withMessages(['pagos' => 'El efectivo recibido no alcanza para cubrir el pago.']);
            }

            $comprobante->pagos()->create([
                'caja_id' => $caja->id,
                'user_id' => $usuario->id,
                'tipo' => ComprobantePago::TIPO_VENTA, // pago al contado (no es cobranza)
                'medio' => $pago['medio'],
                'monto' => round((float) $pago['monto'], 2),
                'recibido' => $recibido,
                'referencia' => $efectivo ? null : ($pago['referencia'] ?? null),
                'fecha' => now(),
            ]);
        }
    }

    /** Las cuotas deben sumar exactamente el total (SUNAT lo valida). */
    private function registrarCuotas(Comprobante $comprobante, array $cuotas): void
    {
        if (! $cuotas) {
            throw ValidationException::withMessages(['cuotas' => 'Indica al menos una cuota para la venta al crédito.']);
        }

        $suma = round(collect($cuotas)->sum(fn ($c) => (float) $c['monto']), 2);
        if (abs($suma - (float) $comprobante->total) > 0.009) {
            throw ValidationException::withMessages([
                'cuotas' => "Las cuotas suman S/ {$suma} y el total es S/ {$comprobante->total}: deben ser iguales.",
            ]);
        }

        foreach (array_values($cuotas) as $i => $cuota) {
            if (strtotime($cuota['fecha_vencimiento']) <= strtotime($comprobante->fecha_emision->toDateString())) {
                throw ValidationException::withMessages(['cuotas' => 'Las cuotas deben vencer después de la fecha de emisión.']);
            }
            $comprobante->cuotas()->create([
                'numero' => $i + 1,
                'monto' => round((float) $cuota['monto'], 2),
                'fecha_vencimiento' => $cuota['fecha_vencimiento'],
            ]);
        }

        $comprobante->update(['fecha_vencimiento' => collect($cuotas)->max('fecha_vencimiento')]);
    }
}