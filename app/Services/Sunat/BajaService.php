<?php

namespace App\Services\Sunat;

use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\User;
use App\Services\InventarioService;
use Greenter\Model\Response\BillResult;
use Greenter\See;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Comunicación de baja: anula ante SUNAT una factura o boleta ya aceptada.
 * - Factura: Comunicación de Baja (RA-AAAAMMDD-n).
 * - Boleta: resumen diario con el comprobante en estado "3" = anulado (RC-AAAAMMDD-n).
 * En ambos casos SUNAT responde con un ticket que se consulta unos segundos después.
 * Si la acepta: el comprobante queda "anulado", la mercadería vuelve al stock y deja de ser deuda.
 */
class BajaService
{
    public function __construct(
        private GreenterFactory $factory,
        private DocumentoBuilder $builder,
        private InventarioService $inventario,
    ) {}

    /** Envía la comunicación de baja. Devuelve el mensaje para el usuario. */
    public function solicitar(Comprobante $comprobante, string $motivo, User $usuario): string
    {
        $empresa = Empresa::actual();

        // 1. Se valida y se reserva el número del documento de baja (bloqueando para no duplicar)
        DB::transaction(function () use ($comprobante, $motivo, $usuario) {
            Empresa::query()->lockForUpdate()->first();
            $comprobante->refresh();

            if ($impedimento = $comprobante->impedimentoBaja()) {
                throw ValidationException::withMessages(['motivo' => $impedimento]);
            }

            $prefijo = ($comprobante->tipo_comprobante === '01' ? 'RA' : 'RC').'-'.now()->format('Ymd').'-';

            $comprobante->update([
                'baja_estado' => 'enviada',
                'baja_motivo' => $motivo,
                'baja_documento' => $prefijo.$this->siguienteCorrelativo($prefijo),
                'baja_ticket' => null,
                'baja_codigo' => null,
                'baja_descripcion' => 'Enviando a SUNAT...',
                'baja_cdr_path' => null,
                'baja_user_id' => $usuario->id,
                'baja_at' => now(),
            ]);
        });

        // 2. Envío a SUNAT
        try {
            $see = $this->factory->crear($empresa);
            $correlativo = (string) substr($comprobante->baja_documento, strrpos($comprobante->baja_documento, '-') + 1);

            $documento = $comprobante->tipo_comprobante === '01'
                ? $this->builder->baja($comprobante, $empresa, $correlativo, $motivo)
                : $this->builder->resumen($comprobante, $empresa, $correlativo, '3');

            $resultado = $see->send($documento);
            $this->guardarXml($comprobante, $empresa, $see->getFactory()->getLastXml());

            if (! $resultado->isSuccess()) {
                $this->registrarError($comprobante, $resultado);

                return $this->mensaje($comprobante->refresh());
            }

            $comprobante->update([
                'baja_ticket' => $resultado->getTicket(),
                'baja_descripcion' => 'Baja enviada. Esperando respuesta de SUNAT.',
            ]);
        } catch (Throwable $e) {
            $comprobante->update([
                'baja_estado' => 'error',
                'baja_descripcion' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return $this->mensaje($comprobante);
        }

        // 3. SUNAT suele procesar el ticket en pocos segundos
        for ($intento = 0; $intento < 3 && $comprobante->baja_estado === 'enviada'; $intento++) {
            sleep(2);
            $this->consultar($comprobante);
        }

        return $this->mensaje($comprobante->refresh());
    }

    /** Consulta el ticket de una baja enviada. */
    public function consultar(Comprobante $comprobante): void
    {
        if ($comprobante->baja_estado !== 'enviada' || ! $comprobante->baja_ticket) {
            return;
        }

        try {
            $empresa = Empresa::actual();
            $estado = $this->factory->crear($empresa)->getStatus($comprobante->baja_ticket);
        } catch (Throwable $e) {
            // Falla de conexión: la baja sigue enviada y se vuelve a consultar después
            $comprobante->update(['baja_descripcion' => 'No se pudo consultar: '.mb_substr($e->getMessage(), 0, 900)]);

            return;
        }

        if ($estado->getCode() === '98') {
            $comprobante->update(['baja_descripcion' => 'SUNAT aún está procesando la baja.']);

            return;
        }

        if (! $estado->isSuccess() || ! $estado->getCdrResponse()) {
            $this->registrarError($comprobante, $estado);

            return;
        }

        $cdr = $estado->getCdrResponse();
        $codigo = (int) $cdr->getCode();

        $cdrPath = $this->carpeta($comprobante, $empresa).'/R-'.$empresa->ruc.'-'.$comprobante->baja_documento.'.zip';
        Storage::disk('local')->put($cdrPath, $estado->getCdrZip());

        // 0 = aceptada; 4000 en adelante = aceptada con observaciones
        if ($codigo === 0 || $codigo >= 4000) {
            $this->aceptar($comprobante, (string) $cdr->getCode(), $cdr->getDescription(), $cdrPath);

            return;
        }

        $comprobante->update([
            'baja_estado' => 'rechazada',
            'baja_codigo' => (string) $cdr->getCode(),
            'baja_descripcion' => $cdr->getDescription(),
            'baja_cdr_path' => $cdrPath,
        ]);
    }

    /** SUNAT aceptó la baja: el comprobante pierde validez y se deshace su efecto. */
    private function aceptar(Comprobante $comprobante, string $codigo, ?string $descripcion, string $cdrPath): void
    {
        DB::transaction(function () use ($comprobante, $codigo, $descripcion, $cdrPath) {
            $comprobante->update([
                'estado' => 'anulado',
                'saldo' => 0, // ya no es deuda del cliente
                'baja_estado' => 'aceptada',
                'baja_codigo' => $codigo,
                'baja_descripcion' => $descripcion,
                'baja_cdr_path' => $cdrPath,
            ]);

            // La mercadería vuelve a sus lotes (si ya se devolvió, no lo repite)
            $this->inventario->revertirSalidas($comprobante, $comprobante->baja_user_id, 'baja_sunat');
        });
    }

    private function registrarError(Comprobante $comprobante, BillResult $resultado): void
    {
        $error = $resultado->getError();
        $codigo = (string) $error?->getCode();

        // 2000-3999: SUNAT rechazó la baja. Otros (conexión, servidor ocupado): se puede reintentar.
        $rechazo = ctype_digit($codigo) && (int) $codigo >= 2000 && (int) $codigo < 4000;

        $comprobante->update([
            'baja_estado' => $rechazo ? 'rechazada' : 'error',
            'baja_codigo' => $codigo ?: null,
            'baja_descripcion' => $error?->getMessage() ?: 'SUNAT no respondió.',
        ]);
    }

    /**
     * Correlativo del día. Las bajas de boletas (RC) comparten la numeración con los resúmenes de boletas.
     */
    private function siguienteCorrelativo(string $prefijo): int
    {
        $total = Comprobante::query()->where('baja_documento', 'like', $prefijo.'%')->count();

        if (str_starts_with($prefijo, 'RC')) {
            $total += Comprobante::query()->where('resumen', 'like', $prefijo.'%')->count();
        }

        return $total + 1;
    }

    private function guardarXml(Comprobante $comprobante, Empresa $empresa, ?string $xml): void
    {
        if ($xml) {
            Storage::disk('local')->put($this->carpeta($comprobante, $empresa).'/'.$empresa->ruc.'-'.$comprobante->baja_documento.'.xml', $xml);
        }
    }

    private function carpeta(Comprobante $comprobante, Empresa $empresa): string
    {
        return 'sunat/'.$empresa->entorno.'/'.$comprobante->fecha_emision->format('Y/m');
    }

    private function mensaje(Comprobante $c): string
    {
        return match ($c->baja_estado) {
            'aceptada' => "SUNAT aceptó la baja de {$c->numero} ({$c->baja_documento}). El comprobante quedó anulado y la mercadería volvió al stock.",
            'enviada' => 'SUNAT aún está procesando la baja. Vuelve a consultar en unos segundos.',
            'rechazada' => "SUNAT rechazó la baja: {$c->baja_descripcion}",
            default => "No se pudo enviar la baja: {$c->baja_descripcion}",
        };
    }
}