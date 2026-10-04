<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importaciones de productos y stock desde Excel (carga inicial del catálogo).
 * Cada importación queda registrada y sus ingresos al kárdex apuntan a ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->foreignId('user_id')->constrained('users');
            $table->string('archivo', 150);                       // nombre del Excel subido
            $table->unsignedInteger('productos_nuevos')->default(0);
            $table->unsignedInteger('productos_actualizados')->default(0);
            $table->unsignedInteger('lotes')->default(0);
            $table->decimal('valor', 14, 2)->default(0);          // costo total del stock ingresado
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importaciones');
    }
};