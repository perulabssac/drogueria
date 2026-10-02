<?php

namespace App\Services;

use App\Models\Cliente;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Busca un cliente por su documento: primero en la base de datos y, si no existe,
 * en SUNAT/RENIEC (Decolecta). Lo que viene de la API se guarda, así no se vuelve a consultar.
 */
class ClienteService
{
    public function __construct(private DecolectaService $decolecta) {}

    /** Tipo de documento según la cantidad de dígitos: 8 = DNI, 11 = RUC. */
    public static function tipoPorNumero(string $numero): ?string
    {
        return match (true) {
            (bool) preg_match('/^\d{8}$/', $numero) => Cliente::DNI,
            (bool) preg_match('/^(10|15|17|20)\d{9}$/', $numero) => Cliente::RUC,
            default => null,
        };
    }

    /**
     * Devuelve el cliente (existente o recién creado con datos de SUNAT/RENIEC).
     * $nuevo indica si se creó en esta consulta.
     *
     * @return array{cliente: Cliente, nuevo: bool}
     */
    public function buscarOCrear(string $numero): array
    {
        $numero = trim($numero);
        $tipo = self::tipoPorNumero($numero);

        if (! $tipo) {
            throw ValidationException::withMessages([
                'numero' => 'Escribe un DNI (8 dígitos) o un RUC (11 dígitos que empiece con 10, 15, 17 o 20).',
            ]);
        }

        // 1. Ya registrado: no se gasta ninguna consulta
        $existente = Cliente::query()->where('tipo_documento', $tipo)->where('numero_documento', $numero)->first();
        if ($existente) {
            return ['cliente' => $existente, 'nuevo' => false];
        }

        // 2. Nuevo: se consulta a SUNAT/RENIEC y se guarda
        $datos = $this->consultar($tipo, $numero);
        $cliente = Cliente::create([
            'tipo_documento' => $tipo,
            'numero_documento' => $numero,
            ...$datos,
            'verificado_at' => now(),
        ]);

        return ['cliente' => $cliente, 'nuevo' => true];
    }

    /** Vuelve a consultar SUNAT/RENIEC y actualiza los datos oficiales del cliente (gasta 1 consulta). */
    public function actualizarDesdeSunat(Cliente $cliente): Cliente
    {
        if (! in_array($cliente->tipo_documento, [Cliente::DNI, Cliente::RUC], true) || $cliente->esClientesVarios()) {
            throw ValidationException::withMessages(['numero' => 'Solo se pueden verificar clientes con DNI o RUC.']);
        }

        $cliente->update([...$this->consultar($cliente->tipo_documento, $cliente->numero_documento), 'verificado_at' => now()]);

        return $cliente;
    }

    private function consultar(string $tipo, string $numero): array
    {
        try {
            $datos = $tipo === Cliente::RUC ? $this->decolecta->ruc($numero) : $this->decolecta->dni($numero);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['numero' => $e->getMessage()]);
        }

        if ($datos['razon_social'] === '') {
            throw ValidationException::withMessages(['numero' => 'SUNAT/RENIEC no devolvió el nombre. Escribe los datos a mano.']);
        }

        // No se guardan campos vacíos encima de datos que ya existían
        return array_filter($datos, fn ($v) => $v !== null);
    }
}