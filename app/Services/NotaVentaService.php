<?php

namespace App\Services;

use App\Models\Caja;
use App\Models\Comprobante;
use App\Models\ComprobantePago;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anulación de notas de venta (documento interno, no va a SUNAT).
 * La nota no se borra: queda ANULADA con su número, motivo, usuario y fecha.
 * - La mercadería vuelve a sus lotes (kárdex: "Reversión (nota de venta anulada)").
 * - Al crédito: deja de ser deuda del cliente.
 * - Al contado: si la caja donde se cobró sigue abierta, el cierre ya no espera ese dinero
 *   (los comprobantes anulados no cuentan); hay que devolverlo desde esa caja.
 *   Si esa caja ya se cerró, la devolución sale como egreso de la caja abierta de quien anula.
 */
class NotaVentaService
{
    public function __construct(
        private InventarioService $inventario,
        private CajaService $cajas,
    ) {}

    /** Anula la nota y devuelve el mensaje para el usuario. */
    public function anular(Comprobante $nota, string $motivo, User $usuario): string
    {
        return DB::transaction(function () use ($nota, $motivo, $usuario) {
            $nota = Comprobante::query()->lockForUpdate()->findOrFail($nota->id);

            if ($impedimento = $nota->impedimentoAnulacionNota()) {
                throw ValidationException::withMessages(['motivo' => $impedimento]);
            }

            $avisos = $this->devolverDinero($nota, $usuario);

            $nota->update([
                'estado' => 'anulado',
                'saldo' => 0, // ya no es deuda del cliente
                'baja_motivo' => $motivo,
                'baja_user_id' => $usuario->id,
                'baja_at' => now(),
            ]);

            $this->inventario->revertirSalidas($nota, $usuario->id, 'anulacion_nota_venta');

            return trim("Nota de venta {$nota->numero} anulada. La mercadería volvió al stock. ".implode(' ', $avisos));
        });
    }

    /**
     * Pagos al contado de la nota: qué pasa con el dinero cobrado.
     *
     * @return array<int, string> avisos para el usuario
     */
    private function devolverDinero(Comprobante $nota, User $usuario): array
    {
        $avisos = [];
        $pagos = $nota->pagos()->where('tipo', ComprobantePago::TIPO_VENTA)->with('caja.usuario:id,name')->get();

        foreach ($pagos->groupBy('caja_id') as $pagosCaja) {
            /** @var Caja|null $caja */
            $caja = $pagosCaja->first()->caja;
            $total = round((float) $pagosCaja->sum('monto'), 2);

            if ($caja && $caja->estaAbierta()) {
                // El cierre de esa caja ya no espera este dinero: se devuelve al cliente desde ahí
                $cajero = $caja->usuario?->name ?? 'el cajero';
                $avisos[] = 'Devuelve S/ '.number_format($total, 2)." al cliente desde la caja de {$cajero}.";

                continue;
            }

            // La caja ya se cerró (la venta quedó en su cierre): la devolución sale de la caja abierta de quien anula
            $abierta = $this->cajas->requerirAbierta($usuario, 'motivo');
            foreach ($pagosCaja->groupBy('medio') as $medio => $pagosMedio) {
                $this->cajas->registrarMovimiento(
                    $abierta,
                    $usuario,
                    'egreso',
                    (float) $pagosMedio->sum('monto'),
                    "Devolución por anulación de {$nota->numero}",
                    $medio,
                    $nota,
                );
            }
            $avisos[] = 'Se registró en tu caja un egreso de S/ '.number_format($total, 2).' por la devolución al cliente.';
        }

        return $avisos;
    }
}
