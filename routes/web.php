<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CobranzaController;
use App\Http\Controllers\ComprobanteController;
use App\Http\Controllers\CompraController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotaCreditoController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\ReporteContableController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VentaController;
use Illuminate\Support\Facades\Route;

// Solo para visitantes (no logueados)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:10,1');
});

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

        Route::get('/api/clientes/buscar', [ClienteController::class, 'buscar']);
        Route::post('/api/clientes', [ClienteController::class, 'guardarRapido']);

        // Acciones con SUNAT (el contador no las tiene)
        Route::post('/comprobantes/{comprobante}/reenviar', [ComprobanteController::class, 'reenviar']);
        Route::post('/comprobantes/{comprobante}/consultar', [ComprobanteController::class, 'consultar']);

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
    });

    // Consulta de comprobantes (solo lectura): vendedores y contador
    Route::middleware('rol:vendedor,contador')->group(function () {
        Route::get('/comprobantes', [ComprobanteController::class, 'index'])->name('comprobantes.index');
        Route::get('/comprobantes/{comprobante}', [ComprobanteController::class, 'show'])->name('comprobantes.show');
        Route::get('/comprobantes/{comprobante}/imprimir', [ComprobanteController::class, 'imprimir']);
        Route::get('/comprobantes/{comprobante}/xml', [ComprobanteController::class, 'xml']);
        Route::get('/comprobantes/{comprobante}/cdr', [ComprobanteController::class, 'cdr']);
    });

    // Consulta de compras (solo lectura): almacén y contador.
    // Va después de /compras/nueva para que "nueva" no se confunda con el número de una compra.
    Route::middleware('rol:almacen,contador')->group(function () {
        Route::get('/compras', [CompraController::class, 'index'])->name('compras.index');
        Route::get('/compras/{compra}', [CompraController::class, 'show'])->name('compras.show');
    });

    // Reportes contables: contador y administrador
    Route::middleware('rol:contador')->group(function () {
        Route::get('/reportes', [ReporteContableController::class, 'index'])->name('reportes.index');
        Route::get('/reportes/ventas.xlsx', [ReporteContableController::class, 'excelVentas'])->name('reportes.ventas');
        Route::get('/reportes/compras.xlsx', [ReporteContableController::class, 'excelCompras'])->name('reportes.compras');
        Route::get('/reportes/xml.zip', [ReporteContableController::class, 'xml'])->name('reportes.xml');
    });

    // Solo administrador: historial de cajas, usuarios y notas de crédito
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