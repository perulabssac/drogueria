<?php

namespace App\Services;

use App\Models\Caja;
use App\Models\Compra;
use App\Models\CompraPago;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cuentas por pagar: pagos (totales o parciales) a proveedores por compras al crédito.
 * Si se paga en efectivo desde la caja, el dinero sale de la caja como egreso.
 */
class CuentaPagarService
{
    public function __construct(private CajaService $cajas) {}

    /**
     * @param  array  $datos  fecha, medio, monto, referencia, observacion, desde_caja
     */
    public function registrarPago(Compra $compra, array $datos, User $usuario): CompraPago
    {
        return DB::transaction(function () use ($compra, $datos, $usuario) {
            // Se bloquea la compra para que dos pagos a la vez no dejen un saldo negativo
            $compra = Compra::query()->with('proveedor:id,razon_social')->lockForUpdate()->findOrFail($compra->id);
            $monto = round((float) $datos['monto'], 2);

            if ($compra->estado === 'anulada') {
                throw ValidationException::withMessages(['monto' => 'La compra está anulada: no tiene deuda.']);
            }
            if (! $compra->esCredito()) {
                throw ValidationException::withMessages(['monto' => 'Es una compra al contado: no tiene deuda pendiente.']);
            }
            if ((float) $compra->saldo <= 0) {
                throw ValidationException::withMessages(['monto' => 'Esta compra ya está pagada.']);
            }
            if ($monto > (float) $compra->saldo + 0.009) {
                throw ValidationException::withMessages(['monto' => 'El pago (S/ '.number_format($monto, 2).') supera el saldo de S/ '.number_format((float) $compra->saldo, 2).'.']);
            }
            if ($datos['fecha'] < $compra->fecha_emision->toDateString()) {
                throw ValidationException::withMessages(['fecha' => 'El pago no puede ser anterior a la fecha de la compra.']);
            }

            // Efectivo que sale de la caja del usuario (si no, se asume banco o caja chica aparte)
            $caja = null;
            if ($datos['medio'] === 'efectivo' && ! empty($datos['desde_caja'])) {
                $caja = $this->cajas->requerirAbierta($usuario, 'desde_caja');
            }

            $pago = $compra->pagos()->create([
                'user_id' => $usuario->id,
                'caja_id' => $caja?->id,
                'fecha' => $datos['fecha'],
                'medio' => $datos['medio'],
                'monto' => $monto,
                'referencia' => $datos['referencia'] ?? null,
                'observacion' => $datos['observacion'] ?? null,
                'estado' => 'activo',
            ]);

            if ($caja) {
                $this->cajas->registrarMovimiento(
                    $caja, $usuario, 'egreso', $monto,
                    "Pago a proveedor {$compra->proveedor->razon_social} - {$compra->documento}", 'efectivo', $pago,
                );
            }

            $compra->update(['saldo' => round((float) $compra->saldo - $monto, 2)]);

            return $pago;
        });
    }

    /**
     * Anula un pago mal registrado: la deuda vuelve a subir. Si el efectivo salió de una caja
     * que sigue abierta, se devuelve a esa caja; si ya se cerró, se avisa para ajustarlo a mano.
     */
    public function anularPago(CompraPago $pago, User $usuario): string
    {
        return DB::transaction(function () use ($pago, $usuario) {
            $pago = CompraPago::query()->lockForUpdate()->findOrFail($pago->id);
            $compra = Compra::query()->with('proveedor:id,razon_social')->lockForUpdate()->findOrFail($pago->compra_id);

            if ($pago->estado === 'anulado') {
                throw ValidationException::withMessages(['pago' => 'Ese pago ya está anulado.']);
            }

            $pago->update(['estado' => 'anulado', 'anulado_por' => $usuario->id, 'anulado_at' => now()]);
            $compra->update(['saldo' => min((float) $compra->total, round((float) $compra->saldo + (float) $pago->monto, 2))]);

            $aviso = '';
            if ($pago->caja_id) {
                $caja = Caja::find($pago->caja_id);
                if ($caja?->estaAbierta()) {
                    $this->cajas->registrarMovimiento(
                        $caja, $usuario, 'ingreso', (float) $pago->monto,
                        "Anulación de pago a {$compra->proveedor->razon_social} - {$compra->documento}", 'efectivo', $pago,
                    );
                } else {
                    $aviso = ' El efectivo salió de una caja ya cerrada: regístralo como ingreso en tu caja si el dinero volvió.';
                }
            }

            return "Pago anulado: la compra {$compra->documento} vuelve a deber S/ ".number_format((float) $compra->saldo, 2).'.'.$aviso;
        });
    }
}