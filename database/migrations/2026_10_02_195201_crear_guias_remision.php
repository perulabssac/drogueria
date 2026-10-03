<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guía de remisión electrónica (remitente, tipo 09): traslado de mercadería a los clientes.
 * Se envía a SUNAT por su API REST y la respuesta llega con un ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('comprobante_id')->nullable()->constrained('comprobantes'); // factura que sustenta el traslado
            $table->string('serie', 4);
            $table->unsignedInteger('correlativo');
            $table->dateTime('fecha_emision');
            $table->date('fecha_traslado');
            $table->string('motivo', 2)->default('01');          // catálogo 20: 01 venta, 04 entre establecimientos, 13 otros...
            $table->string('motivo_descripcion')->nullable();
            $table->string('modalidad', 2)->default('01');       // catálogo 18: 01 transporte público, 02 privado
            // Destinatario (normalmente el cliente)
            $table->string('destinatario_tipo_doc', 1);
            $table->string('destinatario_num_doc', 15);
            $table->string('destinatario_nombre');
            // Puntos de partida y llegada
            $table->string('partida_ubigeo', 6);
            $table->string('partida_direccion');
            $table->string('llegada_ubigeo', 6);
            $table->string('llegada_direccion');
            $table->decimal('peso_total', 12, 3);
            $table->string('unidad_peso', 3)->default('KGM');
            // Transporte público: la empresa de transportes
            $table->string('transportista_ruc', 11)->nullable();
            $table->string('transportista_nombre')->nullable();
            $table->string('transportista_mtc', 20)->nullable();
            // Transporte privado: vehículo y conductor propios
            $table->string('vehiculo_placa', 10)->nullable();
            $table->string('conductor_tipo_doc', 1)->nullable();
            $table->string('conductor_num_doc', 15)->nullable();
            $table->string('conductor_nombres')->nullable();
            $table->string('conductor_apellidos')->nullable();
            $table->string('conductor_licencia', 20)->nullable();
            $table->text('observaciones')->nullable();
            // SUNAT: pendiente | enviado (con ticket) | aceptado | rechazado | error
            $table->string('estado', 15)->default('pendiente')->index();
            $table->string('ticket', 60)->nullable();
            $table->string('sunat_codigo', 10)->nullable();
            $table->text('sunat_descripcion')->nullable();
            $table->text('enlace_qr')->nullable();               // enlace que SUNAT devuelve en el CDR
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->unsignedSmallInteger('intentos_envio')->default(0);
            $table->timestamp('enviado_at')->nullable();
            $table->timestamps();
            $table->unique(['serie', 'correlativo']);
        });

        Schema::create('guia_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guia_id')->constrained('guias')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos');
            $table->string('codigo', 30)->nullable();
            $table->string('descripcion');                       // incluye lote y vencimiento
            $table->string('unidad', 3)->default('NIU');         // catálogo 03 (BX, NIU...)
            $table->decimal('cantidad', 12, 2);
            $table->timestamps();
        });

        // Serie T001 para las guías en cada local (se puede cambiar en Configuración)
        foreach (DB::table('sucursales')->pluck('id') as $sucursalId) {
            $existe = DB::table('series')->where('tipo_comprobante', '09')->where('sucursal_id', $sucursalId)->exists();
            if (! $existe && ! DB::table('series')->where('tipo_comprobante', '09')->where('serie', 'T001')->exists()) {
                DB::table('series')->insert([
                    'sucursal_id' => $sucursalId,
                    'tipo_comprobante' => '09',
                    'serie' => 'T001',
                    'correlativo' => 0,
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('guia_items');
        Schema::dropIfExists('guias');
        DB::table('series')->where('tipo_comprobante', '09')->where('correlativo', 0)->delete();
    }
};