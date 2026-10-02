<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes de inventario: corrigen el stock con un motivo (rotura, vencido, faltante, sobrante...).
 * Cada ajuste es un documento (AJ-000001) con su acta, y sus líneas quedan en el kárdex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->foreignId('user_id')->constrained('users');      // quién lo registró
            $table->string('tipo', 10);                               // entrada | salida
            $table->string('motivo', 30);                             // rotura, vencimiento, faltante, sobrante...
            $table->text('observacion');                              // justificación (obligatoria)
            $table->decimal('valor', 12, 2)->default(0);              // valor a costo, sin IGV
            $table->timestamps();
        });

        Schema::create('ajuste_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ajuste_id')->constrained('ajustes')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('lote_id')->constrained('lotes');
            $table->string('numero_lote', 50);                        // copia para el acta
            $table->date('fecha_vencimiento');                        // copia para el acta
            $table->decimal('cantidad', 12, 2);                       // en unidad mínima, como los lotes
            $table->decimal('costo_unitario', 12, 4)->default(0);     // por presentación, sin IGV
            $table->decimal('valor', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajuste_items');
        Schema::dropIfExists('ajustes');
    }
};