<?php

namespace App\Services;

use App\Models\Ajuste;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra un ajuste de inventario: saca o ingresa stock lote por lote, con su motivo,
 * y deja cada movimiento en el kárdex (motivo "ajuste", con el ajuste como documento).
 */
class AjusteService
{
    public function __construct(private InventarioService $inventario) {}

    /**
     * @param  array  $datos  tipo, motivo, observacion, items[]
     *                        items[]: producto_id, cantidad, por_fraccion,
     *                        salida: lote_id · entrada: numero_lote, fecha_vencimiento, costo_unitario
     */
    public function registrar(array $datos, User $usuario): Ajuste
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $tipo = $datos['tipo'];

            $ajuste = Ajuste::create([
                'sucursal_id' => $usuario->sucursal_id,
                'user_id' => $usuario->id,
                'tipo' => $tipo,
                'motivo' => $datos['motivo'],
                'observacion' => trim($datos['observacion']),
            ]);

            $valorTotal = 0;
            foreach ($datos['items'] as $i => $linea) {
                $producto = Producto::findOrFail($linea['producto_id']);
                $factor = $producto->factor();

                // La cantidad llega en la unidad elegida (caja o unidad suelta): se pasa a unidad mínima
                $porFraccion = $producto->fraccionable && ! empty($linea['por_fraccion']);
                $unidades = round((float) $linea['cantidad'] * ($porFraccion ? 1 : $factor), 2);

                $lote = $tipo === 'salida'
                    ? $this->salida($producto, $linea, $unidades, $usuario, $ajuste, $i)
                    : $this->entrada($producto, $linea, $unidades, $usuario, $ajuste);

                $costo = (float) $lote->costo_unitario;
                $valor = round($unidades / $factor * $costo, 2);
                $valorTotal += $valor;

                $ajuste->items()->create([
                    'producto_id' => $producto->id,
                    'lote_id' => $lote->id,
                    'numero_lote' => $lote->numero_lote,
                    'fecha_vencimiento' => $lote->fecha_vencimiento,
                    'cantidad' => $unidades,
                    'costo_unitario' => $costo,
                    'valor' => $valor,
                ]);
            }

            $ajuste->update(['valor' => round($valorTotal, 2)]);

            return $ajuste;
        });
    }

    /** Salida: se saca del lote elegido (puede ser un lote vencido). */
    private function salida(Producto $producto, array $linea, float $unidades, User $usuario, Ajuste $ajuste, int $i): Lote
    {
        $lote = Lote::query()
            ->where('id', $linea['lote_id'] ?? 0)
            ->where('producto_id', $producto->id)
            ->where('sucursal_id', $usuario->sucursal_id)
            ->first();

        if (! $lote) {
            throw ValidationException::withMessages(["items.{$i}.lote_id" => "Elige el lote de {$producto->descripcionCompleta()}."]);
        }

        return $this->inventario->descontarDeLote($lote->id, $unidades, $usuario->id, 'ajuste', $ajuste);
    }

    /** Entrada: suma a un lote existente o crea uno nuevo. */
    private function entrada(Producto $producto, array $linea, float $unidades, User $usuario, Ajuste $ajuste): Lote
    {
        $numeroLote = mb_strtoupper(trim($linea['numero_lote']));
        $existente = Lote::query()
            ->where('producto_id', $producto->id)
            ->where('sucursal_id', $usuario->sucursal_id)
            ->where('numero_lote', $numeroLote)
            ->first();

        // Costo: el indicado; si no, el del lote existente o el último costo del producto
        $costo = isset($linea['costo_unitario']) && $linea['costo_unitario'] !== null && $linea['costo_unitario'] !== ''
            ? (float) $linea['costo_unitario']
            : (float) ($existente?->costo_unitario ?? $producto->costo);

        return $this->inventario->ingresarLote([
            'producto_id' => $producto->id,
            'sucursal_id' => $usuario->sucursal_id,
            'numero_lote' => $numeroLote,
            'fecha_vencimiento' => $existente ? $existente->fecha_vencimiento->toDateString() : $linea['fecha_vencimiento'],
            'cantidad' => $unidades,
            'costo_unitario' => $costo,
        ], $usuario->id, 'ajuste', $ajuste);
    }
}