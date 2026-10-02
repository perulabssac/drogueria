<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Genera un Excel (.xlsx) simple: título, encabezados en negrita, filas y fila de totales.
 * Los textos se guardan como texto (así un DNI "01234567" no pierde el cero inicial).
 */
class Excel
{
    /**
     * @param  array<int, string>  $encabezados
     * @param  array<int, array<int, mixed>>  $filas
     * @param  array<int, int>  $columnasMonto  índices (desde 0) de las columnas con soles
     */
    public static function descargar(string $archivo, string $titulo, string $subtitulo, array $encabezados, array $filas, array $columnasMonto = []): StreamedResponse
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(mb_substr($titulo, 0, 31));
        $ultimaColumna = Coordinate::stringFromColumnIndex(count($encabezados));

        // Título y subtítulo (empresa, RUC, periodo)
        $hoja->setCellValue('A1', $titulo);
        $hoja->setCellValue('A2', $subtitulo);
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // Encabezados en la fila 4
        $fila = 4;
        foreach ($encabezados as $i => $texto) {
            $hoja->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$fila, $texto);
        }
        $estiloEncabezado = $hoja->getStyle("A{$fila}:{$ultimaColumna}{$fila}");
        $estiloEncabezado->getFont()->setBold(true);
        $estiloEncabezado->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');

        // Filas de datos
        foreach ($filas as $datos) {
            $fila++;
            foreach (array_values($datos) as $i => $valor) {
                $celda = Coordinate::stringFromColumnIndex($i + 1).$fila;
                if (is_int($valor) || is_float($valor)) {
                    $hoja->setCellValue($celda, $valor);
                } else {
                    $hoja->setCellValueExplicit($celda, (string) $valor, DataType::TYPE_STRING);
                }
            }
        }

        // Fila de totales (suma de las columnas de montos)
        $primera = 5;
        if ($columnasMonto && $fila >= $primera) {
            $total = $fila + 1;
            $hoja->setCellValue("A{$total}", 'TOTAL');
            foreach ($columnasMonto as $i) {
                $columna = Coordinate::stringFromColumnIndex($i + 1);
                $hoja->setCellValue("{$columna}{$total}", "=SUM({$columna}{$primera}:{$columna}{$fila})");
            }
            $hoja->getStyle("A{$total}:{$ultimaColumna}{$total}")->getFont()->setBold(true);
            $fila = $total;
        }

        foreach ($columnasMonto as $i) {
            $columna = Coordinate::stringFromColumnIndex($i + 1);
            $hoja->getStyle("{$columna}{$primera}:{$columna}{$fila}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        for ($i = 1; $i <= count($encabezados); $i++) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        $hoja->freezePane('A5');

        return response()->streamDownload(function () use ($libro) {
            (new Xlsx($libro))->save('php://output');
        }, $archivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}