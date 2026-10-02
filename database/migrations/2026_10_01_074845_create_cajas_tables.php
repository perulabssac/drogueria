<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caja por usuario (turno): apertura con monto inicial, ingresos/egresos y cierre con arqueo.
 * Los pagos de las ventas al contado quedan asociados a la caja de quien cobró.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->foreignId('user_id')->constrained('users');          // cajero
            $table->string('estado', 10)->default('abierta')->index();    // abierta | cerrada
            $table->dateTime('abierta_at');
            $table->dateTime('cerrada_at')->nullable();
            $table->decimal('monto_inicial', 12, 2)->default(0);
            // Arqueo (se guarda al cerrar, para que el reporte no cambie después)
            $table->decimal('efectivo_esperado', 12, 2)->nullable();
            $table->decimal('efectivo_contado', 12, 2)->nullable();
            $table->decimal('diferencia', 12, 2)->nullable();             // + sobrante / - faltante
            $table->json('conteo')->nullable();                           // billetes y monedas contados
            $table->json('resumen')->nullable();                          // totales por medio al cerrar
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'estado']);
        });

        Schema::create('caja_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('tipo', 10);                 // ingreso | egreso
            $table->string('medio', 20)->default('efectivo');
            $table->decimal('monto', 12, 2);
            $table->string('concepto', 150);
            $table->nullableMorphs('origen');           // ej. la nota de crédito que devolvió dinero
            $table->timestamps();
        });

        Schema::table('comprobante_pagos', function (Blueprint $table) {
            $table->foreignId('caja_id')->nullable()->after('comprobante_id')->constrained('cajas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('comprobante_pagos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caja_id');
        });
        Schema::dropIfExists('caja_movimientos');
        Schema::dropIfExists('cajas');
    }
};