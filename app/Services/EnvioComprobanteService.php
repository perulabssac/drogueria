<?php

namespace App\Services;

use App\Mail\ComprobanteMail;
use App\Models\Comprobante;
use App\Models\ComprobanteEnvio;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía el comprobante al cliente por correo y registra los envíos (correo y WhatsApp).
 */
class EnvioComprobanteService
{
    /** "Clientes varios" (sin documento): no se le guardan datos de contacto. */
    private const CLIENTE_VARIOS = '00000000';

    public function __construct(private ComprobantePdfService $pdf) {}

    /** Envía el correo con PDF, XML y CDR. Devuelve el registro del envío (enviado o error). */
    public function enviarCorreo(Comprobante $comprobante, string $correo, ?string $mensaje, User $usuario, bool $guardarEnCliente): ComprobanteEnvio
    {
        $comprobante->loadMissing('cliente');

        try {
            Mail::to($correo)->send(new ComprobanteMail(
                $comprobante,
                Empresa::actual(),
                $this->pdf->generar($comprobante),
                $this->pdf->nombreArchivo($comprobante),
                $this->pdf->enlacePublico($comprobante),
                $mensaje,
            ));
            $error = null;
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 1000);
        }

        if (! $error && $guardarEnCliente) {
            $this->guardarContacto($comprobante, ['email' => $correo]);
        }

        return $this->registrar($comprobante, $usuario, 'correo', $correo, $error);
    }

    /** El vendedor abrió WhatsApp con el enlace: se registra y se guarda el celular en el cliente. */
    public function registrarWhatsapp(Comprobante $comprobante, string $celular, User $usuario): ComprobanteEnvio
    {
        $comprobante->loadMissing('cliente');
        $this->guardarContacto($comprobante, ['telefono' => $celular], soloSiVacio: true);

        return $this->registrar($comprobante, $usuario, 'whatsapp', $celular);
    }

    /** Guarda el correo o celular en la ficha del cliente para la próxima vez. */
    private function guardarContacto(Comprobante $comprobante, array $datos, bool $soloSiVacio = false): void
    {
        $cliente = $comprobante->cliente;
        if (! $cliente || $cliente->numero_documento === self::CLIENTE_VARIOS) {
            return;
        }

        $campo = array_key_first($datos);
        if ($soloSiVacio && filled($cliente->{$campo})) {
            return;
        }

        $cliente->update($datos);
    }

    private function registrar(Comprobante $comprobante, User $usuario, string $canal, string $destino, ?string $error = null): ComprobanteEnvio
    {
        return $comprobante->envios()->create([
            'user_id' => $usuario->id,
            'canal' => $canal,
            'destino' => $destino,
            'estado' => $error ? 'error' : 'enviado',
            'error' => $error,
        ]);
    }
}