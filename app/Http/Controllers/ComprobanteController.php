<?php

namespace App\Http\Controllers;

use App\Models\Comprobante;
use App\Models\Empresa;
use App\Services\NotaCreditoService;
use App\Services\Sunat\SunatService;
use App\Support\NumeroALetras;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComprobanteController extends Controller
{
    public function index(Request $request): Response
    {
        $comprobantes = Comprobante::query()
            ->with('cliente:id,razon_social,numero_documento')
            ->where('sucursal_id', $request->user()->sucursal_id)
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo_comprobante', $request->string('tipo')->toString()))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')->toString()))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha_emision', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha_emision', '<=', $request->date('hasta')))
            ->when($request->filled('buscar'), function ($q) use ($request) {
                $b = trim($request->string('buscar')->toString());
                $q->where(function ($w) use ($b) {
                    // "F001-25" busca por número; cualquier otro texto, por cliente
                    if (preg_match('/^([A-Z0-9]{4})-(\d+)$/i', $b, $m)) {
                        $w->where('serie', strtoupper($m[1]))->where('correlativo', (int) $m[2]);
                    } else {
                        $w->whereHas('cliente', fn ($c) => $c->where('razon_social', 'like', "%{$b}%")->orWhere('numero_documento', 'like', "{$b}%"));
                    }
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Comprobantes/Index', [
            'comprobantes' => $comprobantes,
            'filtros' => $request->only('tipo', 'estado', 'desde', 'hasta', 'buscar'),
            'tipos' => Comprobante::TIPOS,
        ]);
    }

    public function show(Request $request, Comprobante $comprobante, NotaCreditoService $notasCredito): Response
    {
        $comprobante->load(['items', 'cliente', 'cuotas', 'pagos', 'vendedor:id,name', 'usuario:id,name', 'referencia', 'notas']);

        return Inertia::render('Comprobantes/Show', [
            'comprobante' => $comprobante,
            'puedeReenviarse' => $comprobante->puedeReenviarse(),
            // Botón "Nota de crédito": solo administrador y si aún queda algo por acreditar
            'puedeNotaCredito' => $request->user()->tieneRol('admin') && ! $notasCredito->impedimento($comprobante),
        ]);
    }

    /**
     * Representación impresa: A4 o ticket, con QR y hash como exige SUNAT.
     */
    public function imprimir(Request $request, Comprobante $comprobante): Response
    {
        $comprobante->load(['items', 'cliente', 'cuotas', 'pagos', 'vendedor:id,name', 'usuario:id,name', 'sucursal', 'referencia']);
        $empresa = Empresa::actual();

        return Inertia::render('Comprobantes/Imprimir', [
            'comprobante' => $comprobante,
            'empresa' => $empresa->only([
                'ruc', 'razon_social', 'nombre_comercial', 'direccion', 'distrito', 'provincia', 'departamento',
                'telefono', 'email', 'cuentas_bancarias',
            ]),
            'formato' => $request->query('formato') === 'ticket' ? 'ticket' : 'a4',
            'autoImprimir' => $request->boolean('auto'),
            'letras' => NumeroALetras::convertir((float) $comprobante->total, $comprobante->moneda === 'USD' ? 'DOLARES AMERICANOS' : 'SOLES'),
            'qr' => $comprobante->esInterno() ? null : $this->textoQr($comprobante, $empresa),
        ]);
    }

    /**
     * Contenido del QR según SUNAT:
     * RUC | TIPO | SERIE | NÚMERO | IGV | TOTAL | FECHA | TIPO DOC. CLIENTE | N° DOC. CLIENTE | HASH |
     */
    private function textoQr(Comprobante $c, Empresa $empresa): string
    {
        return implode('|', [
            $empresa->ruc,
            $c->tipo_comprobante,
            $c->serie,
            $c->correlativo,
            number_format((float) $c->igv, 2, '.', ''),
            number_format((float) $c->total, 2, '.', ''),
            $c->fecha_emision->format('Y-m-d'),
            $c->cliente->tipo_documento,
            $c->cliente->numero_documento,
            $c->hash ?? '',
        ]).'|';
    }

    /** Reintenta el envío de un comprobante que no llegó a SUNAT. */
    public function reenviar(Comprobante $comprobante, SunatService $sunat): RedirectResponse
    {
        if (! $comprobante->puedeReenviarse()) {
            return back()->with('error', 'Este comprobante ya fue procesado por SUNAT.');
        }

        $sunat->enviar($comprobante);

        return $this->respuesta($comprobante->refresh());
    }

    /** Consulta el ticket de un resumen (boletas) que SUNAT aún procesaba. */
    public function consultar(Comprobante $comprobante, SunatService $sunat): RedirectResponse
    {
        if ($comprobante->estado !== 'enviado' || ! $comprobante->ticket) {
            return back()->with('error', 'Este comprobante no tiene una consulta pendiente.');
        }

        $sunat->consultarTicket($comprobante);

        return $this->respuesta($comprobante->refresh());
    }

    public function xml(Comprobante $comprobante): StreamedResponse
    {
        abort_unless($comprobante->xml_path && Storage::disk('local')->exists($comprobante->xml_path), 404, 'Aún no se generó el XML.');

        return Storage::disk('local')->download($comprobante->xml_path);
    }

    public function cdr(Comprobante $comprobante): StreamedResponse
    {
        abort_unless($comprobante->cdr_path && Storage::disk('local')->exists($comprobante->cdr_path), 404, 'Aún no hay CDR de SUNAT.');

        return Storage::disk('local')->download($comprobante->cdr_path);
    }

    private function respuesta(Comprobante $c): RedirectResponse
    {
        return match ($c->estado) {
            'aceptado', 'observado' => back()->with('success', "SUNAT aceptó el comprobante {$c->numero}."),
            'enviado' => back()->with('success', 'SUNAT aún está procesando. Vuelve a consultar en unos segundos.'),
            default => back()->with('error', "SUNAT: {$c->sunat_descripcion}"),
        };
    }
}