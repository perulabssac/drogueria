<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas por cobrar:
 * - comprobantes.saldo: lo que el cliente aún debe de una venta al crédito.
 * - comprobante_pagos.tipo: "venta" (pago al contado) o "cobranza" (abono de una venta al crédito).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->decimal('saldo', 12, 2)->default(0)->after('total')->index();
        });

        Schema::table('comprobante_pagos', function (Blueprint $table) {
            $table->string('tipo', 10)->default('venta')->after('user_id')->index();
        });

        // Las ventas al crédito ya emitidas empiezan debiendo su total
        DB::table('comprobantes')
            ->where('forma_pago', 'credito')
            ->where('estado', '!=', 'rechazado')
            ->update(['saldo' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('comprobante_pagos', function (Blueprint $table) {
            $table->dropIndex(['tipo']);
            $table->dropColumn('tipo');
        });

        Schema::table('comprobantes', function (Blueprint $table) {
            $table->dropIndex(['saldo']);
            $table->dropColumn('saldo');
        });
    }
};