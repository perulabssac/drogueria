<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tablero de Inicio: cada rol ve los indicadores que le sirven.
 * - Vendedor: ventas, cotizaciones, por cobrar y SUNAT.
 * - Almacén: inventario (stock bajo, vencimientos) y por pagar.
 * - Contador: ventas, utilidad, por cobrar, por pagar y SUNAT.
 * - Administrador: todo.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $usuario = $request->user();
        $d = new DashboardService($usuario->sucursal_id);

        $ve = fn (string ...$roles) => $usuario->tieneRol(...$roles);

        return Inertia::render('Dashboard', [
            'secciones' => [
                'ventas' => $ve('vendedor', 'contador'),
                'utilidad' => $ve('contador'),
                'inventario' => $ve('almacen'),
                'cobrar' => $ve('vendedor', 'contador'),
                'pagar' => $ve('almacen', 'contador'),
                'sunat' => $ve('vendedor', 'contador'),
                'cotizaciones' => $ve('vendedor'),
            ],
            // Lo que el rol no ve no se calcula (llega como null)
            'ventas' => $ve('vendedor', 'contador') ? $d->resumenVentas() : null,
            'ventasPorDia' => $ve('vendedor', 'contador') ? $d->ventasPorDia(30) : null,
            'ingresosPorMedio' => $ve('vendedor', 'contador') ? $d->ingresosPorMedio() : null,
            'topProductos' => $ve('vendedor', 'contador', 'almacen') ? $d->topProductos(10) : null,
            'topClientes' => $ve('vendedor', 'contador') ? $d->topClientes(5) : null,
            'utilidad' => $ve('contador') ? $d->utilidadMes() : null,
            'porCobrar' => $ve('vendedor', 'contador') ? $d->porCobrar() : null,
            'porPagar' => $ve('almacen', 'contador') ? $d->porPagar() : null,
            'sunat' => $ve('vendedor', 'contador') ? $d->alertasSunat() : null,
            'cotizaciones' => $ve('vendedor') ? $d->cotizaciones() : null,
            'inventario' => $ve('almacen') ? $d->inventario() : null,
            'stockBajo' => $ve('almacen') ? $d->stockBajo(8) : null,
            'porVencer' => $ve('almacen') ? $d->porVencer(8) : null,
        ]);
    }
}