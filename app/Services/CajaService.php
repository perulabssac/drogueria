<?php

namespace App\Services;

use App\Models\Caja;
use App\Models\CajaMovimiento;
use App\Models\ComprobantePago;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caja por usuario: apertura, ingresos/egresos, resumen por medio de pago y cierre con arqueo.
 */
class CajaService
{
    /** Billetes y monedas en soles para el conteo del cierre. */
    public const DENOMINACIONES = [200, 100, 50, 20, 10, 5, 2, 1, 0.5, 0.2, 0.1];

    public function abrir(User $usuario, float $montoInicial): Caja
    {
        return DB::transaction(function () use ($usuario, $montoInicial) {
            // Se bloquea al usuario para que no se abran dos cajas a la vez (doble clic)
            User::query()->lockForUpdate()->findOrFail($usuario->id);

            if (Caja::abiertaDe($usuario)) {
                throw ValidationException::withMessages(['monto_inicial' => 'Ya tienes una caja abierta.']);
            }

            return Caja::create([
                'sucursal_id' => $usuario->sucursal_id,
                'user_id' => $usuario->id,
                'estado' => 'abierta',
                'abierta_at' => now(),
                'monto_inicial' => round($montoInicial, 2),
            ]);
        });
    }

    /** Caja abierta del usuario, o error claro si no tiene (para cobrar o devolver dinero). */
    public function requerirAbierta(User $usuario, string $campo = 'caja'): Caja
    {
        $caja = Caja::abiertaDe($usuario);
        if (! $caja) {
            throw ValidationException::withMessages([$campo => 'Abre tu caja antes de registrar cobros o devoluciones (menú Caja).']);
        }

        return $caja;
    }

    public function registrarMovimiento(Caja $caja, User $usuario, string $tipo, float $monto, string $concepto, string $medio = 'efectivo', ?Model $origen = null): CajaMovimiento
    {
        if (! $caja->estaAbierta()) {
            throw ValidationException::withMessages(['monto' => 'La caja ya está cerrada.']);
        }

        return $caja->movimientos()->create([
            'user_id' => $usuario->id,
            'tipo' => $tipo,
            'medio' => $medio,
            'monto' => round($monto, 2),
            'concepto' => mb_substr(trim($concepto), 0, 150),
            'origen_type' => $origen?->getMorphClass(),
            'origen_id' => $origen?->getKey(),
        ]);
    }

    /**
     * Totales de la caja por medio de pago.
     * Efectivo esperado = monto inicial + ventas y cobranzas en efectivo + ingresos − egresos en efectivo.
     * Los comprobantes rechazados por SUNAT no cuentan (no tienen validez).
     */
    public function resumen(Caja $caja): array
    {
        $validos = fn () => ComprobantePago::query()
            ->where('caja_id', $caja->id)
            ->whereHas('comprobante', fn ($q) => $q->validos());

        // Ventas al contado y cobranzas de ventas al crédito, por medio de pago
        $pagos = $validos()
            ->selectRaw('tipo, medio, SUM(monto) as total')
            ->groupBy('tipo', 'medio')
            ->get();

        $cantidades = $validos()
            ->selectRaw('tipo, COUNT(DISTINCT comprobante_id) as cantidad')
            ->groupBy('tipo')
            ->pluck('cantidad', 'tipo');

        // Consulta directa (sin el orderBy de la relación, que MySQL no acepta con GROUP BY)
        $movimientos = CajaMovimiento::query()
            ->where('caja_id', $caja->id)
            ->selectRaw('tipo, medio, SUM(monto) as total')
            ->groupBy('tipo', 'medio')
            ->get();

        $pago = fn (string $tipo, string $medio) => round((float) $pagos->where('tipo', $tipo)->where('medio', $medio)->sum('total'), 2);
        $mov = fn (string $tipo, string $medio) => round((float) $movimientos->where('tipo', $tipo)->where('medio', $medio)->sum('total'), 2);

        $medios = collect(ComprobantePago::MEDIOS)->map(fn ($nombre, $medio) => [
            'medio' => $medio,
            'nombre' => $nombre,
            'ventas' => $pago(ComprobantePago::TIPO_VENTA, $medio),
            'cobranzas' => $pago(ComprobantePago::TIPO_COBRANZA, $medio),
            'ingresos' => $mov('ingreso', $medio),
            'egresos' => $mov('egreso', $medio),
        ])->map(fn ($m) => [...$m, 'neto' => round($m['ventas'] + $m['cobranzas'] + $m['ingresos'] - $m['egresos'], 2)])->values();

        $efectivo = $medios->firstWhere('medio', 'efectivo');

        return [
            'monto_inicial' => (float) $caja->monto_inicial,
            'medios' => $medios->all(),
            'total_ventas' => round($medios->sum('ventas'), 2),
            'cantidad_ventas' => (int) ($cantidades[ComprobantePago::TIPO_VENTA] ?? 0),
            'total_cobranzas' => round($medios->sum('cobranzas'), 2),
            'cantidad_cobranzas' => (int) ($cantidades[ComprobantePago::TIPO_COBRANZA] ?? 0),
            'total_ingresos' => round($medios->sum('ingresos'), 2),
            'total_egresos' => round($medios->sum('egresos'), 2),
            'efectivo_esperado' => round((float) $caja->monto_inicial + $efectivo['neto'], 2),
        ];
    }

    /**
     * Cierre con arqueo: se guarda lo esperado, lo contado y la diferencia.
     *
     * @param  array<string, int>  $conteo  denominación => cantidad (opcional)
     */
    public function cerrar(Caja $caja, User $usuario, float $efectivoContado, array $conteo = [], ?string $observaciones = null): Caja
    {
        return DB::transaction(function () use ($caja, $usuario, $efectivoContado, $conteo, $observaciones) {
            $caja = Caja::query()->lockForUpdate()->findOrFail($caja->id);

            if (! $caja->estaAbierta()) {
                throw ValidationException::withMessages(['efectivo_contado' => 'Esta caja ya fue cerrada.']);
            }
            if ((int) $caja->user_id !== (int) $usuario->id && ! $usuario->tieneRol('admin')) {
                throw ValidationException::withMessages(['efectivo_contado' => 'Solo el cajero o un administrador puede cerrar esta caja.']);
            }

            $resumen = $this->resumen($caja);
            $contado = round($efectivoContado, 2);

            $caja->update([
                'estado' => 'cerrada',
                'cerrada_at' => now(),
                'efectivo_esperado' => $resumen['efectivo_esperado'],
                'efectivo_contado' => $contado,
                'diferencia' => round($contado - $resumen['efectivo_esperado'], 2),
                'conteo' => array_filter($conteo, fn ($cantidad) => (int) $cantidad > 0) ?: null,
                'resumen' => $resumen,
                'observaciones' => $observaciones,
            ]);

            return $caja;
        });
    }
}