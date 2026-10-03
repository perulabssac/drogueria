<?php

namespace App\Services\Sunat;

use App\Models\Empresa;
use App\Models\Guia;
use DOMDocument;
use DOMXPath;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Envía la guía de remisión a SUNAT por su API REST (no por SOAP como las facturas):
 * 1. GuiaXml arma el XML (UBL 2.1, versión 2022) y greenter/xmldsig lo firma con el certificado.
 * 2. Se comprime en ZIP y se envía con un token OAuth2 → SUNAT responde con un ticket.
 * 3. Con el ticket se consulta el resultado y se guarda el CDR.
 */
class GuiaSunatService
{
    // Producción: servidores de SUNAT
    private const PROD_AUTH = 'https://api-seguridad.sunat.gob.pe/v1';

    private const PROD_CPE = 'https://api-cpe.sunat.gob.pe/v1';

    // Pruebas: SUNAT no tiene beta para guías; se usa el entorno de pruebas de la comunidad Greenter
    private const BETA_URL = 'https://gre-test.nubefact.com/v1';

    private const BETA_CLIENT_ID = 'test-85e5b0ae-255c-4891-a595-0b98c65c9854';

    private const BETA_CLIENT_SECRET = 'test-Hty/M6QshYvPgItX2P0+Kw==';

    public function __construct(private GuiaXml $generador) {}

    /** Envía la guía (si aún no tiene ticket) y consulta el resultado. */
    public function enviar(Guia $guia): void
    {
        if (! $guia->pendienteDeSunat()) {
            return;
        }

        $guia->increment('intentos_envio');
        $empresa = Empresa::actual();

        try {
            if (! $guia->ticket) {
                $this->enviarXml($guia, $empresa);
            }
            // SUNAT procesa en segundos: se consulta hasta 3 veces
            for ($i = 0; $i < 3 && $guia->estado === 'enviado'; $i++) {
                sleep($i === 0 ? 2 : 3);
                $this->consultar($guia, $empresa);
            }
        } catch (Throwable $e) {
            $guia->update(['estado' => 'error', 'sunat_descripcion' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }

    /** Consulta el ticket de una guía ya enviada. */
    public function consultar(Guia $guia, ?Empresa $empresa = null): void
    {
        $empresa ??= Empresa::actual();
        if (! $guia->ticket) {
            return;
        }

        $respuesta = $this->http($empresa)
            ->get($this->urlCpe($empresa).'/contribuyente/gem/comprobantes/envios/'.$guia->ticket);
        $this->verificarHttp($respuesta, $empresa);

        $datos = $respuesta->json();
        $codigo = (string) ($datos['codRespuesta'] ?? '');

        if ($codigo === '98') {
            return; // SUNAT todavía lo está procesando
        }

        $cdr = ! empty($datos['arcCdr']) ? $this->leerCdr($guia, $empresa, base64_decode($datos['arcCdr'])) : null;

        if ($codigo === '0' && $cdr) {
            $aceptado = (int) $cdr['codigo'] === 0 || (int) $cdr['codigo'] >= 4000;
            $guia->update([
                'estado' => $aceptado ? 'aceptado' : 'rechazado',
                'sunat_codigo' => $cdr['codigo'],
                'sunat_descripcion' => trim($cdr['descripcion'].' '.implode(' ', $cdr['notas'])),
                'enlace_qr' => $cdr['enlace'],
            ]);

            return;
        }

        // 99: rechazada (con o sin CDR)
        $error = $datos['error'] ?? [];
        $guia->update([
            'estado' => 'rechazado',
            'sunat_codigo' => $cdr['codigo'] ?? (string) ($error['numError'] ?? $codigo),
            'sunat_descripcion' => $cdr['descripcion'] ?? ($error['desError'] ?? 'SUNAT rechazó la guía.'),
        ]);
    }

    // ================= Envío =================

    private function enviarXml(Guia $guia, Empresa $empresa): void
    {
        $nombre = $guia->nombreArchivo($empresa->ruc);

        // 1. XML firmado
        $xml = $this->firmar($this->generador->generar($guia, $empresa), $empresa);
        $carpeta = $this->carpeta($guia, $empresa);
        Storage::disk('local')->put("{$carpeta}/{$nombre}.xml", $xml);

        // 2. ZIP con el XML
        $zip = $this->comprimir("{$nombre}.xml", $xml);

        // 3. Envío
        $respuesta = $this->http($empresa)->post($this->urlCpe($empresa).'/contribuyente/gem/comprobantes/'.$nombre, [
            'archivo' => [
                'nomArchivo' => "{$nombre}.zip",
                'arcGreZip' => base64_encode($zip),
                'hashZip' => hash('sha256', $zip),
            ],
        ]);
        $this->verificarHttp($respuesta, $empresa);

        $guia->update([
            'estado' => 'enviado',
            'ticket' => $respuesta->json('numTicket'),
            'xml_path' => "{$carpeta}/{$nombre}.xml",
            'enviado_at' => now(),
            'sunat_descripcion' => null,
        ]);
    }

    /** Firma el XML con el certificado digital de la empresa (el mismo de las facturas). */
    private function firmar(string $xml, Empresa $empresa): string
    {
        if (! $empresa->certificado_path || ! Storage::disk('local')->exists($empresa->certificado_path)) {
            throw new RuntimeException('No hay certificado digital cargado. Súbelo o genera uno de prueba en Configuración > Conexión SUNAT.');
        }

        $firma = new SignedXml();
        $firma->setCertificate(Storage::disk('local')->get($empresa->certificado_path));

        return $firma->signXml($xml);
    }

    // ================= Conexión con la API =================

    /** Cliente HTTP con el token vigente (se guarda en caché casi una hora). */
    private function http(Empresa $empresa): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->token($empresa))->acceptJson()->asJson()->timeout(30);
    }

    private function token(Empresa $empresa): string
    {
        [$clientId, $secret, $usuario, $clave] = $this->credenciales($empresa);

        return Cache::remember($this->claveToken($empresa), now()->addMinutes(50), function () use ($empresa, $clientId, $secret, $usuario, $clave) {
            try {
                $respuesta = Http::asForm()->acceptJson()->timeout(20)
                    ->post($this->urlAuth($empresa)."/clientessol/{$clientId}/oauth2/token/", [
                        'grant_type' => 'password',
                        'scope' => 'https://api-cpe.sunat.gob.pe',
                        'client_id' => $clientId,
                        'client_secret' => $secret,
                        'username' => $empresa->ruc.$usuario,
                        'password' => $clave,
                    ]);
            } catch (ConnectionException) {
                throw new RuntimeException('No hay conexión con SUNAT. Revisa el internet e inténtalo de nuevo.');
            }

            if (! $respuesta->successful() || ! $respuesta->json('access_token')) {
                throw new RuntimeException('SUNAT no aceptó las credenciales de la API de guías (Client ID / Client Secret / usuario SOL). '
                    .($respuesta->json('error_description') ?? $respuesta->body()));
            }

            return $respuesta->json('access_token');
        });
    }

    private function claveToken(Empresa $empresa): string
    {
        return 'sunat_gre_token_'.$empresa->entorno;
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} client_id, client_secret, usuario SOL, clave SOL */
    private function credenciales(Empresa $empresa): array
    {
        if (! $empresa->esProduccion()) {
            return [self::BETA_CLIENT_ID, self::BETA_CLIENT_SECRET, 'MODDATOS', 'MODDATOS'];
        }

        $clientId = config('services.sunat_gre.client_id');
        $secret = config('services.sunat_gre.client_secret');
        if (! $clientId || ! $secret) {
            throw new RuntimeException('Faltan SUNAT_GRE_CLIENT_ID y SUNAT_GRE_CLIENT_SECRET en el .env (se generan en SOL > Credenciales de API SUNAT).');
        }
        if (! $empresa->sol_usuario || ! $empresa->sol_clave) {
            throw new RuntimeException('Configura el usuario y la clave SOL antes de emitir guías en producción.');
        }

        return [$clientId, $secret, $empresa->sol_usuario, $empresa->sol_clave];
    }

    private function urlAuth(Empresa $e): string
    {
        return $e->esProduccion() ? self::PROD_AUTH : self::BETA_URL;
    }

    private function urlCpe(Empresa $e): string
    {
        return $e->esProduccion() ? self::PROD_CPE : self::BETA_URL;
    }

    /** Errores de la API: 401 (token vencido), 400/422 (validación), 500 (SUNAT caído). */
    private function verificarHttp(Response $respuesta, Empresa $empresa): void
    {
        if ($respuesta->successful()) {
            return;
        }

        if ($respuesta->status() === 401) {
            Cache::forget($this->claveToken($empresa)); // el token venció: el próximo intento pide uno nuevo
            throw new RuntimeException('El permiso (token) de SUNAT venció. Vuelve a intentar.');
        }

        $errores = collect($respuesta->json('errors') ?? [])->map(fn ($e) => ($e['cod'] ?? '').' '.($e['msg'] ?? ''))->implode(' | ');
        throw new RuntimeException(trim('SUNAT respondió '.$respuesta->status().': '.($respuesta->json('msg') ?? '').' '.$errores) ?: $respuesta->body());
    }

    // ================= Archivos =================

    private function comprimir(string $nombreXml, string $xml): string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'gre');
        $zip = new ZipArchive();
        $zip->open($temporal, ZipArchive::OVERWRITE);
        $zip->addFromString($nombreXml, $xml);
        $zip->close();

        $contenido = file_get_contents($temporal);
        @unlink($temporal);

        return $contenido;
    }

    /** Guarda el CDR y lee su código, descripción, notas y el enlace para el QR. */
    private function leerCdr(Guia $guia, Empresa $empresa, string $cdrZip): array
    {
        $ruta = $this->carpeta($guia, $empresa).'/R-'.$guia->nombreArchivo($empresa->ruc).'.zip';
        Storage::disk('local')->put($ruta, $cdrZip);
        $guia->update(['cdr_path' => $ruta]);

        $temporal = tempnam(sys_get_temp_dir(), 'cdr');
        file_put_contents($temporal, $cdrZip);
        $zip = new ZipArchive();
        $xml = null;
        if ($zip->open($temporal) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_ends_with(strtolower($zip->getNameIndex($i)), '.xml')) {
                    $xml = $zip->getFromIndex($i);
                    break;
                }
            }
            $zip->close();
        }
        @unlink($temporal);

        if (! $xml) {
            return ['codigo' => '0', 'descripcion' => 'CDR recibido.', 'notas' => [], 'enlace' => null];
        }

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xp = new DOMXPath($dom);
        $texto = fn (string $q) => ($n = $xp->query($q)->item(0)) ? trim($n->textContent) : null;

        return [
            'codigo' => $texto('//*[local-name()="Response"]/*[local-name()="ResponseCode"]') ?? '0',
            'descripcion' => $texto('//*[local-name()="Response"]/*[local-name()="Description"]') ?? '',
            'notas' => collect(iterator_to_array($xp->query('//*[local-name()="Note"]')))->map(fn ($n) => trim($n->textContent))->filter()->values()->all(),
            'enlace' => $texto('//*[local-name()="DocumentDescription"]'),
        ];
    }

    private function carpeta(Guia $guia, Empresa $empresa): string
    {
        return 'sunat/'.$empresa->entorno.'/guias/'.$guia->fecha_emision->format('Y/m');
    }
}