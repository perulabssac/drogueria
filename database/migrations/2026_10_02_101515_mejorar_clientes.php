<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de clientes: datos de SUNAT/RENIEC, ubicación, contacto y estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('nombre_comercial')->nullable()->after('razon_social');
            $table->string('distrito', 80)->nullable()->after('direccion');
            $table->string('provincia', 80)->nullable()->after('distrito');
            $table->string('departamento', 80)->nullable()->after('provincia');
            $table->string('ubigeo', 6)->nullable()->after('departamento');
            $table->string('contacto', 120)->nullable()->after('telefono');     // persona de contacto en la botica
            // Estado del RUC en SUNAT (ACTIVO, BAJA DE OFICIO...) y condición (HABIDO, NO HABIDO...)
            $table->string('estado_sunat', 40)->nullable()->after('limite_credito');
            $table->string('condicion_sunat', 40)->nullable()->after('estado_sunat');
            $table->timestamp('verificado_at')->nullable()->after('condicion_sunat'); // última consulta a SUNAT/RENIEC
            $table->text('observaciones')->nullable()->after('verificado_at');
            $table->boolean('activo')->default(true)->after('observaciones');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn([
                'nombre_comercial', 'distrito', 'provincia', 'departamento', 'ubigeo', 'contacto',
                'estado_sunat', 'condicion_sunat', 'verificado_at', 'observaciones', 'activo',
            ]);
        });
    }
};