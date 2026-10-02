<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Toma de inventario física: se "congela" el stock de los lotes, se cuenta lo que hay en el almacén
 * y, al aprobarla, las diferencias se corrigen con ajustes (faltante / sobrante en conteo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tomas_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->foreignId('user_id')->constrained('users');               // quién la abrió
            $table->string('alcance', 15)->default('todo');                    // todo | laboratorio | categoria
            $table->string('alcance_valor')->nullable();                       // id del laboratorio o nombre de la categoría
            $table->string('alcance_texto')->default('Todo el almacén');       // para mostrar
            $table->string('estado', 15)->default('en_conteo')->index();       // en_conteo | aprobada | anulada
            $table->text('observacion')->nullable();
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->timestamp('aprobada_at')->nullable();
            $table->foreignId('ajuste_salida_id')->nullable()->constrained('ajustes');
            $table->foreignId('ajuste_entrada_id')->nullable()->constrained('ajustes');
            $table->timestamps();
        });

        Schema::create('toma_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('toma_id')->constrained('tomas_inventario')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('lote_id')->nullable()->constrained('lotes');   // null = lote encontrado que no estaba en el sistema
            $table->string('numero_lote', 50);
            $table->date('fecha_vencimiento');
            $table->decimal('stock_sistema', 12, 2)->default(0);               // al abrir la toma, en unidad mínima
            $table->decimal('contado', 12, 2)->nullable();                     // null = aún no contado
            $table->decimal('costo_unitario', 12, 4)->default(0);              // por presentación, sin IGV
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toma_items');
        Schema::dropIfExists('tomas_inventario');
    }
};