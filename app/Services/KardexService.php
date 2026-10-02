<?php

namespace App\Services;

use App\Models\Compra;
use App\Models\Comprobante;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Ajuste;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Kárdex (registro de inventario permanente) de un producto: cada entrada y salida con su saldo.
 * Las cantidades van en unidad mínima, igual que se guardan en los lotes.
 */
class KardexService
{
    /** Máximo de movimientos por consulta (si hay más, se pide acortar las fechas). */
    public const MAX_FILAS = 3000;

    /**
     * @return array{saldo_inicial: float, entradas: float, salidas: float, saldo_final: float, filas: array, recortado: bool}
     */
    public function generar(Producto $producto, int $sucursalId, string $desde, string $hasta, ?int $loteId = null): array
    {
        $inicio = CarbonImmutable::parse($desde)->startOfDay();
        $fin = CarbonImmutable::parse($hasta)->endOfDay();

        $base = fn (): Builder => MovimientoInventario::query()
            ->where('producto_id', $producto->id)
            ->where('sucursal_id', $sucursalId)
            ->when($loteId, fn ($q) => $q->where('lote_id', $loteId));

        // Saldo con el que empieza el periodo
        $saldoInicial = round((float) $base()
            ->where('created_at', '<', $inicio)
            ->toBase()
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN cantidad ELSE -cantidad END), 0) AS saldo")
            ->value('saldo'), 2);

        // Totales del periodo (se calculan aparte para que cuadren aunque la lista se recorte)
        $totales = $base()
            ->whereBetween('created_at', [$inicio, $fin])
            ->toBase()
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN cantidad ELSE 0 END), 0) AS entradas")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'salida' THEN cantidad ELSE 0 END), 0) AS salidas")
            ->first();
        $entradas = round((float) $totales->entradas, 2);
        $salidas = round((float) $totales->salidas, 2);

        $movimientos = $base()
            ->whereBetween('created_at', [$inicio, $fin])
            ->with([
                'lote:id,numero_lote,fecha_vencimiento',
                'usuario:id,name',
                'referencia' => fn (MorphTo $m) => $m->morphWith([
                    Comprobante::class => ['cliente:id,razon_social'],
                    Compra::class => ['proveedor:id,razon_social'],
                ]),
            ])
            ->orderBy('id')
            ->limit(self::MAX_FILAS + 1)
            ->get();

        $saldo = $saldoInicial;
        $filas = [];
        foreach ($movimientos->take(self::MAX_FILAS) as $m) {
            $cantidad = (float) $m->cantidad;
            $esEntrada = $m->tipo === 'entrada';
            $saldo = round($saldo + ($esEntrada ? $cantidad : -$cantidad), 2);

            $filas[] = [
                'id' => $m->id,
                'fecha' => $m->created_at->format('Y-m-d H:i'),
                'tipo' => $m->tipo,
                'motivo' => MovimientoInventario::MOTIVOS[$m->motivo] ?? $m->motivo,
                ...$this->documento($m),
                'lote' => $m->lote?->numero_lote,
                'vencimiento' => $m->lote?->fecha_vencimiento?->toDateString(),
                'entrada' => $esEntrada ? $cantidad : null,
                'salida' => $esEntrada ? null : $cantidad,
                'saldo' => $saldo,
                'costo_unitario' => (float) $m->costo_unitario,
                'usuario' => $m->usuario?->name,
                'observacion' => $m->observacion,
            ];
        }

        return [
            'saldo_inicial' => $saldoInicial,
            'entradas' => $entradas,
            'salidas' => $salidas,
            'saldo_final' => round($saldoInicial + $entradas - $salidas, 2),
            'filas' => $filas,
            'recortado' => $movimientos->count() > self::MAX_FILAS,
        ];
    }

    private function documento(MovimientoInventario $m): array
    {
        $ref = $m->referencia;

        return match (true) {
            $ref instanceof Compra => [
                'documento' => 'Compra '.$ref->documento,
                'tercero' => $ref->proveedor?->razon_social,
                'url' => "/compras/{$ref->id}",
            ],
            $ref instanceof Comprobante => [
                'documento' => $ref->tipo_nombre.' '.$ref->numero,
                'tercero' => $ref->cliente?->razon_social,
                'url' => "/comprobantes/{$ref->id}",
            ],
            $ref instanceof Ajuste => [
                'documento' => 'Ajuste '.$ref->numero,
                'tercero' => $ref->motivo_nombre,
                'url' => "/ajustes/{$ref->id}",
            ],
            default => ['documento' => null, 'tercero' => null, 'url' => null],
        };
    }
}