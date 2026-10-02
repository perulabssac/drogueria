<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin | vendedor | almacen
            $table->string('rol', 20)->default('vendedor')->after('email');
            $table->boolean('activo')->default(true)->after('rol');
        });

        // Datos del emisor (una sola fila)
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('ruc', 11);
            $table->string('razon_social');
            $table->string('nombre_comercial')->nullable();
            $table->string('direccion');
            $table->string('ubigeo', 6)->default('150101');
            $table->string('departamento')->default('LIMA');
            $table->string('provincia')->default('LIMA');
            $table->string('distrito')->default('LIMA');
            $table->string('urbanizacion')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->text('cuentas_bancarias')->nullable(); // se imprimen en la factura
            // Conexión SUNAT
            $table->string('entorno', 10)->default('beta'); // beta | produccion
            $table->string('sol_usuario')->nullable();
            $table->text('sol_clave')->nullable(); // se guarda cifrada
            $table->string('certificado_path')->nullable();
            $table->date('certificado_vence')->nullable();
            $table->timestamps();
        });

        Schema::create('sucursales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('direccion')->nullable();
            $table->string('codigo_establecimiento', 4)->default('0000'); // código anexo SUNAT
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('activo')->constrained('sucursales');
        });

        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->string('tipo_comprobante', 2); // 01 factura, 03 boleta, 07 NC, 08 ND
            $table->string('serie', 4);
            $table->unsignedInteger('correlativo')->default(0); // último emitido
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['serie', 'tipo_comprobante']);
        });

        Schema::create('laboratorios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->timestamps();
        });

        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('codigo_barras', 50)->nullable()->index();
            $table->string('nombre');
            $table->string('principio_activo')->nullable()->index(); // DCI
            $table->string('concentracion')->nullable();              // 500 mg
            $table->string('forma_farmaceutica')->nullable();         // Tableta, Jarabe...
            $table->string('presentacion')->nullable();               // Caja x 100 tabletas
            $table->string('categoria', 60)->nullable()->index();     // Antibióticos, Analgésicos...
            $table->foreignId('laboratorio_id')->nullable()->constrained('laboratorios');
            $table->string('registro_sanitario', 30)->nullable();     // DIGEMID
            $table->string('condicion_venta', 20)->default('sin_receta'); // sin_receta | con_receta | receta_retenida
            $table->boolean('controlado')->default(false);            // estupefaciente / psicotrópico
            $table->boolean('cadena_frio')->default(false);           // requiere refrigeración (2 a 8 °C)
            $table->string('tipo_afectacion_igv', 2)->default('10');  // catálogo 07: 10 gravado, 20 exonerado
            // Venta por presentación (mayorista)
            $table->string('unidad_venta', 5)->default('CJA');        // CJA, FCO, AMP, TBO, SOB...
            $table->string('unidad_sunat', 3)->default('BX');         // catálogo 03 (se calcula de unidad_venta)
            $table->decimal('precio_venta', 12, 3)->default(0);       // precio por presentación, con IGV
            // Venta fraccionada (opcional)
            $table->boolean('fraccionable')->default(false);
            $table->unsignedInteger('unidades_por_presentacion')->default(1); // 100 si es caja x 100
            $table->string('unidad_fraccion', 5)->nullable();         // TAB, CAP...
            $table->decimal('precio_fraccion', 12, 3)->nullable();    // precio por unidad suelta, con IGV
            $table->decimal('costo', 12, 4)->default(0);              // costo por presentación
            $table->unsignedInteger('stock_minimo')->default(0);      // en presentaciones
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->string('numero_lote', 50);
            $table->date('fecha_vencimiento')->index();
            // Siempre en la unidad mínima: presentaciones, o unidades sueltas si el producto es fraccionable
            $table->decimal('cantidad', 12, 2)->default(0);
            $table->decimal('costo_unitario', 12, 4)->default(0); // costo por presentación
            $table->timestamps();
            $table->unique(['producto_id', 'sucursal_id', 'numero_lote']);
        });

        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_documento', 1); // catálogo 06: 0 sin doc, 1 DNI, 4 CE, 6 RUC, 7 pasaporte
            $table->string('numero_documento', 15);
            $table->string('razon_social');
            $table->string('direccion')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('email')->nullable();
            $table->string('telefono')->nullable();
            $table->unsignedSmallInteger('dias_credito')->default(0); // 0 = solo contado
            $table->decimal('limite_credito', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['tipo_documento', 'numero_documento']);
        });

        Schema::create('comprobantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->foreignId('user_id')->constrained('users');          // quién lo registró
            $table->foreignId('vendedor_id')->nullable()->constrained('users');
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->string('tipo_comprobante', 2);
            $table->string('serie', 4);
            $table->unsignedInteger('correlativo');
            $table->dateTime('fecha_emision');
            $table->string('moneda', 3)->default('PEN');
            $table->string('forma_pago', 10)->default('contado'); // contado | credito
            $table->date('fecha_vencimiento')->nullable();           // último pago si es crédito
            $table->string('guia_remision', 20)->nullable();
            $table->string('orden_compra', 30)->nullable();
            $table->text('observaciones')->nullable();
            $table->decimal('op_gravadas', 12, 2)->default(0);
            $table->decimal('op_exoneradas', 12, 2)->default(0);
            $table->decimal('op_inafectas', 12, 2)->default(0);
            $table->decimal('op_gratuitas', 12, 2)->default(0);   // bonificaciones
            $table->decimal('igv', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            // Nota de crédito / débito
            $table->foreignId('comprobante_referencia_id')->nullable()->constrained('comprobantes');
            $table->string('motivo_codigo', 2)->nullable();
            $table->string('motivo_descripcion')->nullable();
            // Estado SUNAT: pendiente | aceptado | observado | rechazado | error | anulado
            $table->string('estado', 15)->default('pendiente')->index();
            $table->string('sunat_codigo', 10)->nullable();
            $table->text('sunat_descripcion')->nullable();
            $table->json('sunat_observaciones')->nullable();
            $table->string('hash')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->unsignedSmallInteger('intentos_envio')->default(0);
            $table->timestamp('enviado_at')->nullable();
            $table->timestamps();
            $table->unique(['tipo_comprobante', 'serie', 'correlativo']);
        });

        // Cuotas de una venta al crédito (SUNAT exige informarlas en la factura)
        Schema::create('comprobante_cuotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id')->constrained('comprobantes')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->decimal('monto', 12, 2);
            $table->date('fecha_vencimiento');
            $table->timestamps();
        });

        // Cada línea corresponde a UN lote: si una venta toma de dos lotes, son dos líneas
        Schema::create('comprobante_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id')->constrained('comprobantes')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos');
            $table->foreignId('lote_id')->nullable()->constrained('lotes');
            $table->string('numero_lote', 50)->nullable();     // copia para imprimir
            $table->date('fecha_vencimiento')->nullable();     // copia para imprimir
            $table->string('codigo', 30)->nullable();
            $table->string('descripcion');
            $table->string('unidad', 5)->default('CJA');       // unidad mostrada: CJA, AMP, TAB...
            $table->string('unidad_sunat', 3)->default('BX');
            $table->boolean('es_fraccion')->default(false);    // se vendió por unidad suelta
            $table->decimal('cantidad', 12, 2);
            $table->decimal('valor_unitario', 16, 6);          // sin IGV
            $table->decimal('precio_unitario', 12, 3);         // con IGV
            $table->string('tipo_afectacion_igv', 2)->default('10');
            $table->boolean('bonificacion')->default(false);   // entregado gratis (operación gratuita)
            $table->decimal('valor_venta', 12, 2);
            $table->decimal('igv', 12, 2);
            $table->decimal('total', 12, 2);
            $table->timestamps();
        });

        // Kárdex por lote
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('lote_id')->constrained('lotes');
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('tipo', 10);   // entrada | salida
            $table->string('motivo', 30); // compra, venta, bonificacion, nota_credito, ajuste, inventario_inicial
            $table->decimal('cantidad', 12, 2);  // en unidad mínima
            $table->decimal('saldo_lote', 12, 2);
            $table->decimal('costo_unitario', 12, 4)->default(0);
            $table->nullableMorphs('referencia'); // documento que originó el movimiento
            $table->string('observacion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
        Schema::dropIfExists('comprobante_items');
        Schema::dropIfExists('comprobante_cuotas');
        Schema::dropIfExists('comprobantes');
        Schema::dropIfExists('clientes');
        Schema::dropIfExists('lotes');
        Schema::dropIfExists('productos');
        Schema::dropIfExists('laboratorios');
        Schema::dropIfExists('series');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sucursal_id');
            $table->dropColumn(['rol', 'activo']);
        });
        Schema::dropIfExists('sucursales');
        Schema::dropIfExists('empresas');
    }
};