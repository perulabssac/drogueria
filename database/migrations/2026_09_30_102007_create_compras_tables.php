<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('ruc', 11)->unique();
            $table->string('razon_social');
            $table->string('direccion')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->string('contacto')->nullable(); // nombre del vendedor o representante
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Documento del proveedor tal como llegó (factura o boleta de compra)
        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('sucursal_id')->constrained('sucursales'); // almacén donde ingresa
            $table->foreignId('user_id')->constrained('users');
            $table->string('tipo_documento', 2)->default('01'); // 01 factura, 03 boleta
            $table->string('serie', 4);
            $table->string('numero', 10);
            $table->date('fecha_emision');
            $table->string('forma_pago', 10)->default('contado'); // contado | credito
            $table->date('fecha_vencimiento')->nullable();         // fecha de pago si es crédito
            $table->string('moneda', 3)->default('PEN');
            $table->decimal('op_gravadas', 12, 2)->default(0);
            $table->decimal('op_exoneradas', 12, 2)->default(0);
            $table->decimal('igv', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('observaciones')->nullable();
            $table->string('estado', 15)->default('registrada'); // registrada | anulada
            $table->timestamps();
            // Evita registrar dos veces la misma factura del mismo proveedor
            $table->unique(['proveedor_id', 'tipo_documento', 'serie', 'numero']);
        });

        Schema::create('compra_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('lote_id')->nullable()->constrained('lotes');
            $table->string('numero_lote', 50);
            $table->date('fecha_vencimiento');
            $table->decimal('cantidad', 12, 2);                 // en presentaciones (cajas, frascos...)
            $table->boolean('bonificacion')->default(false);    // llegó gratis
            $table->decimal('precio_unitario', 12, 3)->default(0); // con IGV, como en la factura
            $table->string('tipo_afectacion_igv', 2)->default('10');
            $table->decimal('valor', 12, 2)->default(0);        // sin IGV
            $table->decimal('igv', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);        // importe de la línea
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_items');
        Schema::dropIfExists('compras');
        Schema::dropIfExists('proveedores');
    }
};