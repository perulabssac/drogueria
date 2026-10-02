<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Services\ReporteContableService;
use App\Support\Excel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes contables (contador y administrador): solo lectura y descargas.
 */
class ReporteContableController extends Controller
{
    public function __construct(private ReporteContableService $reportes) {}

    public function index(Request $request): Response
    {
        $periodo = $this->periodo($request);
        $ventas = $this->reportes->ventas($periodo);
        $compras = $this->reportes->compras($periodo);

        return Inertia::render('Reportes/Index', [
            'periodo' => $periodo,
            'ventas' => $ventas->values(),
            'compras' => $compras->values(),
            'resumen' => $this->reportes->resumen($periodo, $ventas, $compras),
        ]);
    }

    public function excelVentas(Request $request): StreamedResponse
    {
        $periodo = $this->periodo($request);

        $filas = $this->reportes->ventas($periodo)->map(fn ($v) => [
            $this->fecha($v['fecha']), $v['tipo'], $v['serie'], $v['numero'],
            $v['cliente_tipo_doc'], $v['cliente_doc'], $v['cliente'],
            $v['gravado'], $v['exonerado'], $v['inafecto'], $v['igv'], $v['total'],
            mb_strtoupper($v['estado']), $v['referencia'] ?? '', $v['referencia_fecha'] ? $this->fecha($v['referencia_fecha']) : '',
        ])->all();

        return Excel::descargar(
            "registro-ventas-{$periodo}.xlsx",
            'Registro de ventas',
            $this->subtitulo($periodo),
            ['Fecha', 'Tipo', 'Serie', 'Número', 'Tipo doc.', 'N° documento', 'Cliente', 'Base imponible', 'Exonerado', 'Inafecto', 'IGV', 'Total', 'Estado SUNAT', 'Doc. modificado', 'Fecha doc. modif.'],
            $filas,
            [7, 8, 9, 10, 11],
        );
    }

    public function excelCompras(Request $request): StreamedResponse
    {
        $periodo = $this->periodo($request);

        $filas = $this->reportes->compras($periodo)->map(fn ($c) => [
            $this->fecha($c['fecha']), $c['tipo'], $c['serie'], $c['numero'],
            $c['proveedor_ruc'], $c['proveedor'],
            $c['gravado'], $c['exonerado'], $c['igv'], $c['total'],
            mb_strtoupper($c['estado']),
        ])->all();

        return Excel::descargar(
            "registro-compras-{$periodo}.xlsx",
            'Registro de compras',
            $this->subtitulo($periodo),
            ['Fecha', 'Tipo', 'Serie', 'Número', 'RUC proveedor', 'Proveedor', 'Base imponible', 'Exonerado', 'IGV', 'Total', 'Estado'],
            $filas,
            [6, 7, 8, 9],
        );
    }

    public function xml(Request $request): BinaryFileResponse|RedirectResponse
    {
        $periodo = $this->periodo($request);
        $ruta = $this->reportes->zipXmlCdr($periodo);

        if (! $ruta) {
            return back()->with('error', 'No hay archivos XML en ese periodo.');
        }

        $ruc = Empresa::actual()?->ruc ?? 'empresa';

        return response()->download($ruta, "{$ruc}-xml-cdr-{$periodo}.zip")->deleteFileAfterSend();
    }

    /** Periodo "AAAA-MM" pedido (por defecto, el mes actual). */
    private function periodo(Request $request): string
    {
        $periodo = (string) $request->input('periodo', now()->format('Y-m'));

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo) ? $periodo : now()->format('Y-m');
    }

    private function subtitulo(string $periodo): string
    {
        $empresa = Empresa::actual();
        $mes = ReporteContableService::rango($periodo)[0]->locale('es')->translatedFormat('F Y');

        return trim(($empresa?->razon_social ?? '').' · RUC '.($empresa?->ruc ?? '').' · Periodo '.mb_strtoupper($mes));
    }

    private function fecha(string $iso): string
    {
        return implode('/', array_reverse(explode('-', $iso)));
    }
}