<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos de cada venta: con qué medio se pagó (efectivo, Yape, Plin, transferencia...).
 * Una venta puede pagarse con varios medios (pago mixto). Más adelante, aquí mismo
 * se registrarán las cobranzas de las ventas al crédito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobante_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id')->constrained('comprobantes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('medio', 20);                        // efectivo, yape, plin, transferencia...
            $table->decimal('monto', 12, 2);                    // lo que se aplica a la venta
            $table->decimal('recibido', 12, 2)->nullable();     // efectivo entregado por el cliente
            $table->string('referencia', 50)->nullable();       // n° de operación / voucher
            $table->dateTime('fecha');
            $table->timestamps();

            $table->index(['medio', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobante_pagos');
    }
};