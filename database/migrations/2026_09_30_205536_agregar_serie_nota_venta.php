<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crea la serie NV01 (nota de venta, documento interno) en la sucursal principal.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sucursalId = DB::table('sucursales')->orderBy('id')->value('id');
        $existe = DB::table('series')->where('tipo_comprobante', 'NV')->where('serie', 'NV01')->exists();

        if ($sucursalId && ! $existe) {
            DB::table('series')->insert([
                'sucursal_id' => $sucursalId,
                'tipo_comprobante' => 'NV',
                'serie' => 'NV01',
                'correlativo' => 0,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('series')->where('tipo_comprobante', 'NV')->where('serie', 'NV01')->delete();
    }
};