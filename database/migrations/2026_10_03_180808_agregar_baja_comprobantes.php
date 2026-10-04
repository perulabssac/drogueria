<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comunicación de baja: anula ante SUNAT una factura o boleta ya aceptada (dentro de 7 días).
 * - Facturas: Comunicación de Baja (RA-AAAAMMDD-n).
 * - Boletas: resumen diario con estado "anulado" (RC-AAAAMMDD-n).
 * Cuando SUNAT la acepta, el comprobante pasa a estado "anulado" y pierde validez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->string('baja_estado', 15)->nullable()->after('enviado_at');   // enviada | aceptada | rechazada | error
            $table->string('baja_motivo', 100)->nullable()->after('baja_estado');
            $table->string('baja_documento', 30)->nullable()->after('baja_motivo'); // RA-20261003-1 o RC-20261003-2
            $table->string('baja_ticket', 50)->nullable()->after('baja_documento');
            $table->string('baja_codigo', 10)->nullable()->after('baja_ticket');
            $table->text('baja_descripcion')->nullable()->after('baja_codigo');
            $table->string('baja_cdr_path')->nullable()->after('baja_descripcion');
            $table->foreignId('baja_user_id')->nullable()->after('baja_cdr_path')->constrained('users');
            $table->timestamp('baja_at')->nullable()->after('baja_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('baja_user_id');
            $table->dropColumn(['baja_estado', 'baja_motivo', 'baja_documento', 'baja_ticket', 'baja_codigo', 'baja_descripcion', 'baja_cdr_path', 'baja_at']);
        });
    }
};