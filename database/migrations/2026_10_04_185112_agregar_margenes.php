<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Margen de ganancia sobre el costo, elegido en cada producto (10 % a 100 %).
 * - productos.margen: margen del producto (null = aún no se le asignó).
 * - empresas.redondeo_precio: a cuánto se redondea hacia arriba el precio calculado (S/ 0.10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->decimal('margen', 6, 2)->nullable()->after('costo');
        });

        Schema::table('empresas', function (Blueprint $table) {
            $table->decimal('redondeo_precio', 4, 2)->default(0.10)->after('cuentas_bancarias');
        });
    }

    public function down(): void
    {
        Schema::table('productos', fn (Blueprint $table) => $table->dropColumn('margen'));
        Schema::table('empresas', fn (Blueprint $table) => $table->dropColumn('redondeo_precio'));
    }
};