<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría: quién hizo qué, cuándo y desde dónde.
 * Solo se agregan registros: nunca se editan ni se borran desde el sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->string('evento', 30);                 // creado | actualizado | eliminado | login | logout | login_fallido
            $table->string('modulo', 40);                 // Producto, Cliente, Usuario, Sesión...
            $table->nullableMorphs('auditable');          // registro afectado
            $table->string('descripcion', 255);
            $table->json('cambios')->nullable();          // {campo: [antes, después]}
            $table->string('ip', 45)->nullable();
            $table->string('navegador', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['modulo', 'evento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditorias');
    }
};