<?php

namespace App\Services;

use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Serie;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Emite notas de crédito sobre facturas y boletas aceptadas por SUNAT:
 * anulaciones y devoluciones (totales o por ítem). Lo devuelto puede reingresar a su mismo lote.
 */
class NotaCreditoService
{
    /** Motivos (catálogo 09 de SUNAT) que se usan en la droguería. */
    public const MOTIVOS = [
        '01' => 'Anulación de la operación',
        '02' => 'Anulación por error en el RUC',
        '06' => 'Devolución total',
        '07' => 'Devolución por ítem',
    ];

    /** Estos motivos acreditan el comprobante completo. */
    public const MOTIVOS_TOTALES = ['01', '02', '06'];

    public function __construct(
        private InventarioService $inventario,
        private CajaService $cajas,
    ) {}

    /**
     * Cantidad que aún se puede acreditar de cada línea: lo vendido menos lo devuelto
     * en notas de crédito anteriores (las rechazadas por SUNAT no cuentan).
     *
     * @return Collection<int, float> id de la línea => cantidad pendiente
     */
    public function pendientes(Comprobante $comprobante): Collection
    {
        $comprobante->loadMissing('items');

        $devuelto = ComprobanteItem::query()
            ->whereIn('item_referencia_id', $comprobante->items->pluck('id'))
            ->whereHas('comprobante', fn ($q) => $q->where('tipo_comprobante', '07')->where('estado', '!=', 'rechazado'))
            ->selectRaw('item_referencia_id, SUM(cantidad) as total')
            ->groupBy('item_referencia_id')
            ->pluck('total', 'item_referencia_id');

        return $comprobante->items->mapWithKeys(fn ($i) => [
            $i->id => max(0, round((float) $i->cantidad - (float) ($devuelto[$i->id] ?? 0), 2)),
        ]);
    }

    /** Devuelve el motivo por el que NO se puede emitir, o null si sí se puede. */
    public function impedimento(Comprobante $comprobante): ?string
    {
        if (! in_array($comprobante->tipo_comprobante, ['01', '03'], true)) {
            return 'Las notas de crédito solo se emiten sobre facturas y boletas.';
        }
        if (! in_array($comprobante->estado, ['aceptado', 'observado'], true)) {
            return 'El comprobante debe estar aceptado por SUNAT para emitirle una nota de crédito.';
        }
        if ($this->pendientes($comprobante)->sum() <= 0) {
            return 'Este comprobante ya fue acreditado por completo.';
        }

        return null;
    }

    /** Serie de nota de crédito que corresponde: FC.. para facturas, BC.. para boletas. */
    public function serieParaNota(Comprobante $comprobante): ?Serie
    {
        return Serie::query()
            ->where('sucursal_id', $comprobante->sucursal_id)
            ->where('tipo_comprobante', '07')
            ->where('activo', true)
            ->where('serie', 'like', $comprobante->serie[0].'%')
            ->orderBy('serie')
            ->first();
    }

    /**
     * @param  array  $datos  motivo_codigo, motivo_descripcion, reingresar_stock, observaciones,
     *                        devolver_dinero, medio_devolucion,
     *                        items[] (solo devolución por ítem): item_id, cantidad
     */
    public function emitir(Comprobante $referencia, array $datos, User $usuario): Comprobante
    {
        return DB::transaction(function () use ($referencia, $datos, $usuario) {
            // Se bloquea el comprobante para que dos personas no lo acrediten a la vez
            $referencia = Comprobante::query()->lockForUpdate()->findOrFail($referencia->id);
            $referencia->load('items.producto');

            if ($error = $this->impedimento($referencia)) {
                throw ValidationException::withMessages(['motivo_codigo' => $error]);
            }

            $codigo = $datos['motivo_codigo'];
            $pendientes = $this->pendientes($referencia);
            $cantidades = $this->cantidadesADevolver($referencia, $pendientes, $codigo, $datos['items'] ?? []);

            $serie = $this->serieParaNota($referencia);
            if (! $serie) {
                $letra = $referencia->serie[0];
                throw ValidationException::withMessages([
                    'motivo_codigo' => "No hay una serie de nota de crédito que empiece con {$letra} (ej. {$letra}C01). Créala en Configuración → Series.",
                ]);
            }

            [$numSerie, $correlativo] = Serie::siguienteCorrelativo($serie->id);

            $nota = Comprobante::create([
                'sucursal_id' => $referencia->sucursal_id,
                'user_id' => $usuario->id,
                'vendedor_id' => $referencia->vendedor_id,
                'cliente_id' => $referencia->cliente_id,
                'tipo_comprobante' => '07',
                'serie' => $numSerie,
                'correlativo' => $correlativo,
                'fecha_emision' => now(),
                'moneda' => $referencia->moneda ?: 'PEN',
                'forma_pago' => 'contado',
                'comprobante_referencia_id' => $referencia->id,
                'motivo_codigo' => $codigo,
                'motivo_descripcion' => mb_strtoupper(trim($datos['motivo_descripcion'] ?? '') ?: self::MOTIVOS[$codigo]),
                'observaciones' => $datos['observaciones'] ?? null,
                'estado' => 'pendiente',
            ]);

            $reingresar = ! empty($datos['reingresar_stock']);

            foreach ($cantidades as $itemId => $cantidad) {
                $item = $referencia->items->firstWhere('id', $itemId);
                $this->agregarLinea($nota, $item, $cantidad);

                if ($reingresar && $item->lote_id) {
                    $factor = $item->es_fraccion ? 1 : ($item->producto?->factor() ?? 1);
                    $this->inventario->devolverALote($item->lote_id, $cantidad * $factor, $usuario->id, 'devolucion_venta', $nota);
                }
            }

            VentaService::totalizar($nota);

            // El dinero que se le devuelve al cliente sale de la caja de quien emite la nota.
            // En una venta al crédito la nota primero rebaja la deuda: solo se devuelve lo pagado de más.
            $montoDevolver = (float) $nota->total;
            if ($referencia->esCredito()) {
                $montoDevolver = min($montoDevolver, max(0, -CobranzaService::balance($referencia)));
            }

            if (! empty($datos['devolver_dinero']) && $montoDevolver <= 0 && $referencia->esCredito()) {
                throw ValidationException::withMessages([
                    'devolver_dinero' => 'Esta venta al crédito no tiene pagos por devolver: la nota solo rebajará la deuda. Desmarca "Devolver el dinero".',
                ]);
            }

            if (! empty($datos['devolver_dinero']) && $montoDevolver > 0) {
                $caja = $this->cajas->requerirAbierta($usuario, 'devolver_dinero');
                $this->cajas->registrarMovimiento(
                    $caja,
                    $usuario,
                    'egreso',
                    $montoDevolver,
                    "Devolución al cliente · {$nota->numero} (modifica {$referencia->numero})",
                    $datos['medio_devolucion'] ?? 'efectivo',
                    $nota,
                );
            }

            // Venta al crédito: la nota rebaja lo que el cliente debe
            CobranzaService::recalcularSaldo($referencia);

            return $nota;
        });
    }

    /**
     * Qué se devuelve de cada línea:
     * - Motivos totales: todo lo vendido (solo si no hubo devoluciones parciales antes).
     * - Devolución por ítem: lo que indicó el usuario, sin superar lo pendiente.
     *
     * @return array<int, float> id de la línea => cantidad
     */
    private function cantidadesADevolver(Comprobante $referencia, Collection $pendientes, string $codigo, array $items): array
    {
        if (in_array($codigo, self::MOTIVOS_TOTALES, true)) {
            $huboParciales = $referencia->items->contains(fn ($i) => abs($pendientes[$i->id] - (float) $i->cantidad) > 0.001);
            if ($huboParciales) {
                throw ValidationException::withMessages([
                    'motivo_codigo' => 'Este comprobante ya tiene devoluciones anteriores: usa "Devolución por ítem" para lo que falta.',
                ]);
            }

            return $pendientes->filter(fn ($q) => $q > 0)->all();
        }

        $cantidades = [];
        foreach ($items as $linea) {
            $id = (int) ($linea['item_id'] ?? 0);
            $cantidad = round((float) ($linea['cantidad'] ?? 0), 2);
            if ($cantidad <= 0) {
                continue;
            }
            if (! $pendientes->has($id)) {
                throw ValidationException::withMessages(['items' => 'Una de las líneas no pertenece a este comprobante.']);
            }
            if ($cantidad > $pendientes[$id] + 0.001) {
                $item = $referencia->items->firstWhere('id', $id);
                throw ValidationException::withMessages([
                    'items' => "De {$item->descripcion} (lote {$item->numero_lote}) solo quedan {$pendientes[$id]} {$item->unidad} por devolver.",
                ]);
            }
            $cantidades[$id] = $cantidad;
        }

        if (! $cantidades) {
            throw ValidationException::withMessages(['items' => 'Indica la cantidad que se devuelve de al menos un producto.']);
        }

        return $cantidades;
    }

    /** Copia la línea de la factura/boleta con la cantidad devuelta y recalcula sus montos. */
    private function agregarLinea(Comprobante $nota, ComprobanteItem $item, float $cantidad): void
    {
        // Las bonificaciones se guardan con afectación "gratuita" (15, 21, 31): se recupera la original
        $afectacion = $item->bonificacion
            ? (string) array_search($item->tipo_afectacion_igv, VentaService::AFECTACION_GRATUITA, true)
            : $item->tipo_afectacion_igv;

        $nota->items()->create([
            'item_referencia_id' => $item->id,
            'producto_id' => $item->producto_id,
            'lote_id' => $item->lote_id,
            'numero_lote' => $item->numero_lote,
            'fecha_vencimiento' => $item->fecha_vencimiento,
            'codigo' => $item->codigo,
            'descripcion' => $item->descripcion,
            'unidad' => $item->unidad,
            'unidad_sunat' => $item->unidad_sunat,
            'es_fraccion' => $item->es_fraccion,
            'cantidad' => $cantidad,
            'bonificacion' => $item->bonificacion,
            ...VentaService::calcularMontos($afectacion, $cantidad, (float) $item->precio_unitario, $item->bonificacion),
        ]);
    }
}