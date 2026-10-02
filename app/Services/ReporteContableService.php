<?php

namespace App\Services;

use App\Models\Compra;
use App\Models\Comprobante;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Información mensual para el contador: registro de ventas, registro de compras,
 * resumen de IGV y archivos XML/CDR. Es de toda la empresa (todas las sucursales).
 */
class ReporteContableService
{
    /** Comprobantes con valor tributario (la nota de venta es interna y no entra). */
    public const TIPOS_TRIBUTARIOS = ['01', '03', '07', '08'];

    /** Estados que aún no tienen respuesta definitiva de SUNAT. */
    public const ESTADOS_PENDIENTES = ['pendiente', 'error', 'enviado'];

    /** Primer y último instante del periodo "AAAA-MM". */
    public static function rango(string $periodo): array
    {
        $inicio = Carbon::createFromFormat('Y-m-d', $periodo.'-01')->startOfDay();

        return [$inicio, $inicio->copy()->endOfMonth()];
    }

    /**
     * Registro de ventas: una fila por comprobante, en orden.
     * Las notas de crédito restan (montos negativos) y los rechazados van en cero, como en el registro oficial.
     */
    public function ventas(string $periodo): Collection
    {
        [$inicio, $fin] = self::rango($periodo);

        return Comprobante::query()
            ->with(['cliente:id,tipo_documento,numero_documento,razon_social', 'referencia:id,tipo_comprobante,serie,correlativo,fecha_emision'])
            ->whereIn('tipo_comprobante', self::TIPOS_TRIBUTARIOS)
            ->whereBetween('fecha_emision', [$inicio, $fin])
            ->orderBy('fecha_emision')
            ->orderBy('tipo_comprobante')
            ->orderBy('serie')
            ->orderBy('correlativo')
            ->get()
            ->map(function (Comprobante $c) {
                $valido = $c->estado !== 'rechazado';
                $factor = ! $valido ? 0 : ($c->tipo_comprobante === '07' ? -1 : 1);

                return [
                    'id' => $c->id,
                    'fecha' => $c->fecha_emision->toDateString(),
                    'tipo' => $c->tipo_comprobante,
                    'tipo_nombre' => $c->tipo_nombre,
                    'serie' => $c->serie,
                    'numero' => (int) $c->correlativo,
                    'cliente_tipo_doc' => $c->cliente?->tipo_documento,
                    'cliente_doc' => $c->cliente?->numero_documento,
                    'cliente' => $c->cliente?->razon_social,
                    'gravado' => round($factor * (float) $c->op_gravadas, 2),
                    'exonerado' => round($factor * (float) $c->op_exoneradas, 2),
                    'inafecto' => round($factor * (float) $c->op_inafectas, 2),
                    'igv' => round($factor * (float) $c->igv, 2),
                    'total' => round($factor * (float) $c->total, 2),
                    'estado' => $c->estado,
                    'valido' => $valido,
                    'referencia' => $c->referencia ? $c->referencia->tipo_comprobante.' '.$c->referencia->numero : null,
                    'referencia_fecha' => $c->referencia?->fecha_emision?->toDateString(),
                ];
            });
    }

    /** Registro de compras del periodo (por fecha del documento del proveedor). */
    public function compras(string $periodo): Collection
    {
        [$inicio, $fin] = self::rango($periodo);

        return Compra::query()
            ->with('proveedor:id,ruc,razon_social')
            ->whereBetween('fecha_emision', [$inicio->toDateString(), $fin->toDateString()])
            ->orderBy('fecha_emision')
            ->orderBy('id')
            ->get()
            ->map(function (Compra $c) {
                $valida = $c->estado !== 'anulada';
                $factor = $valida ? 1 : 0;

                return [
                    'id' => $c->id,
                    'fecha' => $c->fecha_emision->toDateString(),
                    'tipo' => $c->tipo_documento,
                    'tipo_nombre' => Compra::TIPOS_DOCUMENTO[$c->tipo_documento] ?? $c->tipo_documento,
                    'serie' => $c->serie,
                    'numero' => $c->numero,
                    'proveedor_ruc' => $c->proveedor?->ruc,
                    'proveedor' => $c->proveedor?->razon_social,
                    'gravado' => round($factor * (float) $c->op_gravadas, 2),
                    'exonerado' => round($factor * (float) $c->op_exoneradas, 2),
                    'igv' => round($factor * (float) $c->igv, 2),
                    'total' => round($factor * (float) $c->total, 2),
                    'estado' => $c->estado,
                    'valida' => $valida,
                ];
            });
    }

    /**
     * Resumen para revisar antes de declarar.
     * Solo las facturas de compra dan crédito fiscal (las boletas no).
     */
    public function resumen(string $periodo, Collection $ventas, Collection $compras): array
    {
        [$inicio, $fin] = self::rango($periodo);
        $validas = $ventas->where('valido', true);
        $comprasValidas = $compras->where('valida', true);

        $igvVentas = round($validas->sum('igv'), 2);
        $igvCompras = round($comprasValidas->where('tipo', '01')->sum('igv'), 2);

        return [
            'ventas_gravadas' => round($validas->sum('gravado'), 2),
            'ventas_exoneradas' => round($validas->sum('exonerado') + $validas->sum('inafecto'), 2),
            'ventas_total' => round($validas->sum('total'), 2),
            'igv_ventas' => $igvVentas,
            'compras_gravadas' => round($comprasValidas->sum('gravado'), 2),
            'compras_total' => round($comprasValidas->sum('total'), 2),
            'igv_compras' => $igvCompras,
            // Positivo: IGV a pagar; negativo: saldo a favor para el mes siguiente
            'igv_resultado' => round($igvVentas - $igvCompras, 2),
            'cantidad_ventas' => $validas->count(),
            'cantidad_compras' => $comprasValidas->count(),
            'pendientes_sunat' => $ventas->whereIn('estado', self::ESTADOS_PENDIENTES)->count(),
            'rechazados' => $ventas->where('valido', false)->count(),
            // Informativo: las notas de venta no son comprobantes de pago
            'notas_venta' => round((float) Comprobante::query()
                ->where('tipo_comprobante', Comprobante::NOTA_VENTA)
                ->whereBetween('fecha_emision', [$inicio, $fin])
                ->sum('total'), 2),
        ];
    }

    /**
     * ZIP con los XML y CDR del periodo. Devuelve la ruta del archivo temporal,
     * o null si no hay archivos.
     */
    public function zipXmlCdr(string $periodo): ?string
    {
        [$inicio, $fin] = self::rango($periodo);
        $disco = Storage::disk('local');

        $comprobantes = Comprobante::query()
            ->whereIn('tipo_comprobante', self::TIPOS_TRIBUTARIOS)
            ->whereBetween('fecha_emision', [$inicio, $fin])
            ->whereNotNull('xml_path')
            ->get(['id', 'xml_path', 'cdr_path']);

        $ruta = tempnam(sys_get_temp_dir(), 'sunat');
        $zip = new ZipArchive();
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $agregados = 0;
        foreach ($comprobantes as $c) {
            if ($disco->exists($c->xml_path)) {
                $zip->addFile($disco->path($c->xml_path), 'XML/'.basename($c->xml_path));
                $agregados++;
            }
            if ($c->cdr_path && $disco->exists($c->cdr_path)) {
                $zip->addFile($disco->path($c->cdr_path), 'CDR/'.basename($c->cdr_path));
            }
        }
        $zip->close();

        if ($agregados === 0) {
            @unlink($ruta);

            return null;
        }

        return $ruta;
    }
}