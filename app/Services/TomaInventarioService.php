<?php

namespace App\Services;

use App\Models\Laboratorio;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\TomaInventario;
use App\Models\TomaItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Toma de inventario: abrir (congela el stock de los lotes), contar, y aprobar
 * (las diferencias se corrigen con dos ajustes: faltantes y sobrantes en conteo).
 */
class TomaInventarioService
{
    public function __construct(private AjusteService $ajustes) {}

    public function abrir(array $datos, User $usuario): TomaInventario
    {
        return DB::transaction(function () use ($datos, $usuario) {
            // Una sola toma abierta por local, para que los conteos no se crucen
            $abierta = TomaInventario::query()
                ->where('sucursal_id', $usuario->sucursal_id)
                ->where('estado', 'en_conteo')
                ->lockForUpdate()
                ->first();
            if ($abierta) {
                throw ValidationException::withMessages(['alcance' => "Ya hay una toma en conteo ({$abierta->numero}). Apruébala o anúlala primero."]);
            }

            $alcance = $datos['alcance'];
            $valor = $datos['alcance_valor'] ?? null;
            $texto = match ($alcance) {
                'laboratorio' => 'Laboratorio '.(Laboratorio::find($valor)?->nombre ?? '?'),
                'categoria' => "Categoría {$valor}",
                default => 'Todo el almacén',
            };

            $toma = TomaInventario::create([
                'sucursal_id' => $usuario->sucursal_id,
                'user_id' => $usuario->id,
                'alcance' => $alcance,
                'alcance_valor' => $valor,
                'alcance_texto' => $texto,
                'observacion' => $datos['observacion'] ?? null,
            ]);

            // Se "congela" el stock de cada lote con existencias en este momento
            $lotes = Lote::query()
                ->where('sucursal_id', $usuario->sucursal_id)
                ->where('cantidad', '>', 0)
                ->whereHas('producto', fn ($q) => $q
                    ->when($alcance === 'laboratorio', fn ($p) => $p->where('laboratorio_id', $valor))
                    ->when($alcance === 'categoria', fn ($p) => $p->where('categoria', $valor)))
                ->get();

            if ($lotes->isEmpty()) {
                throw ValidationException::withMessages(['alcance' => 'No hay lotes con stock para ese alcance.']);
            }

            $ahora = now();
            TomaItem::insert($lotes->map(fn (Lote $l) => [
                'toma_id' => $toma->id,
                'producto_id' => $l->producto_id,
                'lote_id' => $l->id,
                'numero_lote' => $l->numero_lote,
                'fecha_vencimiento' => $l->fecha_vencimiento->toDateString(),
                'stock_sistema' => $l->cantidad,
                'contado' => null,
                'costo_unitario' => $l->costo_unitario,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all());

            return $toma;
        });
    }

    /** Guarda lo contado (en unidad mínima). Un valor null deja el lote "sin contar". */
    public function guardarConteo(TomaInventario $toma, array $conteos): void
    {
        $this->exigirEnConteo($toma);

        DB::transaction(function () use ($toma, $conteos) {
            foreach ($conteos as $c) {
                $toma->items()->whereKey($c['id'])->update([
                    'contado' => $c['contado'] === null || $c['contado'] === '' ? null : round((float) $c['contado'], 2),
                ]);
            }
        });
    }

    /** Lote que se encontró en el almacén pero no figuraba en el sistema (o no tenía stock). */
    public function agregarLote(TomaInventario $toma, array $datos): TomaItem
    {
        $this->exigirEnConteo($toma);

        $producto = Producto::findOrFail($datos['producto_id']);
        $numero = mb_strtoupper(trim($datos['numero_lote']));

        if ($toma->items()->where('producto_id', $producto->id)->where('numero_lote', $numero)->exists()) {
            throw ValidationException::withMessages(['numero_lote' => "El lote {$numero} ya está en la lista: cuéntalo ahí."]);
        }

        // Si el lote existe en el sistema (con stock 0), se usa ese
        $lote = Lote::query()
            ->where('producto_id', $producto->id)
            ->where('sucursal_id', $toma->sucursal_id)
            ->where('numero_lote', $numero)
            ->first();

        return $toma->items()->create([
            'producto_id' => $producto->id,
            'lote_id' => $lote?->id,
            'numero_lote' => $numero,
            'fecha_vencimiento' => $lote ? $lote->fecha_vencimiento->toDateString() : $datos['fecha_vencimiento'],
            'stock_sistema' => $lote ? (float) $lote->cantidad : 0,
            'contado' => round((float) $datos['contado'], 2),
            'costo_unitario' => $lote ? (float) $lote->costo_unitario : (float) ($datos['costo_unitario'] ?? $producto->costo),
        ]);
    }

    /**
     * Aprueba la toma: los faltantes salen con un ajuste de salida y los sobrantes entran con uno de entrada.
     * La diferencia se calcula contra el stock congelado al abrir la toma.
     */
    public function aprobar(TomaInventario $toma, User $usuario): TomaInventario
    {
        $this->exigirEnConteo($toma);

        return DB::transaction(function () use ($toma, $usuario) {
            $items = $toma->items()->get();

            $sinContar = $items->whereNull('contado')->count();
            if ($sinContar > 0) {
                throw ValidationException::withMessages(['toma' => "Faltan contar {$sinContar} lote(s). Si un lote no está en el almacén, pon 0."]);
            }

            $obs = "Toma de inventario {$toma->numero} ({$toma->alcance_texto}).";
            $faltantes = $items->filter(fn (TomaItem $i) => $i->diferencia() < 0);
            $sobrantes = $items->filter(fn (TomaItem $i) => $i->diferencia() > 0);

            // "por_fraccion" => la cantidad va en unidad mínima, igual que en la toma
            $salida = $faltantes->isEmpty() ? null : $this->ajustes->registrar([
                'tipo' => 'salida',
                'motivo' => 'faltante',
                'observacion' => $obs.' Faltantes encontrados en el conteo físico.',
                'items' => $faltantes->map(fn (TomaItem $i) => [
                    'producto_id' => $i->producto_id,
                    'lote_id' => $i->lote_id,
                    'cantidad' => abs($i->diferencia()),
                    'por_fraccion' => true,
                ])->values()->all(),
            ], $usuario);

            $entrada = $sobrantes->isEmpty() ? null : $this->ajustes->registrar([
                'tipo' => 'entrada',
                'motivo' => 'sobrante',
                'observacion' => $obs.' Sobrantes encontrados en el conteo físico.',
                'items' => $sobrantes->map(fn (TomaItem $i) => [
                    'producto_id' => $i->producto_id,
                    'numero_lote' => $i->numero_lote,
                    'fecha_vencimiento' => $i->fecha_vencimiento->toDateString(),
                    'cantidad' => $i->diferencia(),
                    'por_fraccion' => true,
                    'costo_unitario' => $i->costo_unitario,
                ])->values()->all(),
            ], $usuario);

            $toma->update([
                'estado' => 'aprobada',
                'aprobado_por' => $usuario->id,
                'aprobada_at' => now(),
                'ajuste_salida_id' => $salida?->id,
                'ajuste_entrada_id' => $entrada?->id,
            ]);

            return $toma;
        });
    }

    public function anular(TomaInventario $toma): void
    {
        $this->exigirEnConteo($toma);
        $toma->update(['estado' => 'anulada']);
    }

    private function exigirEnConteo(TomaInventario $toma): void
    {
        if (! $toma->enConteo()) {
            throw ValidationException::withMessages(['toma' => "La toma {$toma->numero} ya está ".mb_strtolower(TomaInventario::ESTADOS[$toma->estado]).'.']);
        }
    }
}