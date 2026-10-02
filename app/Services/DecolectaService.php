<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Consulta RUC (SUNAT) y DNI (RENIEC) en Decolecta (decolecta.com).
 * Solo habla con la API: guardar el cliente lo hace ClienteService,
 * así cada documento se consulta UNA sola vez.
 */
class DecolectaService
{
    public static function configurado(): bool
    {
        return (bool) config('services.decolecta.key');
    }

    /**
     * @return array{razon_social: string, direccion: ?string, distrito: ?string, provincia: ?string,
     *               departamento: ?string, ubigeo: ?string, estado_sunat: ?string, condicion_sunat: ?string}
     */
    public function ruc(string $ruc): array
    {
        $d = $this->consultar('/v1/sunat/ruc', $ruc, 'RUC');

        return [
            'razon_social' => mb_strtoupper(trim($d['razon_social'] ?? $d['razonSocial'] ?? '')),
            'direccion' => $this->texto($d['direccion'] ?? $d['address'] ?? null),
            'distrito' => $this->texto($d['distrito'] ?? null),
            'provincia' => $this->texto($d['provincia'] ?? null),
            'departamento' => $this->texto($d['departamento'] ?? null),
            'ubigeo' => $this->texto($d['ubigeo'] ?? null),
            'estado_sunat' => $this->texto($d['estado'] ?? $d['state'] ?? null),
            'condicion_sunat' => $this->texto($d['condicion'] ?? $d['condition'] ?? null),
        ];
    }

    /** @return array{razon_social: string} */
    public function dni(string $dni): array
    {
        $d = $this->consultar('/v1/reniec/dni', $dni, 'DNI');

        $nombre = $d['full_name'] ?? trim(implode(' ', array_filter([
            $d['first_last_name'] ?? null,
            $d['second_last_name'] ?? null,
            $d['first_name'] ?? null,
        ])));

        return ['razon_social' => mb_strtoupper(trim($nombre))];
    }

    private function consultar(string $ruta, string $numero, string $tipo): array
    {
        if (! self::configurado()) {
            throw new RuntimeException('La consulta a SUNAT/RENIEC no está configurada (falta DECOLECTA_API_KEY en el .env). Escribe los datos a mano.');
        }

        try {
            $respuesta = Http::withToken(config('services.decolecta.key'))
                ->acceptJson()
                ->timeout(15)
                ->get(rtrim(config('services.decolecta.url'), '/').$ruta, ['numero' => $numero]);
        } catch (ConnectionException) {
            throw new RuntimeException('No hay conexión con el servicio de consulta. Revisa el internet o escribe los datos a mano.');
        }

        if ($respuesta->successful() && is_array($respuesta->json())) {
            return $respuesta->json();
        }

        Log::warning("Decolecta {$tipo} {$numero}: HTTP {$respuesta->status()}", ['respuesta' => $respuesta->body()]);

        throw new RuntimeException(match (true) {
            in_array($respuesta->status(), [400, 404, 422], true) => "No se encontró el {$tipo} {$numero}. Revisa el número.",
            in_array($respuesta->status(), [401, 403], true) => 'El token de Decolecta no es válido. Revisa DECOLECTA_API_KEY en el .env.',
            in_array($respuesta->status(), [402, 429], true) => 'Se agotaron las consultas del mes en Decolecta. Escribe los datos a mano.',
            default => 'El servicio de consulta no respondió. Inténtalo de nuevo o escribe los datos a mano.',
        });
    }

    private function texto(mixed $valor): ?string
    {
        $valor = is_string($valor) ? trim($valor) : null;

        return ($valor === '' || $valor === '-') ? null : $valor;
    }
}