<?php

namespace App\Services\Sunat;

use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Services\CobranzaService;
use App\Services\InventarioService;
use Greenter\Model\Response\BillResult;
use Greenter\See;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Envía los comprobantes a SUNAT y guarda la respuesta (CDR).
 * - Facturas y sus notas: envío individual, respuesta inmediata.
 * - Boletas y sus notas: resumen diario con ese único comprobante; SUNAT responde con un ticket
 *   que se consulta unos segundos después.
 * - Si SUNAT rechaza un comprobante, se deshace su efecto en el stock y en la deuda del cliente.
 */
class SunatService
{
    public function __construct(
        private GreenterFactory $factory,
        private DocumentoBuilder $builder,
        private InventarioService $inventario,
    ) {}

    public function enviar(Comprobante $comprobante): void
    {
        // Las notas de venta son internas: nunca van a SUNAT
        if ($comprobante->esInterno()) {
            return;
        }

        $comprobante->increment('intentos_envio');
        $empresa = Empresa::actual();

        try {
            $see = $this->factory->crear($empresa);

            if (! $comprobante->vaPorResumen()) {
                $this->enviarIndividual($see, $comprobante, $empresa);
            } elseif ($comprobante->ticket) {
                $this->consultarTicket($comprobante);
            } else {
                $this->enviarPorResumen($see, $comprobante, $empresa);
            }
        } catch (Throwable $e) {
            $comprobante->update([
                'estado' => 'error',
                'sunat_descripcion' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    }

    /** Factura o nota de factura: SUNAT devuelve el CDR en la misma llamada. */
    private function enviarIndividual(See $see, Comprobante $comprobante, Empresa $empresa): void
    {
        $resultado = $see->send($this->builder->documento($comprobante, $empresa));
        $this->guardarXml($comprobante, $empresa, $see->getFactory()->getLastXml());
        $this->procesarRespuesta($comprobante, $empresa, $resultado);
    }

    /** Boleta o nota de boleta: se informa con un resumen diario que contiene solo ese comprobante. */
    private function enviarPorResumen(See $see, Comprobante $comprobante, Empresa $empresa): void
    {
        // 1. La boleta también se firma: su XML y su hash van en la representación impresa
        $this->guardarXml($comprobante, $empresa, $see->getXmlSigned($this->builder->documento($comprobante, $empresa)));

        // 2. Resumen con correlativo propio del día (RC-AAAAMMDD-n)
        $correlativo = $this->siguienteCorrelativoResumen();
        $resultado = $see->send($this->builder->resumen($comprobante, $empresa, (string) $correlativo));

        if (! $resultado->isSuccess()) {
            $this->procesarError($comprobante, $resultado);

            return;
        }

        $comprobante->update([
            'estado' => 'enviado',
            'resumen' => 'RC-'.now()->format('Ymd').'-'.$correlativo,
            'ticket' => $resultado->getTicket(),
            'sunat_descripcion' => 'Resumen enviado. Esperando respuesta de SUNAT.',
            'enviado_at' => now(),
        ]);

        // 3. SUNAT suele procesar el ticket en pocos segundos
        for ($intento = 0; $intento < 3 && $comprobante->estado === 'enviado'; $intento++) {
            sleep(2);
            $this->consultarTicket($comprobante);
        }
    }

    /** Consulta el estado de un resumen enviado (ticket). */
    public function consultarTicket(Comprobante $comprobante): void
    {
        $empresa = Empresa::actual();
        $estado = $this->factory->crear($empresa)->getStatus($comprobante->ticket);

        if ($estado->getCode() === '98') {
            $comprobante->update(['sunat_descripcion' => 'SUNAT aún está procesando el resumen.']);

            return;
        }

        $this->procesarRespuesta($comprobante, $empresa, $estado);
    }

    /**
     * Un comprobante rechazado no tiene validez, así que se deshace su efecto:
     * - Venta rechazada: la mercadería vuelve a sus lotes y, si fue al crédito, deja de generar deuda.
     * - Nota de crédito rechazada: se retira lo que había reingresado y deja de rebajar la deuda.
     * Si ya se hizo antes, no hace nada.
     */
    public function devolverStockSiRechazado(Comprobante $comprobante): void
    {
        if ($comprobante->estado !== 'rechazado') {
            return;
        }

        // Saldo de la venta al crédito afectada (la misma venta, o la que modifica la nota)
        $alCredito = $comprobante->esNota() ? $comprobante->referencia : $comprobante;
        if ($alCredito) {
            CobranzaService::recalcularSaldo($alCredito);
        }

        if (! $comprobante->esNota()) {
            DB::transaction(fn () => $this->inventario->revertirSalidas($comprobante, auth()->id(), 'rechazo_sunat'));

            return;
        }

        $yaRevertida = MovimientoInventario::query()
            ->where('referencia_type', $comprobante->getMorphClass())
            ->where('referencia_id', $comprobante->getKey())
            ->where('motivo', 'rechazo_sunat')
            ->exists();

        if (! $yaRevertida) {
            try {
                DB::transaction(fn () => $this->inventario->revertirEntradas($comprobante, auth()->id(), 'rechazo_sunat'));
            } catch (ValidationException) {
                // Lo reingresado ya se volvió a vender: se deja como está para no dejar stock negativo
            }
        }
    }

    private function procesarRespuesta(Comprobante $comprobante, Empresa $empresa, BillResult $resultado): void
    {
        if (! $resultado->isSuccess()) {
            $this->procesarError($comprobante, $resultado);

            return;
        }

        $cdr = $resultado->getCdrResponse();
        $codigo = (int) $cdr->getCode();
        $notas = $cdr->getNotes() ?: [];

        $cdrPath = $this->carpeta($comprobante, $empresa).'/R-'.$comprobante->nombreArchivo($empresa->ruc).'.zip';
        Storage::disk('local')->put($cdrPath, $resultado->getCdrZip());

        $comprobante->update([
            'estado' => match (true) {
                $codigo === 0 && count($notas) > 0 => 'observado',
                $codigo === 0 => 'aceptado',
                $codigo >= 4000 => 'observado',
                default => 'rechazado',
            },
            'sunat_codigo' => (string) $cdr->getCode(),
            'sunat_descripcion' => $cdr->getDescription(),
            'sunat_observaciones' => $notas ?: null,
            'cdr_path' => $cdrPath,
            'enviado_at' => $comprobante->enviado_at ?? now(),
        ]);

        $this->devolverStockSiRechazado($comprobante);
    }

    private function procesarError(Comprobante $comprobante, $resultado): void
    {
        $error = $resultado->getError();
        $codigo = (string) $error?->getCode();

        // 2000-3999: SUNAT rechazó el comprobante (no se debe reenviar igual).
        // Otros (conexión, servidor ocupado): se puede reintentar.
        $rechazo = ctype_digit($codigo) && (int) $codigo >= 2000 && (int) $codigo < 4000;

        $comprobante->update([
            'estado' => $rechazo ? 'rechazado' : 'error',
            'sunat_codigo' => $codigo ?: null,
            'sunat_descripcion' => $error?->getMessage(),
            'ticket' => $rechazo ? $comprobante->ticket : null, // si falló el envío, se genera un resumen nuevo
        ]);

        $this->devolverStockSiRechazado($comprobante);
    }

    /** Número de resumen del día: se bloquea la fila de la empresa para que no se repita. */
    private function siguienteCorrelativoResumen(): int
    {
        return DB::transaction(function () {
            Empresa::query()->lockForUpdate()->first();

            return Comprobante::query()->where('resumen', 'like', 'RC-'.now()->format('Ymd').'-%')->count() + 1;
        });
    }

    private function guardarXml(Comprobante $comprobante, Empresa $empresa, ?string $xml): void
    {
        if (! $xml) {
            return;
        }

        $path = $this->carpeta($comprobante, $empresa).'/'.$comprobante->nombreArchivo($empresa->ruc).'.xml';
        Storage::disk('local')->put($path, $xml);

        $comprobante->update([
            'xml_path' => $path,
            'hash' => preg_match('/<ds:DigestValue>([^<]+)</', $xml, $m) ? $m[1] : null,
        ]);
    }

    private function carpeta(Comprobante $comprobante, Empresa $empresa): string
    {
        return 'sunat/'.$empresa->entorno.'/'.$comprobante->fecha_emision->format('Y/m');
    }
}