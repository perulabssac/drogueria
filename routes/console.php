<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Tareas automáticas. Para que funcionen, el programador de tareas debe estar corriendo:
 * - Mientras desarrollas: php artisan schedule:work  (en una terminal aparte)
 * - En producción (Linux / DigitalOcean): * * * * * cd /ruta/drogueria && php artisan schedule:run
 */

// Reenvía a SUNAT lo que quedó pendiente o con error (sin internet, SUNAT caído...)
Schedule::command('sunat:reintentar')->everyTenMinutes()->withoutOverlapping();

// Respaldo diario a las 11:30 p. m. (hora de Perú), cuando ya no hay ventas
Schedule::command('respaldo:crear')
    ->dailyAt('23:30')
    ->timezone('America/Lima')
    ->withoutOverlapping();