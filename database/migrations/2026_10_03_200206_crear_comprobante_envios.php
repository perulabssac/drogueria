<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de envíos de comprobantes al cliente (correo y WhatsApp):
 * a quién, por dónde, quién lo envió y si llegó a salir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobante_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id')->constrained('comprobantes')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('canal', 10);          // correo | whatsapp
            $table->string('destino', 150);       // correo o número de celular
            $table->string('estado', 10);         // enviado | error
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobante_envios');
    }
};