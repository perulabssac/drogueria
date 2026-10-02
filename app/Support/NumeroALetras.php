<?php

namespace App\Support;

/**
 * Convierte montos a letras para la leyenda 1000 de SUNAT.
 * Ej: 118.50 => "CIENTO DIECIOCHO CON 50/100 SOLES"
 */
class NumeroALetras
{
    private const UNIDADES = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
        'VEINTE', 'VEINTIUNO', 'VEINTIDOS', 'VEINTITRES', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISEIS', 'VEINTISIETE',
        'VEINTIOCHO', 'VEINTINUEVE'];

    private const DECENAS = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];

    private const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
        'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    public static function convertir(float $monto, string $moneda = 'SOLES'): string
    {
        $entero = (int) floor(round($monto, 2));
        $centimos = (int) round(($monto - $entero) * 100);
        if ($centimos === 100) {
            $entero++;
            $centimos = 0;
        }

        $letras = $entero === 0 ? 'CERO' : self::millones($entero);

        return trim(sprintf('%s CON %02d/100 %s', $letras, $centimos, $moneda));
    }

    private static function millones(int $n): string
    {
        $millones = intdiv($n, 1_000_000);
        $resto = $n % 1_000_000;

        $texto = '';
        if ($millones === 1) {
            $texto = 'UN MILLON';
        } elseif ($millones > 1) {
            $texto = self::miles($millones, true).' MILLONES';
        }

        return trim($texto.' '.self::miles($resto));
    }

    private static function miles(int $n, bool $apocope = false): string
    {
        $miles = intdiv($n, 1000);
        $resto = $n % 1000;

        $texto = '';
        if ($miles === 1) {
            $texto = 'MIL';
        } elseif ($miles > 1) {
            $texto = self::centenas($miles, true).' MIL';
        }

        return trim($texto.' '.self::centenas($resto, $apocope));
    }

    private static function centenas(int $n, bool $apocope = false): string
    {
        if ($n === 0) {
            return '';
        }
        if ($n === 100) {
            return 'CIEN';
        }

        $c = intdiv($n, 100);
        $resto = $n % 100;

        return trim(self::CENTENAS[$c].' '.self::decenas($resto, $apocope));
    }

    private static function decenas(int $n, bool $apocope): string
    {
        if ($n < 30) {
            $texto = self::UNIDADES[$n];
            if ($apocope) {
                $texto = preg_replace(['/^UNO$/', '/^VEINTIUNO$/'], ['UN', 'VEINTIUN'], $texto);
            }

            return $texto;
        }

        $d = intdiv($n, 10);
        $u = $n % 10;
        $unidad = self::UNIDADES[$u];
        if ($apocope && $u === 1) {
            $unidad = 'UN';
        }

        return $u === 0 ? self::DECENAS[$d] : self::DECENAS[$d].' Y '.$unidad;
    }
}