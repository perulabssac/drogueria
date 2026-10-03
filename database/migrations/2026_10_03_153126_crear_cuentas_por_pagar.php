<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas por pagar: lo que se le debe a cada proveedor por las compras al crédito.
 * - compras.saldo: lo que falta pagar de la compra (0 si es al contado o ya se pagó).
 * - compra_pagos: cada pago (o abono parcial) hecho al proveedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->decimal('saldo', 12, 2)->default(0)->after('total')->index();
        });

        // Las compras al crédito ya registradas quedan debiendo su total
        DB::table('compras')
            ->where('forma_pago', 'credito')
            ->where('estado', 'registrada')
            ->update(['saldo' => DB::raw('total')]);

        Schema::create('compra_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras');
            $table->foreignId('user_id')->constrained('users');             // quién registró el pago
            $table->foreignId('caja_id')->nullable()->constrained('cajas'); // si el efectivo salió de una caja
            $table->date('fecha');
            $table->string('medio', 20);                                    // efectivo, transferencia, depósito...
            $table->decimal('monto', 12, 2);
            $table->string('referencia', 50)->nullable();                   // N° de operación o de cheque
            $table->string('observacion', 250)->nullable();
            $table->string('estado', 10)->default('activo');                // activo | anulado
            $table->foreignId('anulado_por')->nullable()->constrained('users');
            $table->timestamp('anulado_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_pagos');
        Schema::table('compras', function (Blueprint $table) {
            $table->dropIndex(['saldo']);
            $table->dropColumn('saldo');
        });
    }
};