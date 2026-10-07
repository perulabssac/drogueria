<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Services\RentabilidadService;
use App\Support\Excel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte de rentabilidad (administrador y contador): cuánto se ganó en un rango de fechas,
 * por producto y por vendedor, con alertas de productos a pérdida, con margen bajo o sin margen.
 */
class RentabilidadController extends Controller
{
    public function index(Request $request): Response
    {
        [$desde, $hasta] = $this->rango($request);
        $servicio = new RentabilidadService($request->user()->sucursal_id, $desde, $hasta);
        $productos = $servicio->porProducto();

        return Inertia::render('Rentabilidad/Index', [
            'filtros' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
            'resumen' => $servicio->resumen(),
            'productos' => $productos,
            'vendedores' => $servicio->porVendedor(),
            'alertas' => $servicio->alertas($productos),
            'margenBajo' => RentabilidadService::MARGEN_BAJO,
        ]);
    }

    /** Excel por producto (?tipo=productos) o por vendedor (?tipo=vendedores). */
    public function excel(Request $request): StreamedResponse
    {
        [$desde, $hasta] = $this->rango($request);
        $servicio = new RentabilidadService($request->user()->sucursal_id, $desde, $hasta);
        $empresa = Empresa::actual();
        $subtitulo = trim(($empresa?->razon_social ?? '').' · RUC '.($empresa?->ruc ?? '')
            .' · Del '.$desde->format('d/m/Y').' al '.$hasta->format('d/m/Y').' · Montos sin IGV');
        $sufijo = $desde->format('Ymd').'-'.$hasta->format('Ymd');

        if ($request->input('tipo') === 'vendedores') {
            $filas = $servicio->porVendedor()->map(fn ($v) => [
                $v['vendedor'], $v['documentos'], $v['venta'], $v['costo'], $v['ganancia'],
                $v['margen_costo'] ?? '', $v['margen_venta'] ?? '',
            ])->all();

            return Excel::descargar(
                "rentabilidad-vendedores-{$sufijo}.xlsx",
                'Rentabilidad por vendedor',
                $subtitulo,
                ['Vendedor', 'Documentos', 'Venta', 'Costo', 'Ganancia', 'Margen s/costo %', 'Margen s/venta %'],
                $filas,
                [2, 3, 4],
            );
        }

        $filas = $servicio->porProducto()->map(fn ($p) => [
            $p['codigo'], $p['nombre'], $p['unidad_venta'], $p['cantidad'],
            $p['venta'], $p['costo'], $p['ganancia'],
            $p['margen_costo'] ?? '', $p['margen_venta'] ?? '',
            $p['margen_asignado'] ?? 'SIN MARGEN',
        ])->all();

        return Excel::descargar(
            "rentabilidad-productos-{$sufijo}.xlsx",
            'Rentabilidad por producto',
            $subtitulo,
            ['Código', 'Producto', 'Unidad', 'Cantidad', 'Venta', 'Costo', 'Ganancia', 'Margen s/costo %', 'Margen s/venta %', 'Margen asignado %'],
            $filas,
            [4, 5, 6],
        );
    }

    /** Rango pedido (por defecto, del 1 del mes a hoy). Máximo un año para que el reporte no se vuelva lento. */
    private function rango(Request $request): array
    {
        $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        $hasta = $request->filled('hasta') ? Carbon::parse($request->input('hasta')) : today();
        $desde = $request->filled('desde') ? Carbon::parse($request->input('desde')) : $hasta->copy()->startOfMonth();

        if ($desde->diffInDays($hasta) > 366) {
            $desde = $hasta->copy()->subYear()->addDay();
        }

        return [$desde->startOfDay(), $hasta->startOfDay()];
    }
}