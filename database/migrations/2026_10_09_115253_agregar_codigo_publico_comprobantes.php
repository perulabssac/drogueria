<?php

use App\Models\Comprobante;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Código aleatorio para el enlace público corto del comprobante: /c/K7mQ2xP9aR
     */
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->string('codigo_publico', 10)->nullable()->unique()->after('id');
        });

        // Los comprobantes que ya existen también reciben su código
        DB::table('comprobantes')->whereNull('codigo_publico')->select('id')->orderBy('id')
            ->chunkById(500, function ($filas) {
                foreach ($filas as $fila) {
                    DB::table('comprobantes')->where('id', $fila->id)->update(['codigo_publico' => Comprobante::nuevoCodigoPublico()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->dropUnique(['codigo_publico']);
            $table->dropColumn('codigo_publico');
        });
    }
};