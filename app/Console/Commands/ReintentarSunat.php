<?php

namespace App\Console\Commands;

use App\Models\Comprobante;
use App\Services\Sunat\SunatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reenvía a SUNAT lo que quedó sin respuesta (sin internet, SUNAT caído, etc.):
 * - "pendiente" o "error": se vuelve a enviar.
 * - "enviado" (boletas por resumen): se consulta el ticket.
 * Se ejecuta solo cada 10 minutos (ver routes/console.php) y también a mano.
 */
class ReintentarSunat extends Command
{
    protected $signature = 'sunat:reintentar {--limite=50 : Máximo de comprobantes por ejecución}';

    protected $description = 'Reenvía a SUNAT los comprobantes pendientes o con error y consulta los resúmenes en proceso';

    /** Tras tantos intentos fallidos se deja de insistir (hay que revisarlo a mano). */
    public const MAX_INTENTOS = 50;

    public function handle(SunatService $sunat): int
    {
        $comprobantes = Comprobante::query()
            ->whereIn('estado', ['pendiente', 'error', 'enviado'])
            ->where('tipo_comprobante', '!=', Comprobante::NOTA_VENTA)
            // Los recién emitidos los está enviando la pantalla de venta en este momento
            ->where('created_at', '<=', now()->subMinutes(2))
            ->where('intentos_envio', '<', self::MAX_INTENTOS)
            // Primero los más antiguos: así una factura se envía antes que su nota de crédito
            ->orderBy('id')
            ->limit((int) $this->option('limite'))
            ->get();

        if ($comprobantes->isEmpty()) {
            $this->info('No hay comprobantes pendientes de SUNAT.');

            return self::SUCCESS;
        }

        $resueltos = 0;
        foreach ($comprobantes as $comprobante) {
            $antes = $comprobante->estado;
            $sunat->enviar($comprobante);
            $comprobante->refresh();

            $this->line("{$comprobante->numero}: {$antes} → {$comprobante->estado}");
            if (in_array($comprobante->estado, ['aceptado', 'observado', 'rechazado'], true)) {
                $resueltos++;
            }
        }

        $mensaje = "Reintento SUNAT: {$resueltos} de {$comprobantes->count()} comprobante(s) con respuesta definitiva.";
        $this->info($mensaje);
        Log::info($mensaje);

        return self::SUCCESS;
    }
}