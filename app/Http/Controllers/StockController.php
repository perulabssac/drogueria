<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Lote;
use App\Models\Producto;
use App\Support\Excel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stock actual (la "foto" del inventario): por producto y por lote, con vencimientos,
 * stock mínimo y valor a costo. Lo ven almacén, contador y administrador.
 */
class StockController extends Controller
{
    /** Un lote "por vencer" es el que vence dentro de estos días. */
    public const DIAS_POR_VENCER = 90;

    public const VISTAS = [
        'todos' => 'Todos',
        'bajo_minimo' => 'Bajo stock mínimo',
        'por_vencer' => 'Por vencer',
        'vencidos' => 'Con lotes vencidos',
        'sin_stock' => 'Sin stock',
    ];

    public const ESTADOS = ['vigente' => 'Vigente', 'por_vencer' => 'Por vencer', 'vencido' => 'Vencido'];

    // Unidades mínimas por presentación (1 si el producto no se vende fraccionado)
    private const SQL_FACTOR = '(CASE WHEN productos.fraccionable = 1 AND productos.unidades_por_presentacion > 1 THEN productos.unidades_por_presentacion ELSE 1 END)';

    // Stock vigente (lotes no vencidos) del producto en la sucursal
    private const SQL_STOCK_VIGENTE = '(SELECT COALESCE(SUM(l.cantidad), 0) FROM lotes l WHERE l.producto_id = productos.id AND l.sucursal_id = ? AND l.fecha_vencimiento >= ?)';

    public function index(Request $request): Response
    {
        $sucursalId = $request->user()->sucursal_id;
        $vista = $this->vista($request);
        $buscar = trim($request->string('buscar')->toString());

        $productos = $this->consulta($buscar, $sucursalId, $vista)
            ->with($this->relaciones($sucursalId))
            ->orderBy('nombre')
            ->paginate(20)
            ->withQueryString();

        $productos->getCollection()->transform(fn (Producto $p) => $this->fila($p));

        return Inertia::render('Inventario/Stock', [
            'productos' => $productos,
            'filtros' => ['buscar' => $buscar, 'vista' => $vista],
            'vistas' => self::VISTAS,
            // Cuántos productos hay en cada filtro (sin contar la búsqueda)
            'conteos' => collect(array_keys(self::VISTAS))
                ->reject(fn ($v) => $v === 'todos')
                ->mapWithKeys(fn ($v) => [$v => $this->consulta('', $sucursalId, $v)->count()]),
            'valor' => $this->valor($sucursalId),
            'diasPorVencer' => self::DIAS_POR_VENCER,
        ]);
    }

    /** Excel con una fila por lote (valorizado a costo), para el contador. */
    public function excel(Request $request): StreamedResponse
    {
        $sucursalId = $request->user()->sucursal_id;
        $vista = $this->vista($request);
        $buscar = trim($request->string('buscar')->toString());

        // En "por vencer" y "vencidos" solo interesan esos lotes
        $soloEstado = ['por_vencer' => 'por_vencer', 'vencidos' => 'vencido'][$vista] ?? null;

        $filas = [];
        $productos = $this->consulta($buscar, $sucursalId, $vista)->with($this->relaciones($sucursalId))->orderBy('nombre')->get();
        foreach ($productos as $p) {
            $factor = $p->factor();
            $lotes = $p->lotes->filter(fn (Lote $l) => ! $soloEstado || $this->estadoLote($l) === $soloEstado);

            if ($lotes->isEmpty() && ! $soloEstado) {
                $filas[] = [$p->codigo, $p->descripcionCompleta(), $p->laboratorio?->nombre ?? '', '', '', 'Sin stock', 0.0, $p->unidad_venta, (float) $p->costo, 0.0];
            }
            foreach ($lotes as $l) {
                $filas[] = [
                    $p->codigo,
                    $p->descripcionCompleta(),
                    $p->laboratorio?->nombre ?? '',
                    $l->numero_lote,
                    $l->fecha_vencimiento->format('d/m/Y'),
                    self::ESTADOS[$this->estadoLote($l)],
                    round((float) $l->cantidad / $factor, 2),
                    $p->unidad_venta,
                    (float) $l->costo_unitario,
                    round((float) $l->cantidad / $factor * (float) $l->costo_unitario, 2),
                ];
            }
        }

        $empresa = Empresa::actual();
        $subtitulo = implode(' · ', array_filter([
            $empresa?->razon_social,
            $empresa ? 'RUC '.$empresa->ruc : null,
            'Stock al '.now()->format('d/m/Y H:i'),
            $vista !== 'todos' ? self::VISTAS[$vista] : null,
            $buscar ? "Búsqueda: {$buscar}" : null,
            'Cantidades en presentaciones · Costos sin IGV',
        ]));

        return Excel::descargar(
            'stock-'.now()->format('Y-m-d').'.xlsx',
            'Stock valorizado',
            $subtitulo,
            ['Código', 'Producto', 'Laboratorio', 'Lote', 'Vence', 'Estado', 'Cantidad', 'Unidad', 'Costo unit.', 'Valor'],
            $filas,
            [9],
        );
    }

    // ================= Consultas =================

    private function consulta(string $buscar, int $sucursalId, string $vista): Builder
    {
        $hoy = today()->toDateString();
        $limite = today()->addDays(self::DIAS_POR_VENCER)->toDateString();
        $conStock = fn ($q) => $q->where('sucursal_id', $sucursalId)->where('cantidad', '>', 0);

        return Producto::query()
            // Productos activos, y también inactivos que todavía tengan stock
            ->where(fn ($w) => $w->where('activo', true)->orWhereHas('lotes', $conStock))
            ->buscar($buscar)
            ->when($vista === 'bajo_minimo', fn ($q) => $q
                ->where('stock_minimo', '>', 0)
                ->whereRaw(self::SQL_STOCK_VIGENTE.' < productos.stock_minimo * '.self::SQL_FACTOR, [$sucursalId, $hoy]))
            ->when($vista === 'por_vencer', fn ($q) => $q
                ->whereHas('lotes', fn ($l) => $conStock($l)->whereBetween('fecha_vencimiento', [$hoy, $limite])))
            ->when($vista === 'vencidos', fn ($q) => $q
                ->whereHas('lotes', fn ($l) => $conStock($l)->where('fecha_vencimiento', '<', $hoy)))
            ->when($vista === 'sin_stock', fn ($q) => $q
                ->whereDoesntHave('lotes', fn ($l) => $conStock($l)->where('fecha_vencimiento', '>=', $hoy)));
    }

    private function relaciones(int $sucursalId): array
    {
        return [
            'laboratorio:id,nombre',
            // Lotes con stock, en el orden en que saldrán (FEFO)
            'lotes' => fn ($q) => $q
                ->where('sucursal_id', $sucursalId)
                ->where('cantidad', '>', 0)
                ->orderBy('fecha_vencimiento')
                ->select(['id', 'producto_id', 'numero_lote', 'fecha_vencimiento', 'cantidad', 'costo_unitario']),
        ];
    }

    /** Valor del inventario a costo (sin IGV): total y lo que está vencido. */
    private function valor(int $sucursalId): array
    {
        $base = fn () => DB::table('lotes')
            ->join('productos', 'productos.id', '=', 'lotes.producto_id')
            ->where('lotes.sucursal_id', $sucursalId)
            ->where('lotes.cantidad', '>', 0)
            ->selectRaw('COALESCE(SUM(lotes.cantidad * lotes.costo_unitario / '.self::SQL_FACTOR.'), 0) AS valor');

        return [
            'total' => round((float) $base()->value('valor'), 2),
            'vencido' => round((float) $base()->where('lotes.fecha_vencimiento', '<', today()->toDateString())->value('valor'), 2),
        ];
    }

    // ================= Apoyo =================

    private function fila(Producto $p): array
    {
        $factor = $p->factor();
        $lotes = $p->lotes->map(fn (Lote $l) => [
            'id' => $l->id,
            'numero_lote' => $l->numero_lote,
            'fecha_vencimiento' => $l->fecha_vencimiento->toDateString(),
            'cantidad' => (float) $l->cantidad,
            'costo_unitario' => (float) $l->costo_unitario,
            'valor' => round((float) $l->cantidad / $factor * (float) $l->costo_unitario, 2),
            'estado' => $this->estadoLote($l),
        ]);
        $vigentes = $lotes->where('estado', '!=', 'vencido');
        $stock = round($vigentes->sum('cantidad'), 2);

        return [
            ...$p->only([
                'id', 'codigo', 'unidad_venta', 'fraccionable', 'unidades_por_presentacion', 'unidad_fraccion', 'stock_minimo', 'activo',
            ]),
            'descripcion' => $p->descripcionCompleta(),
            'laboratorio' => $p->laboratorio?->nombre,
            'stock' => $stock,
            'stock_vencido' => round($lotes->where('estado', 'vencido')->sum('cantidad'), 2),
            'bajo_minimo' => $p->stock_minimo > 0 && $stock < $p->stock_minimo * $factor,
            'proximo_vencimiento' => $vigentes->first()['fecha_vencimiento'] ?? null,
            'valor' => round($lotes->sum('valor'), 2),
            'lotes' => $lotes->values(),
        ];
    }

    private function estadoLote(Lote $lote): string
    {
        $vence = $lote->fecha_vencimiento->toDateString();

        return match (true) {
            $vence < today()->toDateString() => 'vencido',
            $vence <= today()->addDays(self::DIAS_POR_VENCER)->toDateString() => 'por_vencer',
            default => 'vigente',
        };
    }

    private function vista(Request $request): string
    {
        $vista = $request->string('vista', 'todos')->toString();

        return array_key_exists($vista, self::VISTAS) ? $vista : 'todos';
    }
}