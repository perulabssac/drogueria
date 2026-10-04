<?php

use App\Http\Controllers\AjusteController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CobranzaController;
use App\Http\Controllers\ComprobanteController;
use App\Http\Controllers\ComprobantePublicoController;
use App\Http\Controllers\CompraController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\CotizacionController;
use App\Http\Controllers\CuentaPagarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GuiaController;
use App\Http\Controllers\ImportacionController;
use App\Http\Controllers\KardexController;
use App\Http\Controllers\NotaCreditoController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\ReporteContableController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\TomaInventarioController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VencimientoController;
use App\Http\Controllers\VentaController;
use Illuminate\Support\Facades\Route;

// Solo para visitantes (no logueados)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:10,1');
});

// Enlace público y firmado al PDF del comprobante (se envía por WhatsApp o correo).
// No requiere iniciar sesión: sin una firma válida responde 403.
Route::get('/c/{comprobante}', [ComprobantePublicoController::class, 'pdf'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('comprobantes.publico');

// Solo para usuarios logueados
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    // Cambiar mi contraseña (todos los usuarios)
    Route::get('/perfil/password', [PerfilController::class, 'edit'])->name('perfil.password');
    Route::put('/perfil/password', [PerfilController::class, 'update'])->middleware('throttle:6,1');

    // Búsquedas rápidas (JSON) para los formularios
    Route::get('/api/productos/buscar', [ProductoController::class, 'buscar']);

    // Ventas: roles vendedor y admin
    Route::middleware('rol:vendedor')->group(function () {
        Route::get('/ventas/nueva', [VentaController::class, 'create'])->name('ventas.create');
        Route::post('/ventas', [VentaController::class, 'store'])->name('ventas.store');

        // Clientes (consulta a SUNAT/RENIEC con Decolecta)
        Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
        Route::get('/clientes/nuevo', [ClienteController::class, 'create'])->name('clientes.create');
        Route::post('/clientes', [ClienteController::class, 'store'])->name('clientes.store');
        Route::get('/clientes/{cliente}/editar', [ClienteController::class, 'edit'])->name('clientes.edit');
        Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
        Route::post('/clientes/{cliente}/verificar', [ClienteController::class, 'verificar'])->middleware('throttle:10,1');
        Route::get('/api/clientes/buscar', [ClienteController::class, 'buscar']);
        Route::post('/api/clientes/consultar', [ClienteController::class, 'consultar'])->middleware('throttle:20,1');
        Route::post('/api/clientes', [ClienteController::class, 'guardarRapido']);

        // Acciones con SUNAT (el contador no las tiene)
        Route::post('/comprobantes/{comprobante}/reenviar', [ComprobanteController::class, 'reenviar']);
        Route::post('/comprobantes/{comprobante}/consultar', [ComprobanteController::class, 'consultar']);
        Route::post('/comprobantes/{comprobante}/baja/consultar', [ComprobanteController::class, 'consultarBaja']);

        // Enviar el comprobante al cliente
        Route::post('/comprobantes/{comprobante}/correo', [ComprobanteController::class, 'correo'])->middleware('throttle:20,1');
        Route::post('/comprobantes/{comprobante}/whatsapp', [ComprobanteController::class, 'whatsapp']);

        // Caja del usuario (turno)
        Route::get('/caja', [CajaController::class, 'actual'])->name('caja.actual');
        Route::post('/caja/abrir', [CajaController::class, 'abrir']);
        Route::post('/caja/movimientos', [CajaController::class, 'movimiento']);
        Route::post('/caja/cerrar', [CajaController::class, 'cerrar']);
        Route::get('/cajas/{caja}', [CajaController::class, 'show'])->name('cajas.show');
        Route::get('/cajas/{caja}/imprimir', [CajaController::class, 'imprimir']);

        // Cuentas por cobrar (ventas al crédito)
        Route::get('/cobranzas', [CobranzaController::class, 'index'])->name('cobranzas.index');
        Route::get('/cobranzas/{comprobante}', [CobranzaController::class, 'show'])->name('cobranzas.show');
        Route::post('/cobranzas/{comprobante}', [CobranzaController::class, 'store'])->name('cobranzas.store');
    });

    // Almacén: roles almacén y admin
    Route::middleware('rol:almacen')->group(function () {
        Route::get('/productos', [ProductoController::class, 'index'])->name('productos.index');
        Route::get('/productos/nuevo', [ProductoController::class, 'create'])->name('productos.create');
        Route::post('/productos', [ProductoController::class, 'store'])->name('productos.store');
        Route::get('/productos/{producto}/editar', [ProductoController::class, 'edit'])->name('productos.edit');
        Route::put('/productos/{producto}', [ProductoController::class, 'update'])->name('productos.update');
        Route::delete('/productos/{producto}', [ProductoController::class, 'destroy'])->name('productos.destroy');

        Route::get('/proveedores', [ProveedorController::class, 'index'])->name('proveedores.index');
        Route::get('/proveedores/nuevo', [ProveedorController::class, 'create'])->name('proveedores.create');
        Route::post('/proveedores', [ProveedorController::class, 'store'])->name('proveedores.store');
        Route::get('/proveedores/{proveedor}/editar', [ProveedorController::class, 'edit'])->name('proveedores.edit');
        Route::put('/proveedores/{proveedor}', [ProveedorController::class, 'update'])->name('proveedores.update');
        Route::get('/api/proveedores/buscar', [ProveedorController::class, 'buscar']);

        // Registrar y anular compras (el contador solo las ve)
        Route::get('/compras/nueva', [CompraController::class, 'create'])->name('compras.create');
        Route::post('/compras', [CompraController::class, 'store'])->name('compras.store');
        Route::post('/compras/{compra}/anular', [CompraController::class, 'anular'])->name('compras.anular');

        // Registrar ajustes de inventario (el contador solo los ve)
        Route::get('/ajustes/nuevo', [AjusteController::class, 'create'])->name('ajustes.create');
        Route::post('/ajustes', [AjusteController::class, 'store'])->name('ajustes.store');
        Route::get('/api/ajustes/lotes', [AjusteController::class, 'lotes']);

        // Dar de baja lotes vencidos (genera un ajuste con su acta)
        Route::post('/vencimientos/baja', [VencimientoController::class, 'baja'])->name('vencimientos.baja');

        // Toma de inventario: abrir, contar y anular (aprobar es solo del administrador)
        Route::post('/tomas', [TomaInventarioController::class, 'store'])->name('tomas.store');
        Route::put('/tomas/{toma}/conteo', [TomaInventarioController::class, 'conteo'])->name('tomas.conteo');
        Route::post('/tomas/{toma}/lotes', [TomaInventarioController::class, 'agregarLote'])->name('tomas.lotes');
        Route::post('/tomas/{toma}/anular', [TomaInventarioController::class, 'anular'])->name('tomas.anular');

        // Importar productos y stock desde Excel
        Route::get('/importar', [ImportacionController::class, 'index'])->name('importar.index');
        Route::get('/importar/plantilla', [ImportacionController::class, 'plantilla'])->name('importar.plantilla');
        Route::post('/importar', [ImportacionController::class, 'subir'])->name('importar.subir');
        Route::post('/importar/confirmar', [ImportacionController::class, 'confirmar'])->name('importar.confirmar');
        Route::post('/importar/cancelar', [ImportacionController::class, 'cancelar'])->name('importar.cancelar');
    });

    // Guías de remisión: las emiten ventas y almacén (despacho)
    Route::middleware('rol:vendedor,almacen')->group(function () {
        Route::get('/guias/nueva', [GuiaController::class, 'create'])->name('guias.create');
        Route::post('/guias', [GuiaController::class, 'store'])->name('guias.store');
        Route::post('/guias/{guia}/enviar', [GuiaController::class, 'enviar'])->name('guias.enviar');
    });

    // Consulta de guías (también el contador). Va después de /guias/nueva.
    Route::middleware('rol:vendedor,almacen,contador')->group(function () {
        Route::get('/guias', [GuiaController::class, 'index'])->name('guias.index');
        Route::get('/guias/{guia}', [GuiaController::class, 'show'])->name('guias.show');
        Route::get('/guias/{guia}/imprimir', [GuiaController::class, 'imprimir'])->name('guias.imprimir');
        Route::get('/guias/{guia}/xml', [GuiaController::class, 'xml']);
        Route::get('/guias/{guia}/cdr', [GuiaController::class, 'cdr']);
    });

    // Cotizaciones (proformas): las hace el vendedor
    Route::middleware('rol:vendedor')->group(function () {
        Route::get('/cotizaciones/nueva', [CotizacionController::class, 'create'])->name('cotizaciones.create');
        Route::post('/cotizaciones', [CotizacionController::class, 'store'])->name('cotizaciones.store');
        Route::get('/cotizaciones/{cotizacion}/editar', [CotizacionController::class, 'edit'])->name('cotizaciones.edit');
        Route::put('/cotizaciones/{cotizacion}', [CotizacionController::class, 'update'])->name('cotizaciones.update');
        Route::post('/cotizaciones/{cotizacion}/anular', [CotizacionController::class, 'anular'])->name('cotizaciones.anular');
    });

    // Consulta de cotizaciones (también el contador). Va después de /cotizaciones/nueva.
    Route::middleware('rol:vendedor,contador')->group(function () {
        Route::get('/cotizaciones', [CotizacionController::class, 'index'])->name('cotizaciones.index');
        Route::get('/cotizaciones/{cotizacion}', [CotizacionController::class, 'show'])->name('cotizaciones.show');
        Route::get('/cotizaciones/{cotizacion}/imprimir', [CotizacionController::class, 'imprimir'])->name('cotizaciones.imprimir');
    });

    // Consulta de comprobantes (solo lectura): vendedores y contador
    Route::middleware('rol:vendedor,contador')->group(function () {
        Route::get('/comprobantes', [ComprobanteController::class, 'index'])->name('comprobantes.index');
        Route::get('/comprobantes/{comprobante}', [ComprobanteController::class, 'show'])->name('comprobantes.show');
        Route::get('/comprobantes/{comprobante}/imprimir', [ComprobanteController::class, 'imprimir']);
        Route::get('/comprobantes/{comprobante}/xml', [ComprobanteController::class, 'xml']);
        Route::get('/comprobantes/{comprobante}/cdr', [ComprobanteController::class, 'cdr']);
        Route::get('/comprobantes/{comprobante}/baja/cdr', [ComprobanteController::class, 'cdrBaja']);
    });

    // Consulta de compras (solo lectura): almacén y contador.
    // Va después de /compras/nueva para que "nueva" no se confunda con el número de una compra.
    Route::middleware('rol:almacen,contador')->group(function () {
        Route::get('/compras', [CompraController::class, 'index'])->name('compras.index');
        Route::get('/compras/{compra}', [CompraController::class, 'show'])->name('compras.show');

        // Inventario: stock, kárdex, ajustes, vencimientos y tomas (almacén, contador y admin)
        Route::get('/inventario', [StockController::class, 'index'])->name('inventario.stock');
        Route::get('/inventario/excel', [StockController::class, 'excel'])->name('inventario.excel');
        Route::get('/kardex', [KardexController::class, 'index'])->name('kardex.index');
        Route::get('/kardex/{producto}/excel', [KardexController::class, 'excel'])->name('kardex.excel');
        Route::get('/ajustes', [AjusteController::class, 'index'])->name('ajustes.index');
        Route::get('/ajustes/{ajuste}', [AjusteController::class, 'show'])->name('ajustes.show');
        Route::get('/vencimientos', [VencimientoController::class, 'index'])->name('vencimientos.index');
        Route::get('/tomas', [TomaInventarioController::class, 'index'])->name('tomas.index');
        Route::get('/tomas/{toma}', [TomaInventarioController::class, 'show'])->name('tomas.show');
        Route::get('/tomas/{toma}/hoja', [TomaInventarioController::class, 'hoja'])->name('tomas.hoja');
    });

    // Cuentas por pagar a proveedores: consultan almacén y contador
    Route::middleware('rol:almacen,contador')->group(function () {
        Route::get('/cuentas-por-pagar', [CuentaPagarController::class, 'index'])->name('cuentas-pagar.index');
        Route::get('/cuentas-por-pagar/{compra}', [CuentaPagarController::class, 'show'])->name('cuentas-pagar.show');
    });
    // Registrar pagos: contador y administrador. Anular un pago: solo el administrador.
    Route::post('/cuentas-por-pagar/{compra}/pagos', [CuentaPagarController::class, 'pagar'])->middleware('rol:contador')->name('cuentas-pagar.pagar');
    Route::post('/cuentas-por-pagar/pagos/{pago}/anular', [CuentaPagarController::class, 'anularPago'])->middleware('rol:admin')->name('cuentas-pagar.anular');

    // Reportes contables: contador y administrador
    Route::middleware('rol:contador')->group(function () {
        Route::get('/reportes', [ReporteContableController::class, 'index'])->name('reportes.index');
        Route::get('/reportes/ventas.xlsx', [ReporteContableController::class, 'excelVentas'])->name('reportes.ventas');
        Route::get('/reportes/compras.xlsx', [ReporteContableController::class, 'excelCompras'])->name('reportes.compras');
        Route::get('/reportes/xml.zip', [ReporteContableController::class, 'xml'])->name('reportes.xml');
    });

    // Solo administrador: historial de cajas, usuarios, notas de crédito y bajas
    Route::middleware('rol:admin')->group(function () {
        Route::get('/cajas', [CajaController::class, 'index'])->name('cajas.index');

        // Usuarios del sistema
        Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
        Route::get('/usuarios/nuevo', [UsuarioController::class, 'create'])->name('usuarios.create');
        Route::post('/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
        Route::get('/usuarios/{usuario}/editar', [UsuarioController::class, 'edit'])->name('usuarios.edit');
        Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
        Route::post('/usuarios/{usuario}/estado', [UsuarioController::class, 'cambiarEstado'])->name('usuarios.estado');

        // Notas de crédito (anulan o devuelven ventas)
        Route::get('/comprobantes/{comprobante}/nota-credito', [NotaCreditoController::class, 'create'])->name('notas-credito.create');
        Route::post('/comprobantes/{comprobante}/nota-credito', [NotaCreditoController::class, 'store'])->name('notas-credito.store');

        // Comunicación de baja (anula ante SUNAT dentro de los 7 días)
        Route::post('/comprobantes/{comprobante}/baja', [ComprobanteController::class, 'baja'])->name('comprobantes.baja');

        // Aprobar la toma de inventario (ajusta el stock según el conteo)
        Route::post('/tomas/{toma}/aprobar', [TomaInventarioController::class, 'aprobar'])->name('tomas.aprobar');
    });

    // Solo administrador
    Route::middleware('rol:admin')->prefix('configuracion')->group(function () {
        Route::get('/', [ConfiguracionController::class, 'index'])->name('configuracion.index');
        Route::put('/empresa', [ConfiguracionController::class, 'actualizarEmpresa']);
        Route::put('/sunat', [ConfiguracionController::class, 'actualizarSunat']);
        Route::post('/certificado', [ConfiguracionController::class, 'subirCertificado']);
        Route::post('/certificado-demo', [ConfiguracionController::class, 'certificadoDemo']);
        Route::post('/probar-sunat', [ConfiguracionController::class, 'probarSunat'])->middleware('throttle:10,1');
        Route::post('/series', [ConfiguracionController::class, 'guardarSerie']);
    });
});