<?php

namespace App\Services;

use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Empresa;
use App\Models\Guia;
use App\Models\Serie;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guías de remisión: datos sugeridos (desde una factura o la última guía) y registro con su correlativo.
 * El envío a SUNAT lo hace GuiaSunatService.
 */
class GuiaService
{
    public function registrar(array $datos, User $usuario): Guia
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $serie = Serie::query()
                ->where('tipo_comprobante', Guia::TIPO)
                ->where('sucursal_id', $usuario->sucursal_id)
                ->where('activo', true)
                ->first();

            if (! $serie) {
                throw ValidationException::withMessages(['serie' => 'No hay una serie de guías (T001) activa para tu local. Créala en Configuración.']);
            }

            if (! empty($datos['comprobante_id'])) {
                $comprobante = Comprobante::query()
                    ->where('sucursal_id', $usuario->sucursal_id)
                    ->findOrFail($datos['comprobante_id']);
                if (! $comprobante->tieneValidez()) {
                    throw ValidationException::withMessages(['comprobante_id' => 'Ese comprobante no tiene validez (rechazado o dado de baja): no puede sustentar un traslado.']);
                }
            }

            [$numSerie, $correlativo] = Serie::siguienteCorrelativo($serie->id);

            $guia = Guia::create([
                ...collect($datos)->except(['items'])->all(),
                'sucursal_id' => $usuario->sucursal_id,
                'user_id' => $usuario->id,
                'serie' => $numSerie,
                'correlativo' => $correlativo,
                'fecha_emision' => now(),
                'partida_direccion' => mb_strtoupper(trim($datos['partida_direccion'])),
                'llegada_direccion' => mb_strtoupper(trim($datos['llegada_direccion'])),
                'destinatario_nombre' => mb_strtoupper(trim($datos['destinatario_nombre'])),
                'vehiculo_placa' => isset($datos['vehiculo_placa']) ? strtoupper(str_replace([' ', '-'], '', $datos['vehiculo_placa'])) : null,
                'estado' => 'pendiente',
            ]);

            foreach ($datos['items'] as $item) {
                $guia->items()->create([
                    'producto_id' => $item['producto_id'] ?? null,
                    'codigo' => $item['codigo'] ?? null,
                    'descripcion' => mb_strtoupper(trim($item['descripcion'])),
                    'unidad' => $item['unidad'],
                    'cantidad' => $item['cantidad'],
                ]);
            }

            return $guia;
        });
    }

    /** Datos para empezar el formulario: desde una factura (si se indica) y con el transporte de la última guía. */
    public function sugerencia(?Comprobante $comprobante, User $usuario): array
    {
        $empresa = Empresa::actual();
        $ultima = Guia::query()->where('sucursal_id', $usuario->sucursal_id)->latest('id')->first();

        $datos = [
            'comprobante_id' => null,
            'fecha_traslado' => today()->toDateString(),
            'motivo' => '01',
            'motivo_descripcion' => '',
            'modalidad' => $ultima?->modalidad ?? '01',
            'destinatario_tipo_doc' => '6',
            'destinatario_num_doc' => '',
            'destinatario_nombre' => '',
            'partida_ubigeo' => $empresa->ubigeo,
            'partida_direccion' => $usuario->sucursal?->direccion ?: $empresa->direccion,
            'llegada_ubigeo' => '',
            'llegada_direccion' => '',
            'peso_total' => null,
            'unidad_peso' => 'KGM',
            // El transporte suele repetirse: se propone el de la última guía
            'transportista_ruc' => $ultima?->transportista_ruc ?? '',
            'transportista_nombre' => $ultima?->transportista_nombre ?? '',
            'transportista_mtc' => $ultima?->transportista_mtc ?? '',
            'vehiculo_placa' => $ultima?->vehiculo_placa ?? '',
            'conductor_tipo_doc' => $ultima?->conductor_tipo_doc ?? '1',
            'conductor_num_doc' => $ultima?->conductor_num_doc ?? '',
            'conductor_nombres' => $ultima?->conductor_nombres ?? '',
            'conductor_apellidos' => $ultima?->conductor_apellidos ?? '',
            'conductor_licencia' => $ultima?->conductor_licencia ?? '',
            'observaciones' => '',
            'items' => [],
        ];

        if ($comprobante) {
            $comprobante->loadMissing('cliente', 'items');
            $cliente = $comprobante->cliente;

            $datos = [
                ...$datos,
                'comprobante_id' => $comprobante->id,
                'destinatario_tipo_doc' => $cliente->tipo_documento,
                'destinatario_num_doc' => $cliente->numero_documento,
                'destinatario_nombre' => $cliente->razon_social,
                'llegada_ubigeo' => $cliente->ubigeo ?? '',
                'llegada_direccion' => $cliente->direccion ?? '',
                'items' => $comprobante->items->map(fn (ComprobanteItem $i) => [
                    'producto_id' => $i->producto_id,
                    'codigo' => $i->codigo,
                    // En la guía va el lote y el vencimiento: DIGEMID lo exige en el traslado de medicamentos
                    'descripcion' => trim($i->descripcion
                        .($i->numero_lote ? " - LOTE {$i->numero_lote}" : '')
                        .($i->fecha_vencimiento ? ' - VENCE '.$i->fecha_vencimiento->format('m/Y') : '')
                        .($i->bonificacion ? ' - BONIFICACION' : '')),
                    'unidad' => $i->unidad_sunat ?: 'NIU',
                    'cantidad' => (float) $i->cantidad,
                ])->values()->all(),
            ];
        }

        return $datos;
    }
}