<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Lote;
use App\Models\Producto;
use App\Services\KardexService;
use App\Support\Excel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kárdex por producto (y opcionalmente por lote): pantalla y descarga en Excel.
 * Lo ven almacén, contador y administrador.
 */
class KardexController extends Controller
{
    public function index(Request $request, KardexService $kardex): Response
    {
        $sucursalId = $request->user()->sucursal_id;
        [$desde, $hasta] = $this->periodo($request);

        $producto = $request->filled('producto')
            ? Producto::with('laboratorio:id,nombre')->find($request->integer('producto'))
            : null;
        $loteId = $producto ? $this->loteValido($request, $producto, $sucursalId) : null;

        return Inertia::render('Inventario/Kardex', [
            'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'lote' => $loteId],
            'producto' => $producto ? $this->datosProducto($producto, $sucursalId) : null,
            'lotes' => $producto
                ? Lote::query()
                    ->where('producto_id', $producto->id)
                    ->where('sucursal_id', $sucursalId)
                    ->orderBy('fecha_vencimiento')
                    ->get(['id', 'numero_lote', 'fecha_vencimiento', 'cantidad'])
                    ->map(fn (Lote $l) => [
                        'id' => $l->id,
                        'numero_lote' => $l->numero_lote,
                        'fecha_vencimiento' => $l->fecha_vencimiento->toDateString(),
                        'cantidad' => (float) $l->cantidad,
                    ])
                : [],
            'kardex' => $producto ? $kardex->generar($producto, $sucursalId, $desde, $hasta, $loteId) : null,
        ]);
    }

    public function excel(Request $request, Producto $producto, KardexService $kardex): StreamedResponse
    {
        $sucursalId = $request->user()->sucursal_id;
        [$desde, $hasta] = $this->periodo($request);
        $loteId = $this->loteValido($request, $producto, $sucursalId);
        $datos = $kardex->generar($producto, $sucursalId, $desde, $hasta, $loteId);

        // Unidad en que están las cantidades (la unidad mínima del producto)
        $unidad = $producto->fraccionable ? $producto->unidad_fraccion : $producto->unidad_venta;
        $empresa = Empresa::actual();
        $lote = $loteId ? Lote::find($loteId)?->numero_lote : null;

        $filas = [['', 'SALDO INICIAL', '', '', '', '', null, null, $datos['saldo_inicial'], '', '']];
        foreach ($datos['filas'] as $f) {
            $filas[] = [
                $this->fecha(substr($f['fecha'], 0, 10)).substr($f['fecha'], 10),
                ($f['tipo'] === 'entrada' ? 'Entrada · ' : 'Salida · ').$f['motivo'],
                $f['documento'] ?? '',
                $f['tercero'] ?? '',
                $f['lote'] ?? '',
                $f['vencimiento'] ? $this->fecha($f['vencimiento']) : '',
                $f['entrada'],
                $f['salida'],
                $f['saldo'],
                $f['costo_unitario'],
                $f['usuario'] ?? '',
            ];
        }

        $subtitulo = implode(' · ', array_filter([
            $empresa?->razon_social,
            $empresa ? 'RUC '.$empresa->ruc : null,
            $producto->codigo.' '.$producto->descripcionCompleta(),
            $lote ? "Lote {$lote}" : null,
            'Del '.$this->fecha($desde).' al '.$this->fecha($hasta),
            "Cantidades en {$unidad}",
        ]));

        return Excel::descargar(
            "kardex-{$producto->codigo}-{$desde}-al-{$hasta}.xlsx",
            'Kárdex',
            $subtitulo,
            ['Fecha', 'Movimiento', 'Documento', 'Cliente / Proveedor', 'Lote', 'Vence', 'Entrada', 'Salida', 'Saldo', 'Costo unit. (sin IGV)', 'Usuario'],
            $filas,
            [6, 7],
        );
    }

    // ================= Apoyo =================

    /** Periodo pedido; por defecto, del 1 del mes a hoy. */
    private function periodo(Request $request): array
    {
        $desde = ($request->date('desde') ?? now()->startOfMonth())->toDateString();
        $hasta = ($request->date('hasta') ?? now())->toDateString();

        return $desde <= $hasta ? [$desde, $hasta] : [$hasta, $desde];
    }

    /** Solo se acepta un lote que sea de ese producto y de la sucursal del usuario. */
    private function loteValido(Request $request, Producto $producto, int $sucursalId): ?int
    {
        if (! $request->filled('lote')) {
            return null;
        }

        return Lote::query()
            ->where('id', $request->integer('lote'))
            ->where('producto_id', $producto->id)
            ->where('sucursal_id', $sucursalId)
            ->value('id');
    }

    private function datosProducto(Producto $producto, int $sucursalId): array
    {
        return [
            ...$producto->only([
                'id', 'codigo', 'nombre', 'unidad_venta', 'fraccionable', 'unidades_por_presentacion', 'unidad_fraccion',
            ]),
            'descripcion' => $producto->descripcionCompleta(),
            'laboratorio' => $producto->laboratorio?->nombre,
            // Stock físico total (incluye lotes vencidos que aún no se dieron de baja)
            'stock' => (float) Lote::query()->where('producto_id', $producto->id)->where('sucursal_id', $sucursalId)->sum('cantidad'),
        ];
    }

    private function fecha(string $iso): string
    {
        return implode('/', array_reverse(explode('-', $iso)));
    }
}