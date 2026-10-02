<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * En una nota de crédito, cada línea apunta a la línea de la factura/boleta que devuelve.
 * Así se sabe cuánto queda por devolver de cada producto y lote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobante_items', function (Blueprint $table) {
            $table->foreignId('item_referencia_id')->nullable()->after('comprobante_id')
                ->constrained('comprobante_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('comprobante_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_referencia_id');
        });
    }
};