<?php

namespace App\Http\Controllers;

use App\Models\Comprobante;
use App\Models\Empresa;
use App\Services\ComprobantePdfService;
use Illuminate\Http\Response;

/**
 * PDF del comprobante para el cliente, por enlace corto (WhatsApp o correo): /c/K7mQ2xP9aR
 * No requiere iniciar sesión: el código aleatorio de 10 caracteres es el que da acceso.
 */
class ComprobantePublicoController extends Controller
{
    public function pdf(string $codigo, ComprobantePdfService $pdf): Response
    {
        $comprobante = Comprobante::query()->where('codigo_publico', $codigo)->first();

        if (! $comprobante) {
            return $this->aviso('Comprobante no encontrado', 'Revise que el enlace esté completo o solicite su comprobante a la droguería.', 404);
        }
        if (! $comprobante->tieneValidez()) {
            return $this->aviso('Comprobante sin validez', "El comprobante {$comprobante->numero} fue anulado. Comuníquese con la droguería.", 410);
        }
        if ($pdf->enlaceVencido($comprobante)) {
            return $this->aviso('Enlace vencido', "El enlace del comprobante {$comprobante->numero} ya no está disponible. Solicítelo a la droguería.", 410);
        }

        return response($pdf->generar($comprobante), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->nombreArchivo($comprobante).'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow', // que los buscadores no lo indexen
        ]);
    }

    /** Página sencilla con un mensaje para el cliente (sin datos del comprobante). */
    private function aviso(string $titulo, string $texto, int $estado): Response
    {
        $empresa = Empresa::query()->first();
        $nombre = e($empresa ? ($empresa->nombre_comercial ?: $empresa->razon_social) : config('app.name'));
        $contacto = e(collect([$empresa?->telefono, $empresa?->email])->filter()->implode(' · '));
        $titulo = e($titulo);
        $texto = e($texto);

        $html = <<<HTML
        <!doctype html>
        <html lang="es">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>{$titulo} · {$nombre}</title>
        </head>
        <body style="margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f1f5f9;color:#1e293b">
            <div style="max-width:420px;margin:15vh auto 0;padding:28px 24px;background:#fff;border-radius:14px;border:1px solid #e2e8f0;text-align:center">
                <p style="margin:0 0 6px;font-size:13px;color:#047857;font-weight:600">{$nombre}</p>
                <h1 style="margin:0 0 10px;font-size:20px">{$titulo}</h1>
                <p style="margin:0;font-size:15px;line-height:1.5;color:#475569">{$texto}</p>
                <p style="margin:16px 0 0;font-size:14px;color:#334155">{$contacto}</p>
            </div>
        </body>
        </html>
        HTML;

        return response($html, $estado, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}