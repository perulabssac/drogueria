<?php

namespace App\Services;

use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Todas las entradas y salidas de stock pasan por aquí, para que el kárdex
 * siempre cuadre con los lotes. Debe usarse dentro de una transacción.
 */
class InventarioService
{
    /**
     * Ingresa stock a un lote (lo crea si no existe) y lo registra en el kárdex.
     * - cantidad: en unidad mínima (presentaciones x factor del producto)
     * - costo_unitario: por presentación, sin IGV
     */
    public function ingresarLote(array $datos, ?int $userId, string $motivo, ?Model $referencia = null): Lote
    {
        $lote = Lote::query()
            ->where('producto_id', $datos['producto_id'])
            ->where('sucursal_id', $datos['sucursal_id'])
            ->where('numero_lote', $datos['numero_lote'])
            ->lockForUpdate()
            ->first();

        if ($lote && $lote->fecha_vencimiento->toDateString() !== $datos['fecha_vencimiento']) {
            throw ValidationException::withMessages([
                'items' => "El lote {$datos['numero_lote']} ya existe con vencimiento {$lote->fecha_vencimiento->format('d/m/Y')}. Revisa la fecha.",
            ]);
        }

        $costo = (float) ($datos['costo_unitario'] ?? 0);

        if (! $lote) {
            $lote = new Lote([
                'producto_id' => $datos['producto_id'],
                'sucursal_id' => $datos['sucursal_id'],
                'numero_lote' => $datos['numero_lote'],
                'fecha_vencimiento' => $datos['fecha_vencimiento'],
                'cantidad' => 0,
                'costo_unitario' => $costo,
            ]);
        } elseif ($costo > 0) {
            // Una bonificación (costo 0) no debe borrar el costo real del lote
            $lote->costo_unitario = $costo;
        }

        $lote->cantidad = (float) $lote->cantidad + (float) $datos['cantidad'];
        $lote->save();

        $this->registrarMovimiento($lote, 'entrada', $motivo, (float) $datos['cantidad'], $userId, $referencia);

        return $lote;
    }

    /**
     * Revierte las entradas que hizo un documento (ej. al anular una compra).
     * Falla si parte de esa mercadería ya salió del almacén.
     *
     * - $neto = true: por cada lote revierte solo lo que sigue ingresado por el documento
     *   (entradas menos las reversiones ya hechas). Se usa en compras, que pueden haberse
     *   corregido antes (cada corrección revierte y vuelve a ingresar).
     * - $accion: verbo para el mensaje de error ("anular", "corregir"...).
     */
    public function revertirEntradas(Model $origen, ?int $userId, string $motivo, bool $neto = false, string $accion = 'anular'): void
    {
        foreach ($this->entradasPorRevertir($origen, $neto) as $loteId => $cantidad) {
            $lote = Lote::query()->lockForUpdate()->findOrFail($loteId);

            if ((float) $lote->cantidad + 0.0001 < $cantidad) {
                throw ValidationException::withMessages([
                    'compra' => "No se puede {$accion}: del lote {$lote->numero_lote} ya salió mercadería (quedan {$lote->cantidad}, ingresaron {$cantidad}).",
                ]);
            }

            $lote->cantidad = round((float) $lote->cantidad - $cantidad, 2);
            $lote->save();

            $this->registrarMovimiento($lote, 'salida', $motivo, $cantidad, $userId, $origen);
        }
    }

    /**
     * Revisa, sin cambiar nada, si las entradas de un documento siguen completas en sus lotes
     * (es decir, que nada de esa mercadería se vendió ni se movió).
     */
    public function entradasIntactas(Model $origen, bool $neto = true): bool
    {
        foreach ($this->entradasPorRevertir($origen, $neto) as $loteId => $cantidad) {
            $lote = Lote::find($loteId);
            if (! $lote || (float) $lote->cantidad + 0.0001 < $cantidad) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cantidad por lote que un documento ingresó y que habría que retirar.
     *
     * @return array<int, float> lote_id => cantidad (unidad mínima)
     */
    private function entradasPorRevertir(Model $origen, bool $neto): array
    {
        $movimientos = MovimientoInventario::query()
            ->where('referencia_type', $origen->getMorphClass())
            ->where('referencia_id', $origen->getKey())
            ->when(! $neto, fn ($q) => $q->where('tipo', 'entrada'))
            ->orderBy('id')
            ->get();

        if (! $neto) {
            // Comportamiento original: cada entrada por separado (agrupada por lote)
            return $movimientos->groupBy('lote_id')->map(fn ($m) => round($m->sum(fn ($x) => (float) $x->cantidad), 2))->all();
        }

        return $movimientos
            ->groupBy('lote_id')
            ->map(fn ($m) => round(
                $m->where('tipo', 'entrada')->sum(fn ($x) => (float) $x->cantidad)
                - $m->where('tipo', 'salida')->sum(fn ($x) => (float) $x->cantidad),
                2,
            ))
            ->filter(fn ($cantidad) => $cantidad > 0.0001)
            ->all();
    }

    /**
     * Reingresa mercadería a un lote concreto (ej. devolución de un cliente con nota de crédito).
     * $cantidad en unidad mínima.
     */
    public function devolverALote(int $loteId, float $cantidad, ?int $userId, string $motivo, ?Model $referencia = null): void
    {
        $lote = Lote::query()->lockForUpdate()->findOrFail($loteId);
        $lote->cantidad = (float) $lote->cantidad + $cantidad;
        $lote->save();

        $this->registrarMovimiento($lote, 'entrada', $motivo, $cantidad, $userId, $referencia);
    }

    /**
     * Devuelve a sus lotes lo que salió por un documento (ej. una venta rechazada por SUNAT).
     * Si ya se devolvió antes con el mismo motivo, no hace nada (evita devolver dos veces).
     */
    public function revertirSalidas(Model $origen, ?int $userId, string $motivo): void
    {
        $filtro = fn () => MovimientoInventario::query()
            ->where('referencia_type', $origen->getMorphClass())
            ->where('referencia_id', $origen->getKey());

        if ($filtro()->where('tipo', 'entrada')->where('motivo', $motivo)->exists()) {
            return;
        }

        foreach ($filtro()->where('tipo', 'salida')->get() as $salida) {
            $lote = Lote::query()->lockForUpdate()->findOrFail($salida->lote_id);
            $lote->cantidad = (float) $lote->cantidad + (float) $salida->cantidad;
            $lote->save();

            $this->registrarMovimiento($lote, 'entrada', $motivo, (float) $salida->cantidad, $userId, $origen);
        }
    }

    /**
     * Descuenta stock siguiendo FEFO (primero vence, primero sale) y lo registra en el kárdex.
     *
     * - $cantidad: en unidad mínima.
     * - $soloEnteras: true cuando se vende por presentación (caja completa) de un producto
     *   fraccionable; así no se arma una caja con tabletas sueltas de lotes distintos.
     *
     * @return array<int, array{lote: Lote, cantidad: float}> lo que salió de cada lote
     */
    public function descontarFefo(Producto $producto, int $sucursalId, float $cantidad, bool $soloEnteras, ?int $userId, string $motivo, ?Model $referencia = null): array
    {
        $factor = $producto->factor();
        $lotes = $producto->lotesDisponibles($sucursalId)->lockForUpdate()->get();

        // Cuánto se puede tomar de cada lote
        $tomable = fn (Lote $l) => $soloEnteras && $factor > 1
            ? floor((float) $l->cantidad / $factor) * $factor
            : (float) $l->cantidad;

        $disponible = $lotes->sum($tomable);
        if ($disponible + 0.0001 < $cantidad) {
            $unidad = $soloEnteras || $factor === 1 ? $producto->unidad_venta : $producto->unidad_fraccion;
            $divisor = $soloEnteras ? $factor : 1;
            throw ValidationException::withMessages([
                'items' => sprintf(
                    'Stock insuficiente de %s: hay %s %s disponibles y pides %s.',
                    $producto->descripcionCompleta(),
                    rtrim(rtrim(number_format($disponible / $divisor, 2, '.', ''), '0'), '.'),
                    $unidad,
                    rtrim(rtrim(number_format($cantidad / $divisor, 2, '.', ''), '0'), '.'),
                ),
            ]);
        }

        $pendiente = $cantidad;
        $asignacion = [];

        foreach ($lotes as $lote) {
            if ($pendiente <= 0.0001) {
                break;
            }
            $tomar = min($tomable($lote), $pendiente);
            if ($tomar <= 0) {
                continue;
            }

            $lote->cantidad = (float) $lote->cantidad - $tomar;
            $lote->save();
            $this->registrarMovimiento($lote, 'salida', $motivo, $tomar, $userId, $referencia);

            $asignacion[] = ['lote' => $lote, 'cantidad' => $tomar];
            $pendiente = round($pendiente - $tomar, 4);
        }

        return $asignacion;
    }

    public function descontarDeLote(int $loteId, float $cantidad, ?int $userId, string $motivo, ?Model $referencia = null): Lote
    {
        $lote = Lote::query()->lockForUpdate()->findOrFail($loteId);

        if ((float) $lote->cantidad + 0.0001 < $cantidad) {
            throw ValidationException::withMessages([
                'items' => "El lote {$lote->numero_lote} solo tiene {$lote->cantidad} unidades y quieres sacar {$cantidad}.",
            ]);
        }

        $lote->cantidad = round((float) $lote->cantidad - $cantidad, 2);
        $lote->save();

        $this->registrarMovimiento($lote, 'salida', $motivo, $cantidad, $userId, $referencia);

        return $lote;
    }

    private function registrarMovimiento(Lote $lote, string $tipo, string $motivo, float $cantidad, ?int $userId, ?Model $referencia): void
    {
        MovimientoInventario::create([
            'producto_id' => $lote->producto_id,
            'lote_id' => $lote->id,
            'sucursal_id' => $lote->sucursal_id,
            'user_id' => $userId,
            'tipo' => $tipo,
            'motivo' => $motivo,
            'cantidad' => $cantidad,
            'saldo_lote' => $lote->cantidad,
            'costo_unitario' => $lote->costo_unitario,
            'referencia_type' => $referencia?->getMorphClass(),
            'referencia_id' => $referencia?->getKey(),
        ]);
    }
}