<?php

namespace App\Services\Sunat;

use App\Models\Empresa;
use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Crea el objeto See de Greenter (firma y envía a SUNAT) con los datos de la empresa.
 */
class GreenterFactory
{
    public function crear(Empresa $empresa): See
    {
        if (! extension_loaded('soap')) {
            throw new RuntimeException('Falta activar la extensión "soap" de PHP (en Laragon: Menú > PHP > Extensions > soap).');
        }

        $see = new See();
        $see->setCertificate($this->certificado($empresa));

        if ($empresa->esProduccion()) {
            if (! $empresa->sol_usuario || ! $empresa->sol_clave) {
                throw new RuntimeException('Configura el usuario y la clave SOL antes de emitir en producción.');
            }
            $see->setService(SunatEndpoints::FE_PRODUCCION);
            $see->setClaveSOL($empresa->ruc, $empresa->sol_usuario, $empresa->sol_clave);
        } else {
            // Credenciales públicas del entorno de pruebas (beta) de SUNAT
            $see->setService(SunatEndpoints::FE_BETA);
            $see->setClaveSOL($empresa->ruc, 'MODDATOS', 'moddatos');
        }

        // Greenter guarda aquí sus plantillas compiladas para generar el XML más rápido
        $cache = storage_path('framework/cache/greenter');
        if (! is_dir($cache)) {
            mkdir($cache, 0775, true);
        }
        $see->setCachePath($cache);

        return $see;
    }

    private function certificado(Empresa $empresa): string
    {
        if (! $empresa->certificado_path || ! Storage::disk('local')->exists($empresa->certificado_path)) {
            throw new RuntimeException('No hay certificado digital cargado. Súbelo o genera uno de prueba en Configuración > Conexión SUNAT.');
        }

        return Storage::disk('local')->get($empresa->certificado_path);
    }
}