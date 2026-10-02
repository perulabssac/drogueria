<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            // Las boletas (y sus notas) se informan con un resumen diario: SUNAT responde con un ticket
            $table->string('resumen', 30)->nullable()->after('hash'); // ej. RC-20260930-3
            $table->string('ticket', 50)->nullable()->after('resumen');
        });
    }

    public function down(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->dropColumn(['resumen', 'ticket']);
        });
    }
};