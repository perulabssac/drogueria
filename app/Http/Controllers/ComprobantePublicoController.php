<?php

namespace App\Http\Controllers;

use App\Models\Comprobante;
use App\Services\ComprobantePdfService;
use Illuminate\Http\Response;

/**
 * PDF del comprobante para el cliente, por enlace firmado (WhatsApp o correo).
 * No requiere iniciar sesión: la firma de la URL es la que da acceso.
 */
class ComprobantePublicoController extends Controller
{
    public function pdf(Comprobante $comprobante, ComprobantePdfService $pdf): Response
    {
        return response($pdf->generar($comprobante), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->nombreArchivo($comprobante).'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow', // que los buscadores no lo indexen
        ]);
    }
}