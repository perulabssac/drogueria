<?php

namespace App\Console\Commands;

use App\Models\Comprobante;
use App\Services\Sunat\SunatService;
use Illuminate\Console\Command;

/**
 * Devuelve a sus lotes el stock de los comprobantes rechazados por SUNAT
 * que aún no lo tenían devuelto. Se puede ejecutar varias veces sin riesgo.
 */
class DevolverStockRechazados extends Command
{
    protected $signature = 'sunat:devolver-rechazados';

    protected $description = 'Devuelve el stock de los comprobantes rechazados por SUNAT';

    public function handle(SunatService $sunat): int
    {
        $rechazados = Comprobante::where('estado', 'rechazado')->get();

        foreach ($rechazados as $comprobante) {
            $sunat->devolverStockSiRechazado($comprobante);
            $this->info("{$comprobante->numero}: stock devuelto.");
        }

        $this->info($rechazados->isEmpty() ? 'No hay comprobantes rechazados.' : 'Listo.');

        return self::SUCCESS;
    }
}