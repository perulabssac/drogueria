<?php

namespace App\Http\Controllers;

use App\Models\Lote;
use App\Models\Producto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        // El contador no ve stock ni ventas del día: entra directo a sus reportes
        if ($request->user()->rol === 'contador') {
            return redirect('/reportes');
        }
        $sucursalId = $request->user()->sucursal_id;
        $hoy = now()->toDateString();

        // Stock vigente (no vencido) sumado por producto
        $stockPorProducto = Lote::query()
            ->select('producto_id', DB::raw('SUM(cantidad) as stock'))
            ->where('sucursal_id', $sucursalId)
            ->whereDate('fecha_vencimiento', '>=', $hoy)
            ->groupBy('producto_id');

        // stock_minimo está en presentaciones; el stock en unidades mínimas
        $stockBajo = Producto::query()
            ->where('activo', true)
            ->where('stock_minimo', '>', 0)
            ->leftJoinSub($stockPorProducto, 's', 's.producto_id', '=', 'productos.id')
            ->whereRaw('COALESCE(s.stock, 0) <= productos.stock_minimo * productos.unidades_por_presentacion')
            ->orderBy('nombre')
            ->limit(10)
            ->get([
                'productos.id', 'productos.nombre', 'productos.concentracion', 'productos.stock_minimo',
                'productos.unidad_venta', 'productos.fraccionable', 'productos.unidades_por_presentacion', 'productos.unidad_fraccion',
                DB::raw('COALESCE(s.stock, 0) as stock'),
            ]);

        $porVencer = Lote::query()
            ->with('producto:id,nombre,concentracion,unidad_venta,fraccionable,unidades_por_presentacion,unidad_fraccion')
            ->where('sucursal_id', $sucursalId)
            ->where('cantidad', '>', 0)
            ->whereDate('fecha_vencimiento', '<=', now()->addDays(90)->toDateString())
            ->orderBy('fecha_vencimiento')
            ->limit(10)
            ->get();

        return Inertia::render('Dashboard', [
            'kpis' => [
                'productos' => Producto::where('activo', true)->count(),
                'lotes_por_vencer' => Lote::where('sucursal_id', $sucursalId)->where('cantidad', '>', 0)
                    ->whereBetween('fecha_vencimiento', [$hoy, now()->addDays(90)->toDateString()])->count(),
                'lotes_vencidos' => Lote::where('sucursal_id', $sucursalId)->where('cantidad', '>', 0)
                    ->whereDate('fecha_vencimiento', '<', $hoy)->count(),
                'stock_bajo' => $stockBajo->count(),
            ],
            'stockBajo' => $stockBajo,
            'porVencer' => $porVencer,
        ]);
    }
}