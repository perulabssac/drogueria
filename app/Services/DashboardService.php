<?php

namespace App\Services;

use App\Models\Compra;
use App\Models\Comprobante;
use App\Models\ComprobantePago;
use App\Models\Cotizacion;
use App\Models\Lote;
use App\Models\Producto;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores del tablero de Inicio. Todo se calcula por sucursal.
 * Ventas = facturas, boletas y notas de venta no rechazadas, menos las notas de crédito.
 */
class DashboardService
{
    public const TIPOS_VENTA = ['01', '03', Comprobante::NOTA_VENTA];

    public const ESTADOS_PENDIENTES_SUNAT = ['pendiente', 'error', 'enviado'];

    public function __construct(private int $sucursalId) {}

    // ================= VENTAS =================

    /** Ventas netas entre dos fechas (incluidas), descontando notas de crédito. */
    public function ventasNetas(Carbon $desde, Carbon $hasta): float
    {
        $suma = fn (array $tipos) => (float) $this->comprobantes()
            ->whereIn('tipo_comprobante', $tipos)
            ->whereBetween('fecha_emision', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->sum('total');

        return round($suma(self::TIPOS_VENTA) - $suma(['07']), 2);
    }

    public function resumenVentas(): array
    {
        $hoy = today();
        $mes = $this->ventasNetas($hoy->copy()->startOfMonth(), $hoy);
        // Mismo tramo del mes anterior (del 1 al mismo día) para comparar en igualdad
        $inicioAnterior = $hoy->copy()->subMonthNoOverflow()->startOfMonth();
        $finAnterior = $inicioAnterior->copy()->day(min($hoy->day, $inicioAnterior->daysInMonth));
        $mesAnterior = $this->ventasNetas($inicioAnterior, $finAnterior);

        return [
            'hoy' => $this->ventasNetas($hoy, $hoy),
            'documentos_hoy' => $this->comprobantes()->whereIn('tipo_comprobante', self::TIPOS_VENTA)->whereDate('fecha_emision', $hoy)->count(),
            'mes' => $mes,
            'mes_anterior' => $mesAnterior,
            'variacion' => $mesAnterior > 0 ? round(($mes - $mesAnterior) / $mesAnterior * 100, 1) : null,
        ];
    }

    /**
     * Utilidad bruta aproximada del mes: venta sin IGV menos el costo de los lotes vendidos.
     * Las bonificaciones suman costo (se entregan gratis).
     */
    public function utilidadMes(): array
    {
        $desde = today()->startOfMonth();
        $hasta = today()->endOfDay();

        $ventas = $this->comprobantes()
            ->whereIn('tipo_comprobante', self::TIPOS_VENTA)
            ->whereBetween('fecha_emision', [$desde, $hasta]);
        $notas = $this->comprobantes()
            ->where('tipo_comprobante', '07')
            ->whereBetween('fecha_emision', [$desde, $hasta]);

        $base = fn (Builder $q) => (float) (clone $q)->sum(DB::raw('op_gravadas + op_exoneradas + op_inafectas'));
        $ventaSinIgv = $base($ventas) - $base($notas);

        // Costo: cantidad vendida × costo del lote (por presentación; si fue suelto, se divide entre el factor)
        $costo = fn (Builder $q) => (float) DB::table('comprobante_items as i')
            ->join('lotes as l', 'l.id', '=', 'i.lote_id')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->whereIn('i.comprobante_id', (clone $q)->select('id'))
            ->sum(DB::raw('i.cantidad * l.costo_unitario / CASE WHEN i.es_fraccion = 1 AND p.unidades_por_presentacion > 0 THEN p.unidades_por_presentacion ELSE 1 END'));
        $costoVentas = $costo($ventas) - $costo($notas);

        $utilidad = round($ventaSinIgv - $costoVentas, 2);

        return [
            'venta_sin_igv' => round($ventaSinIgv, 2),
            'costo' => round($costoVentas, 2),
            'utilidad' => $utilidad,
            'margen' => $ventaSinIgv > 0 ? round($utilidad / $ventaSinIgv * 100, 1) : null,
        ];
    }

    /** Ventas de cada uno de los últimos $dias días (incluye días sin ventas en cero). */
    public function ventasPorDia(int $dias = 30): array
    {
        $desde = today()->subDays($dias - 1);

        $filas = $this->comprobantes()
            ->whereIn('tipo_comprobante', [...self::TIPOS_VENTA, '07'])
            ->where('fecha_emision', '>=', $desde)
            ->selectRaw("DATE(fecha_emision) as dia, SUM(CASE WHEN tipo_comprobante = '07' THEN -total ELSE total END) as total")
            ->groupBy(DB::raw('DATE(fecha_emision)'))
            ->pluck('total', 'dia');

        return collect(range(0, $dias - 1))->map(function ($i) use ($desde, $filas) {
            $dia = $desde->copy()->addDays($i)->toDateString();

            return ['dia' => $dia, 'total' => round((float) ($filas[$dia] ?? 0), 2)];
        })->all();
    }

    /** Dinero que entró en el mes por medio de pago (ventas al contado y cobranzas). */
    public function ingresosPorMedio(): array
    {
        return ComprobantePago::query()
            ->whereHas('comprobante', fn ($q) => $q->where('sucursal_id', $this->sucursalId)->validos())
            ->whereBetween('fecha', [today()->startOfMonth(), today()->endOfDay()])
            ->selectRaw('medio, SUM(monto) as total')
            ->groupBy('medio')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($f) => ['medio' => ComprobantePago::MEDIOS[$f->medio] ?? $f->medio, 'total' => round((float) $f->total, 2)])
            ->all();
    }

    public function topProductos(int $limite = 10): array
    {
        return DB::table('comprobante_items as i')
            ->join('comprobantes as c', 'c.id', '=', 'i.comprobante_id')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->where('c.sucursal_id', $this->sucursalId)
            ->whereIn('c.tipo_comprobante', self::TIPOS_VENTA)
            ->whereNotIn('c.estado',Comprobante::ESTADOS_SIN_VALIDEZ)
            ->where('i.bonificacion', false)
            ->whereBetween('c.fecha_emision', [today()->startOfMonth(), today()->endOfDay()])
            ->groupBy('p.id', 'p.nombre', 'p.concentracion', 'p.unidad_venta', 'p.unidades_por_presentacion')
            ->selectRaw('p.id, p.nombre, p.concentracion, p.unidad_venta, SUM(i.total) as importe,
                SUM(i.cantidad / CASE WHEN i.es_fraccion = 1 AND p.unidades_por_presentacion > 0 THEN p.unidades_por_presentacion ELSE 1 END) as cantidad')
            ->orderByDesc('importe')
            ->limit($limite)
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'producto' => trim($f->nombre.' '.$f->concentracion),
                'unidad' => $f->unidad_venta,
                'cantidad' => round((float) $f->cantidad, 2),
                'importe' => round((float) $f->importe, 2),
            ])
            ->all();
    }

    public function topClientes(int $limite = 5): array
    {
        return DB::table('comprobantes as c')
            ->join('clientes as cl', 'cl.id', '=', 'c.cliente_id')
            ->where('c.sucursal_id', $this->sucursalId)
            ->whereIn('c.tipo_comprobante', self::TIPOS_VENTA)
            ->whereNotIn('c.estado',Comprobante::ESTADOS_SIN_VALIDEZ)
            ->where('cl.numero_documento', '!=', '00000000') // sin "Clientes varios"
            ->whereBetween('c.fecha_emision', [today()->startOfMonth(), today()->endOfDay()])
            ->groupBy('cl.id', 'cl.razon_social', 'cl.numero_documento')
            ->selectRaw('cl.id, cl.razon_social, cl.numero_documento, SUM(c.total) as total, COUNT(*) as documentos')
            ->orderByDesc('total')
            ->limit($limite)
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'cliente' => $f->razon_social,
                'documento' => $f->numero_documento,
                'total' => round((float) $f->total, 2),
                'documentos' => (int) $f->documentos,
            ])
            ->all();
    }

    // ================= COBRAR Y PAGAR =================

    public function porCobrar(): array
    {
        $creditos = $this->comprobantes()->where('forma_pago', 'credito')->where('saldo', '>', 0);

        return [
            'total' => round((float) (clone $creditos)->sum('saldo'), 2),
            // Ventas al crédito con alguna cuota vencida
            'vencido' => round((float) (clone $creditos)
                ->whereHas('cuotas', fn ($q) => $q->whereDate('fecha_vencimiento', '<', today()))
                ->sum('saldo'), 2),
            'clientes' => (clone $creditos)->distinct()->count('cliente_id'),
        ];
    }

    public function porPagar(): array
    {
        $pendientes = Compra::query()
            ->where('sucursal_id', $this->sucursalId)
            ->where('forma_pago', 'credito')
            ->where('estado', 'registrada')
            ->where('saldo', '>', 0);

        return [
            'total' => round((float) (clone $pendientes)->sum('saldo'), 2),
            'vencido' => round((float) (clone $pendientes)->whereDate('fecha_vencimiento', '<', today())->sum('saldo'), 2),
            'esta_semana' => round((float) (clone $pendientes)
                ->whereBetween('fecha_vencimiento', [today()->toDateString(), today()->addDays(7)->toDateString()])
                ->sum('saldo'), 2),
        ];
    }

    // ================= ALERTAS =================

    public function alertasSunat(): array
    {
        $ultimos = $this->comprobantes(false)
            ->whereIn('tipo_comprobante', ['01', '03', '07', '08'])
            ->where('fecha_emision', '>=', today()->subDays(30));

        return [
            'pendientes' => (clone $ultimos)->whereIn('estado', self::ESTADOS_PENDIENTES_SUNAT)->count(),
            'rechazados' => (clone $ultimos)->where('estado', 'rechazado')->count(),
        ];
    }

    public function cotizaciones(): array
    {
        $pendientes = Cotizacion::query()
            ->where('sucursal_id', $this->sucursalId)
            ->where('estado', 'pendiente')
            ->whereDate('fecha_vencimiento', '>=', today());

        return [
            'pendientes' => (clone $pendientes)->count(),
            'monto' => round((float) (clone $pendientes)->sum('total'), 2),
            'vencen_pronto' => (clone $pendientes)->whereDate('fecha_vencimiento', '<=', today()->addDays(2))->count(),
        ];
    }

    public function inventario(): array
    {
        $hoy = today()->toDateString();

        return [
            'productos' => Producto::where('activo', true)->count(),
            'stock_bajo' => $this->stockBajo(1000)->count(),
            'lotes_por_vencer' => Lote::where('sucursal_id', $this->sucursalId)->where('cantidad', '>', 0)
                ->whereBetween('fecha_vencimiento', [$hoy, today()->addDays(90)->toDateString()])->count(),
            'lotes_vencidos' => Lote::where('sucursal_id', $this->sucursalId)->where('cantidad', '>', 0)
                ->whereDate('fecha_vencimiento', '<', $hoy)->count(),
        ];
    }

    /** Productos con stock vigente (no vencido) igual o menor a su stock mínimo. */
    public function stockBajo(int $limite = 8)
    {
        $stockPorProducto = Lote::query()
            ->select('producto_id', DB::raw('SUM(cantidad) as stock'))
            ->where('sucursal_id', $this->sucursalId)
            ->whereDate('fecha_vencimiento', '>=', today()->toDateString())
            ->groupBy('producto_id');

        // stock_minimo está en presentaciones; el stock, en unidades mínimas
        return Producto::query()
            ->where('activo', true)
            ->where('stock_minimo', '>', 0)
            ->leftJoinSub($stockPorProducto, 's', 's.producto_id', '=', 'productos.id')
            ->whereRaw('COALESCE(s.stock, 0) <= productos.stock_minimo * productos.unidades_por_presentacion')
            ->orderByRaw('COALESCE(s.stock, 0) asc')
            ->limit($limite)
            ->get([
                'productos.id', 'productos.nombre', 'productos.concentracion', 'productos.stock_minimo',
                'productos.unidad_venta', 'productos.fraccionable', 'productos.unidades_por_presentacion', 'productos.unidad_fraccion',
                DB::raw('COALESCE(s.stock, 0) as stock'),
            ]);
    }

    public function porVencer(int $limite = 8)
    {
        return Lote::query()
            ->with('producto:id,nombre,concentracion,unidad_venta,fraccionable,unidades_por_presentacion,unidad_fraccion')
            ->where('sucursal_id', $this->sucursalId)
            ->where('cantidad', '>', 0)
            ->whereDate('fecha_vencimiento', '<=', today()->addDays(90)->toDateString())
            ->orderBy('fecha_vencimiento')
            ->limit($limite)
            ->get(['id', 'producto_id', 'numero_lote', 'fecha_vencimiento', 'cantidad']);
    }

    // ================= APOYO =================

    /** Comprobantes de la sucursal; por defecto sin los rechazados (no tienen validez). */
    private function comprobantes(bool $soloValidos = true): Builder
    {
        return Comprobante::query()
            ->where('sucursal_id', $this->sucursalId)
            ->when($soloValidos, fn ($q) => $q->validos());
    }
}