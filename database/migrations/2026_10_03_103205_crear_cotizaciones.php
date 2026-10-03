<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotizaciones (proformas): precios que se le ofrecen a un cliente, con fecha de validez.
 * No mueven stock ni van a SUNAT. Si el cliente acepta, se convierten en venta con un clic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->id();                                              // COT-000001
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->foreignId('user_id')->constrained('users');        // quién la registró
            $table->foreignId('vendedor_id')->nullable()->constrained('users');
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->date('fecha');
            $table->unsignedSmallInteger('validez_dias')->default(7);
            $table->date('fecha_vencimiento');
            $table->string('forma_pago', 10)->default('contado');      // contado | credito
            $table->string('condiciones', 500)->nullable();            // entrega, forma de pago, etc.
            $table->string('observaciones', 500)->nullable();
            $table->decimal('op_gravadas', 12, 2)->default(0);
            $table->decimal('op_exoneradas', 12, 2)->default(0);
            $table->decimal('op_gratuitas', 12, 2)->default(0);
            $table->decimal('igv', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('estado', 15)->default('pendiente');        // pendiente | vendida | anulada (vencida se calcula)
            $table->foreignId('comprobante_id')->nullable()->constrained('comprobantes'); // venta que la cerró
            $table->timestamps();

            $table->index(['sucursal_id', 'estado']);
        });

        Schema::create('cotizacion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->string('descripcion', 250);                        // copia para la impresión
            $table->string('unidad', 30);                              // CAJA, FRASCO, TABLETA...
            $table->decimal('cantidad', 12, 2);
            $table->boolean('por_fraccion')->default(false);           // vendido por unidad suelta
            $table->decimal('precio_unitario', 12, 4)->default(0);     // con IGV
            $table->boolean('bonificacion')->default(false);           // se entrega gratis
            $table->decimal('importe', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_items');
        Schema::dropIfExists('cotizaciones');
    }
};