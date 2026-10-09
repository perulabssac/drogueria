<?php

namespace App\Services;

use App\Models\Comprobante;
use App\Models\Empresa;
use App\Support\NumeroALetras;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Genera el PDF (A4) de un comprobante en el servidor: para adjuntarlo al correo
 * y para el enlace de descarga que se envía por WhatsApp.
 */
class ComprobantePdfService
{
    public const TITULOS = [
        '01' => 'FACTURA ELECTRÓNICA',
        '03' => 'BOLETA DE VENTA ELECTRÓNICA',
        '07' => 'NOTA DE CRÉDITO ELECTRÓNICA',
        '08' => 'NOTA DE DÉBITO ELECTRÓNICA',
        'NV' => 'NOTA DE VENTA',
    ];

    /** Días que el cliente puede abrir su comprobante desde la emisión (SUNAT: al menos 1 año). */
    public const DIAS_ENLACE = 365;

    /**
     * Enlace público y corto al PDF: el cliente lo abre sin iniciar sesión.
     * El código aleatorio impide adivinar los comprobantes de otros clientes.
     */
    public function enlacePublico(Comprobante $comprobante): string
    {
        return route('comprobantes.publico', ['codigo' => $comprobante->codigoPublico()]);
    }

    /** ¿El enlace público ya venció? (se cuenta desde la fecha de emisión) */
    public function enlaceVencido(Comprobante $comprobante): bool
    {
        return $comprobante->fecha_emision->copy()->startOfDay()->addDays(self::DIAS_ENLACE)->isPast();
    }

    /** Contenido binario del PDF. */
    public function generar(Comprobante $comprobante): string
    {
        $comprobante->loadMissing(['items', 'cliente', 'cuotas', 'pagos', 'vendedor:id,name', 'sucursal', 'referencia']);
        $empresa = Empresa::actual();

        $html = view('pdf.comprobante', [
            'c' => $comprobante,
            'e' => $empresa,
            'titulo' => self::TITULOS[$comprobante->tipo_comprobante] ?? mb_strtoupper($comprobante->tipo_nombre),
            'letras' => NumeroALetras::convertir((float) $comprobante->total, $comprobante->moneda === 'USD' ? 'DOLARES AMERICANOS' : 'SOLES'),
            'qr' => $comprobante->esInterno() ? null : $this->qr($this->textoQr($comprobante, $empresa)),
        ])->render();

        $dompdf = new Dompdf($this->opciones());
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    /** Nombre del archivo que ve el cliente: 20123456789-01-F001-25.pdf */
    public function nombreArchivo(Comprobante $comprobante): string
    {
        return $comprobante->nombreArchivo(Empresa::actual()->ruc).'.pdf';
    }

    /**
     * Contenido del QR según SUNAT:
     * RUC | TIPO | SERIE | NÚMERO | IGV | TOTAL | FECHA | TIPO DOC. CLIENTE | N° DOC. CLIENTE | HASH |
     */
    public function textoQr(Comprobante $c, Empresa $empresa): string
    {
        return implode('|', [
            $empresa->ruc,
            $c->tipo_comprobante,
            $c->serie,
            $c->correlativo,
            number_format((float) $c->igv, 2, '.', ''),
            number_format((float) $c->total, 2, '.', ''),
            $c->fecha_emision->format('Y-m-d'),
            $c->cliente->tipo_documento,
            $c->cliente->numero_documento,
            $c->hash ?? '',
        ]).'|';
    }

    /** QR como imagen PNG embebida (data URI), que el PDF muestra sin acceder a internet. */
    private function qr(string $texto): string
    {
        return (new QRCode(new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'eccLevel' => EccLevel::Q,
            'scale' => 5,
            'addQuietzone' => false,
        ])))->render($texto);
    }

    private function opciones(): Options
    {
        // Caché de fuentes en storage (la carpeta vendor puede no tener permiso de escritura en el servidor)
        $fuentes = storage_path('fonts');
        if (! is_dir($fuentes)) {
            mkdir($fuentes, 0775, true);
        }

        return (new Options())
            ->setDefaultFont('DejaVu Sans') // incluye tildes, ñ y el símbolo S/
            ->setFontDir($fuentes)
            ->setFontCache($fuentes)
            ->setIsRemoteEnabled(false)     // no descarga nada de internet
            ->setIsPhpEnabled(false);
    }
}