<?php

namespace App\Mail;

use App\Models\Comprobante;
use App\Models\Empresa;
use App\Services\ComprobantePdfService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

/**
 * Correo al cliente con su comprobante: PDF, XML firmado y CDR de SUNAT adjuntos,
 * más el enlace para verlo en línea.
 */
class ComprobanteMail extends Mailable
{
    public function __construct(
        public Comprobante $comprobante,
        public Empresa $empresa,
        public string $pdf,
        public string $nombrePdf,
        public string $enlace,
        public ?string $mensaje = null,
    ) {}

    public function envelope(): Envelope
    {
        $remitente = $this->empresa->nombre_comercial ?: $this->empresa->razon_social;

        return new Envelope(
            from: new Address(config('mail.from.address'), $remitente),
            // Si el cliente responde, le llega a la empresa (no a la cuenta que envía)
            replyTo: $this->empresa->email ? [new Address($this->empresa->email, $remitente)] : [],
            subject: (ComprobantePdfService::TITULOS[$this->comprobante->tipo_comprobante] ?? $this->comprobante->tipo_nombre)
                ." {$this->comprobante->numero} - {$remitente}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.comprobante');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $c = $this->comprobante;
        $base = $c->nombreArchivo($this->empresa->ruc);
        $disco = Storage::disk('local');

        $adjuntos = [Attachment::fromData(fn () => $this->pdf, $this->nombrePdf)->withMime('application/pdf')];

        if ($c->xml_path && $disco->exists($c->xml_path)) {
            $adjuntos[] = Attachment::fromStorageDisk('local', $c->xml_path)->as("{$base}.xml")->withMime('application/xml');
        }
        if ($c->cdr_path && $disco->exists($c->cdr_path)) {
            $adjuntos[] = Attachment::fromStorageDisk('local', $c->cdr_path)->as("R-{$base}.zip")->withMime('application/zip');
        }

        return $adjuntos;
    }
}