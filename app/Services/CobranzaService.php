<?php

namespace App\Services;

use App\Models\CajaMovimiento;
use App\Models\Comprobante;
use App\Models\ComprobantePago;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cuentas por cobrar: saldo de las ventas al crédito, estado de sus cuotas y registro de cobros.
 * Lo cobrado entra a la caja abierta de quien cobra.
 */
class CobranzaService
{
    public function __construct(private CajaService $cajas) {}

    /**
     * Total − lo cobrado − notas de crédito + dinero devuelto por esas notas.
     * Positivo = el cliente debe; negativo = el cliente tiene dinero a favor.
     */
    public static function balance(Comprobante $comprobante): float
    {
        // Consultas directas (sin el orderBy de las relaciones, que MySQL no acepta con SUM)
        $cobrado = (float) ComprobantePago::query()->where('comprobante_id', $comprobante->id)->sum('monto');

        $notas = Comprobante::query()
            ->where('comprobante_referencia_id', $comprobante->id)
            ->where('estado', '!=', 'rechazado')
            ->get(['id', 'total']);

        $devuelto = $notas->isEmpty() ? 0.0 : (float) CajaMovimiento::query()
            ->where('origen_type', $comprobante->getMorphClass())
            ->whereIn('origen_id', $notas->pluck('id'))
            ->where('tipo', 'egreso')
            ->sum('monto');

        return round((float) $comprobante->total - $cobrado - (float) $notas->sum('total') + $devuelto, 2);
    }

    /** Recalcula y guarda el saldo. Solo las ventas al crédito válidas tienen deuda. */
    public static function recalcularSaldo(Comprobante $comprobante): float
    {
        $saldo = $comprobante->esCredito() && $comprobante->estado !== 'rechazado'
            ? max(0, self::balance($comprobante))
            : 0;

        $comprobante->update(['saldo' => $saldo]);

        return $saldo;
    }

    /** Motivo por el que no se puede cobrar este comprobante (null si se puede). */
    public static function impedimento(Comprobante $comprobante, User $usuario): ?string
    {
        if (! $comprobante->esCredito()) {
            return 'Esta venta fue al contado: no tiene saldo por cobrar.';
        }
        if ($comprobante->estado === 'rechazado') {
            return 'SUNAT rechazó este comprobante: no genera deuda. Emite una nueva venta.';
        }
        if ((int) $comprobante->sucursal_id !== (int) $usuario->sucursal_id && ! $usuario->tieneRol('admin')) {
            return 'Este comprobante es de otra sucursal.';
        }

        return null;
    }

    /**
     * Estado de cada cuota: lo cobrado (y lo rebajado con notas de crédito) se aplica
     * a las cuotas en orden, de la más antigua a la más nueva.
     */
    public static function cuotas(Comprobante $comprobante): array
    {
        $comprobante->loadMissing('cuotas');
        $aplicado = round((float) $comprobante->total - (float) $comprobante->saldo, 2);
        $hoy = today();

        return $comprobante->cuotas->map(function ($cuota) use (&$aplicado, $hoy) {
            $monto = (float) $cuota->monto;
            $pagado = round(min($monto, max(0, $aplicado)), 2);
            $aplicado = round($aplicado - $pagado, 2);
            $pendiente = round($monto - $pagado, 2);

            $estado = match (true) {
                $pendiente <= 0 => 'pagada',
                $cuota->fecha_vencimiento->lt($hoy) => 'vencida',
                $pagado > 0 => 'parcial',
                default => 'pendiente',
            };

            return [
                'id' => $cuota->id,
                'numero' => $cuota->numero,
                'monto' => $monto,
                'fecha_vencimiento' => $cuota->fecha_vencimiento->toDateString(),
                'pagado' => $pagado,
                'pendiente' => $pendiente,
                'estado' => $estado,
                'dias' => (int) $hoy->diffInDays($cuota->fecha_vencimiento, false), // negativo = días de atraso
            ];
        })->all();
    }

    /**
     * Registra un cobro (abono) de una venta al crédito. Puede ser parcial.
     *
     * @param  array  $datos  monto, medio, recibido (efectivo), referencia (n° de operación)
     */
    public function cobrar(Comprobante $comprobante, array $datos, User $usuario): ComprobantePago
    {
        return DB::transaction(function () use ($comprobante, $datos, $usuario) {
            // Se bloquea la venta para que dos cajeros no cobren lo mismo a la vez
            $comprobante = Comprobante::query()->lockForUpdate()->findOrFail($comprobante->id);

            if ($error = self::impedimento($comprobante, $usuario)) {
                throw ValidationException::withMessages(['monto' => $error]);
            }

            $saldo = self::recalcularSaldo($comprobante);
            if ($saldo <= 0) {
                throw ValidationException::withMessages(['monto' => 'Esta venta ya está cancelada.']);
            }

            $monto = round((float) $datos['monto'], 2);
            if ($monto > $saldo + 0.009) {
                throw ValidationException::withMessages([
                    'monto' => 'El saldo pendiente es S/ '.number_format($saldo, 2).': no puedes cobrar más que eso.',
                ]);
            }

            $caja = $this->cajas->requerirAbierta($usuario, 'monto');

            $efectivo = $datos['medio'] === 'efectivo';
            $recibido = $efectivo && ! empty($datos['recibido']) ? round((float) $datos['recibido'], 2) : null;
            if ($recibido !== null && $recibido < $monto) {
                throw ValidationException::withMessages(['recibido' => 'El efectivo recibido no alcanza para cubrir el cobro.']);
            }

            $pago = $comprobante->pagos()->create([
                'caja_id' => $caja->id,
                'user_id' => $usuario->id,
                'tipo' => ComprobantePago::TIPO_COBRANZA,
                'medio' => $datos['medio'],
                'monto' => $monto,
                'recibido' => $recibido,
                'referencia' => $efectivo ? null : ($datos['referencia'] ?? null),
                'fecha' => now(),
            ]);

            self::recalcularSaldo($comprobante);

            return $pago;
        });
    }
}