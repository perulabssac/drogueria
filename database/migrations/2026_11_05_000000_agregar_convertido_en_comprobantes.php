<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota de venta convertida en boleta o factura: guarda a qué comprobante se convirtió
 * (ej. NV01-45 -> F001-230). Así se ven enlazadas en ambas pantallas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->foreignId('convertido_en_id')->nullable()->after('comprobante_referencia_id')
                ->constrained('comprobantes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('convertido_en_id');
        });
    }
};
