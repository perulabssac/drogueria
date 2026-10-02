<?php

namespace App\Services\Sunat;

use App\Models\Empresa;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Manejo del certificado digital con el que se firman los comprobantes.
 * Greenter lo necesita en formato PEM (clave privada + certificado en un solo texto).
 */
class CertificadoService
{
    /**
     * Convierte el .pfx/.p12 que entrega la entidad certificadora a PEM y lo guarda.
     */
    public function importarPfx(Empresa $empresa, string $contenidoPfx, string $password): array
    {
        $certs = [];
        if (! openssl_pkcs12_read($contenidoPfx, $certs, $password)) {
            throw ValidationException::withMessages([
                'certificado' => 'No se pudo abrir el certificado: revisa la contraseña o que el archivo sea .pfx / .p12.',
            ]);
        }

        $pem = $certs['pkey'].$certs['cert'];
        foreach ($certs['extracerts'] ?? [] as $extra) {
            $pem .= $extra;
        }

        return $this->guardar($empresa, $pem, 'certificado');
    }

    /**
     * Genera un certificado autofirmado SOLO para el entorno beta de SUNAT,
     * que no valida quién emitió el certificado. Nunca sirve para producción.
     */
    public function generarDemo(Empresa $empresa): array
    {
        // En Windows, PHP no encuentra solo el archivo openssl.cnf: se lo indicamos siempre
        $opciones = [
            'config' => $this->archivoConfigOpenssl(),
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $clave = openssl_pkey_new($opciones);
        $csr = $clave ? openssl_csr_new([
            'countryName' => 'PE',
            'organizationName' => mb_substr($empresa->razon_social, 0, 60),
            'commonName' => $empresa->ruc,
        ], $clave, $opciones) : false;
        $cert = $csr ? openssl_csr_sign($csr, null, $clave, 365, $opciones) : false;

        if (! $cert || ! openssl_x509_export($cert, $pemCert) || ! openssl_pkey_export($clave, $pemKey, null, $opciones)) {
            throw new RuntimeException('No se pudo generar el certificado de prueba: '.(openssl_error_string() ?: 'error de OpenSSL'));
        }

        return $this->guardar($empresa, $pemKey.$pemCert, 'demo');
    }

    /**
     * Usa el openssl.cnf de PHP si existe; si no, crea uno mínimo en storage.
     * (En Laragon suele estar en C:\laragon\bin\php\<version>\extras\ssl\openssl.cnf)
     */
    private function archivoConfigOpenssl(): string
    {
        $candidatos = [
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
        ];
        foreach (array_filter($candidatos) as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        $ruta = storage_path('app/private/openssl.cnf');
        if (! is_file($ruta)) {
            @mkdir(dirname($ruta), 0775, true);
            file_put_contents($ruta, "[ req ]\ndistinguished_name = req_distinguished_name\n[ req_distinguished_name ]\n[ v3_req ]\n");
        }

        return $ruta;
    }

    /** Titular y fecha de vencimiento del certificado cargado (o null si no hay). */
    public function informacion(Empresa $empresa): ?array
    {
        if (! $empresa->certificado_path || ! Storage::disk('local')->exists($empresa->certificado_path)) {
            return null;
        }

        $datos = openssl_x509_parse(Storage::disk('local')->get($empresa->certificado_path));
        if (! $datos) {
            return null;
        }

        $vence = Carbon::createFromTimestamp($datos['validTo_time_t']);

        return [
            'titular' => $datos['subject']['CN'] ?? ($datos['subject']['O'] ?? '—'),
            'emisor' => $datos['issuer']['O'] ?? ($datos['issuer']['CN'] ?? '—'),
            'vence' => $vence->toDateString(),
            'dias_restantes' => (int) now()->startOfDay()->diffInDays($vence, false),
            'es_demo' => str_contains($empresa->certificado_path, 'demo-'),
        ];
    }

    private function guardar(Empresa $empresa, string $pem, string $prefijo): array
    {
        $datos = openssl_x509_parse($pem);
        $vence = Carbon::createFromTimestamp($datos['validTo_time_t']);

        // Se guarda en storage/app/private (no es accesible desde el navegador)
        $path = "certificados/{$prefijo}-{$empresa->ruc}-".now()->format('YmdHis').'.pem';
        Storage::disk('local')->put($path, $pem);

        $empresa->update(['certificado_path' => $path, 'certificado_vence' => $vence->toDateString()]);

        return [
            'titular' => $datos['subject']['CN'] ?? ($datos['subject']['O'] ?? ''),
            'vence' => $vence,
        ];
    }
}