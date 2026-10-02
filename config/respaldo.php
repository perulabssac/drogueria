<?php

/*
 * Respaldo diario (comando: php artisan respaldo:crear).
 * Todo se configura en el .env para cambiarlo sin tocar el código.
 */
return [
    // Dónde se guardan los respaldos (en producción: otro disco, carpeta de Google Drive, etc.)
    'carpeta' => env('RESPALDO_CARPETA', storage_path('app/respaldos')),

    // Cuántos días se conservan; los más antiguos se borran solos
    'dias' => (int) env('RESPALDO_DIAS', 30),

    // Programa que exporta la base de datos (en Laragon y Linux basta "mysqldump")
    'mysqldump' => env('RESPALDO_MYSQLDUMP', 'mysqldump'),

    // Contraseña para cifrar los ZIP (AES-256). Vacía = sin cifrar. ¡Guárdala en un lugar seguro!
    'clave' => env('RESPALDO_CLAVE'),

    // Si se indica, se envía por correo una copia (solo la base de datos, cifrada)
    'correo' => env('RESPALDO_CORREO'),

    // Tamaño máximo del adjunto (Gmail acepta hasta 25 MB)
    'correo_max_mb' => (int) env('RESPALDO_CORREO_MAX_MB', 20),
];