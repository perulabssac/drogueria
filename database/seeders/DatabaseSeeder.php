<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Laboratorio;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Serie;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // RUC de prueba aceptado por el entorno beta de SUNAT
        Empresa::create([
            'ruc' => '20000000001',
            'razon_social' => 'DROGUERIA DEMO S.A.C.',
            'nombre_comercial' => 'DROGUERIA DEMO',
            'direccion' => 'AV. JAVIER PRADO ESTE 123',
            'ubigeo' => '150131',
            'departamento' => 'LIMA',
            'provincia' => 'LIMA',
            'distrito' => 'SAN ISIDRO',
            'cuentas_bancarias' => "BCP Cta. Cte. Soles: 000-0000000-0-00\nBanco de la Nación Cta. Detracciones: 00-000-000000",
            'entorno' => 'beta',
        ]);

        $sucursal = Sucursal::create([
            'nombre' => 'Almacén principal',
            'direccion' => 'AV. JAVIER PRADO ESTE 123',
            'codigo_establecimiento' => '0000',
        ]);

        // Series: F001 facturas, B001 boletas, FC01/BC01 notas de crédito, NV01 notas de venta (internas)
        $series = [['01', 'F001'], ['03', 'B001'], ['07', 'FC01'], ['07', 'BC01'], ['NV', 'NV01']];
        foreach ($series as [$tipo, $serie]) {
            // firstOrCreate: la migración de notas de venta pudo haber creado NV01 antes
            Serie::firstOrCreate(
                ['tipo_comprobante' => $tipo, 'serie' => $serie],
                ['sucursal_id' => $sucursal->id],
            );
        }

        $admin = User::create([
            'name' => 'Administrador',
            'email' => 'admin@drogueria.test',
            'password' => 'password',
            'rol' => 'admin',
            'sucursal_id' => $sucursal->id,
        ]);
        User::create([
            'name' => 'Vendedor Demo',
            'email' => 'vendedor@drogueria.test',
            'password' => 'password',
            'rol' => 'vendedor',
            'sucursal_id' => $sucursal->id,
        ]);

        Cliente::clientesVarios();
        Cliente::create([
            'tipo_documento' => '6',
            'numero_documento' => '20123456786',
            'razon_social' => 'BOTICA EJEMPLO E.I.R.L.',
            'direccion' => 'JR. DE LA UNION 456',
            'ciudad' => 'LIMA',
            'dias_credito' => 30,
            'limite_credito' => 5000,
        ]);

        $labs = collect(['PORTUGAL', 'MEDIFARMA', 'FARMINDUSTRIA', 'GENFAR', 'NOVO NORDISK'])
            ->mapWithKeys(fn ($n) => [$n => Laboratorio::create(['nombre' => $n])->id]);

        $base = ['condicion_venta' => 'sin_receta', 'tipo_afectacion_igv' => '10', 'fraccionable' => false, 'unidades_por_presentacion' => 1];

        $productos = [
            [...$base, 'codigo' => 'PAR500', 'nombre' => 'PARACETAMOL', 'principio_activo' => 'Paracetamol', 'concentracion' => '500 MG',
                'forma_farmaceutica' => 'Tableta', 'presentacion' => 'CJA X 100 TAB', 'categoria' => 'ANALGÉSICOS', 'laboratorio' => 'GENFAR',
                'registro_sanitario' => 'EN-01234', 'unidad_venta' => 'CJA', 'precio_venta' => 15.000, 'costo' => 9.50, 'stock_minimo' => 10,
                'fraccionable' => true, 'unidades_por_presentacion' => 100, 'unidad_fraccion' => 'TAB', 'precio_fraccion' => 0.200],
            [...$base, 'codigo' => 'AMX500', 'nombre' => 'AMOXICILINA', 'principio_activo' => 'Amoxicilina', 'concentracion' => '500 MG',
                'forma_farmaceutica' => 'Cápsula', 'presentacion' => 'CJA X 100 CAP', 'categoria' => 'ANTIBIÓTICOS', 'laboratorio' => 'PORTUGAL',
                'registro_sanitario' => 'EN-04567', 'condicion_venta' => 'con_receta', 'unidad_venta' => 'CJA', 'precio_venta' => 40.000,
                'costo' => 26.00, 'stock_minimo' => 10,
                'fraccionable' => true, 'unidades_por_presentacion' => 100, 'unidad_fraccion' => 'CAP', 'precio_fraccion' => 0.500],
            [...$base, 'codigo' => 'MED150', 'nombre' => 'MEDROXIPROGESTERONA', 'principio_activo' => 'Medroxiprogesterona acetato', 'concentracion' => '150 MG/ML',
                'forma_farmaceutica' => 'Suspensión inyectable', 'presentacion' => 'CJA X 1 AMP', 'categoria' => 'ANTICONCEPTIVOS', 'laboratorio' => 'MEDIFARMA',
                'registro_sanitario' => 'EE-07890', 'condicion_venta' => 'con_receta', 'unidad_venta' => 'AMP', 'precio_venta' => 27.000,
                'costo' => 18.00, 'stock_minimo' => 20],
            [...$base, 'codigo' => 'AMB015', 'nombre' => 'AMBROXOL', 'principio_activo' => 'Ambroxol clorhidrato', 'concentracion' => '15 MG/5 ML',
                'forma_farmaceutica' => 'Jarabe', 'presentacion' => 'FCO X 120 ML', 'categoria' => 'RESPIRATORIO', 'laboratorio' => 'FARMINDUSTRIA',
                'registro_sanitario' => 'EN-02211', 'unidad_venta' => 'FCO', 'precio_venta' => 12.000, 'costo' => 7.20, 'stock_minimo' => 20],
            [...$base, 'codigo' => 'BET005', 'nombre' => 'BETAMETASONA', 'principio_activo' => 'Betametasona valerato', 'concentracion' => '0.1%',
                'forma_farmaceutica' => 'Crema', 'presentacion' => 'TBO X 20 G', 'categoria' => 'DERMATOLÓGICOS', 'laboratorio' => 'PORTUGAL',
                'registro_sanitario' => 'EN-03344', 'condicion_venta' => 'con_receta', 'unidad_venta' => 'TBO', 'precio_venta' => 5.900,
                'costo' => 3.10, 'stock_minimo' => 20],
            [...$base, 'codigo' => 'MET850', 'nombre' => 'METFORMINA', 'principio_activo' => 'Metformina clorhidrato', 'concentracion' => '850 MG',
                'forma_farmaceutica' => 'Tableta', 'presentacion' => 'CJA X 100 TAB', 'categoria' => 'ANTIDIABÉTICOS', 'laboratorio' => 'FARMINDUSTRIA',
                'registro_sanitario' => 'EN-05511', 'condicion_venta' => 'con_receta', 'tipo_afectacion_igv' => '20', 'unidad_venta' => 'CJA',
                'precio_venta' => 20.000, 'costo' => 11.00, 'stock_minimo' => 10],
            [...$base, 'codigo' => 'INS100', 'nombre' => 'INSULINA HUMANA NPH', 'principio_activo' => 'Insulina humana isófana', 'concentracion' => '100 UI/ML',
                'forma_farmaceutica' => 'Suspensión inyectable', 'presentacion' => 'FCO X 10 ML', 'categoria' => 'ANTIDIABÉTICOS', 'laboratorio' => 'NOVO NORDISK',
                'registro_sanitario' => 'BE-00912', 'condicion_venta' => 'con_receta', 'tipo_afectacion_igv' => '20', 'cadena_frio' => true,
                'unidad_venta' => 'FCO', 'precio_venta' => 45.000, 'costo' => 32.00, 'stock_minimo' => 15],
        ];

        foreach ($productos as $i => $datos) {
            $datos['laboratorio_id'] = $labs[$datos['laboratorio']];
            $datos['unidad_sunat'] = Producto::unidadSunat($datos['unidad_venta']);
            unset($datos['laboratorio']);
            $producto = Producto::create($datos);

            // Dos lotes por producto con vencimientos distintos, para ver FEFO en acción.
            // Las cantidades se guardan en unidad mínima: presentaciones x factor.
            $lotes = [
                ['E'.(2604 + $i).'A', now()->addDays(60 + $i * 10), 15],
                ['E'.(2604 + $i).'B', now()->addMonths(24), 50],
            ];

            foreach ($lotes as [$numero, $vence, $presentaciones]) {
                $cantidad = $presentaciones * $producto->factor();

                $lote = Lote::create([
                    'producto_id' => $producto->id,
                    'sucursal_id' => $sucursal->id,
                    'numero_lote' => $numero,
                    'fecha_vencimiento' => $vence->toDateString(),
                    'cantidad' => $cantidad,
                    'costo_unitario' => $producto->costo,
                ]);

                // Todo ingreso de stock queda registrado en el kárdex
                MovimientoInventario::create([
                    'producto_id' => $producto->id,
                    'lote_id' => $lote->id,
                    'sucursal_id' => $sucursal->id,
                    'user_id' => $admin->id,
                    'tipo' => 'entrada',
                    'motivo' => 'inventario_inicial',
                    'cantidad' => $cantidad,
                    'saldo_lote' => $cantidad,
                    'costo_unitario' => $producto->costo,
                ]);
            }
        }
    }
}