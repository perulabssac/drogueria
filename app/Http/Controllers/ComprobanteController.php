<?php

namespace App\Http\Controllers;

use App\Models\Comprobante;
use App\Models\Empresa;
use App\Services\ComprobantePdfService;
use App\Services\EnvioComprobanteService;
use App\Services\NotaCreditoService;
use App\Services\Sunat\BajaService;
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

    public function show(Request $request, Comprobante $comprobante, NotaCreditoService $notasCredito, ComprobantePdfService $pdf): Response
    {
        $comprobante->load([
            'items', 'cliente', 'cuotas', 'pagos', 'vendedor:id,name', 'usuario:id,name', 'referencia', 'notas',
            'guias:id,comprobante_id,serie,correlativo,estado', 'bajaUsuario:id,name', 'envios.usuario:id,name',
        ]);
        $esAdmin = $request->user()->tieneRol('admin');

        return Inertia::render('Comprobantes/Show', [
            'comprobante' => $comprobante,
            'puedeReenviarse' => $comprobante->puedeReenviarse(),
            // Botón "Nota de crédito": solo administrador y si aún queda algo por acreditar
            'puedeNotaCredito' => $esAdmin && ! $notasCredito->impedimento($comprobante),
            // Botón "Dar de baja": solo administrador, dentro del plazo y sin notas ni cobranzas
            'puedeBaja' => $esAdmin && ! $comprobante->impedimentoBaja(),
            'limiteBaja' => in_array($comprobante->tipo_comprobante, ['01', '03'], true)
                ? $comprobante->fechaLimiteBaja()->toDateString()
                : null,
            // Enlace público al PDF para enviarlo por WhatsApp (solo comprobantes válidos)
            'enlacePublico' => $comprobante->tieneValidez() ? $pdf->enlacePublico($comprobante) : null,
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

    /** Envía el comprobante al correo del cliente (PDF, XML y CDR adjuntos). */
    public function correo(Request $request, Comprobante $comprobante, EnvioComprobanteService $envios): RedirectResponse
    {
        abort_unless($comprobante->tieneValidez(), 422, 'Este comprobante no tiene validez: no se puede enviar.');

        $datos = $request->validate([
            'correo' => ['required', 'email:rfc', 'max:150'],
            'mensaje' => ['nullable', 'string', 'max:500'],
            'guardar' => ['boolean'],
        ], [
            'correo.required' => 'Indica el correo del cliente.',
            'correo.email' => 'El correo no es válido.',
        ]);

        $envio = $envios->enviarCorreo($comprobante, trim($datos['correo']), $datos['mensaje'] ?? null, $request->user(), (bool) ($datos['guardar'] ?? false));

        return $envio->estado === 'enviado'
            ? back()->with('success', "Comprobante {$comprobante->numero} enviado a {$envio->destino}.")
            : back()->with('error', 'No se pudo enviar el correo: '.$envio->error);
    }

    /** Registra que se abrió WhatsApp con el comprobante (el envío lo hace el vendedor desde WhatsApp). */
    public function whatsapp(Request $request, Comprobante $comprobante, EnvioComprobanteService $envios): RedirectResponse
    {
        $datos = $request->validate(['celular' => ['required', 'string', 'regex:/^\d{9,15}$/']]);

        $envios->registrarWhatsapp($comprobante, $datos['celular'], $request->user());

        return back();
    }

    /** Comunicación de baja: anula ante SUNAT una factura o boleta aceptada (solo administrador). */
    public function baja(Request $request, Comprobante $comprobante, BajaService $bajas): RedirectResponse
    {
        abort_if((int) $comprobante->sucursal_id !== (int) $request->user()->sucursal_id, 404);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:100'],
        ], [
            'motivo.required' => 'Indica el motivo de la baja.',
            'motivo.max' => 'El motivo admite como máximo 100 caracteres.',
        ]);

        $mensaje = $bajas->solicitar($comprobante, trim($datos['motivo']), $request->user());

        return in_array($comprobante->refresh()->baja_estado, ['aceptada', 'enviada'], true)
            ? back()->with('success', $mensaje)
            : back()->with('error', $mensaje);
    }

    /** Consulta el ticket de una baja que SUNAT aún procesaba. */
    public function consultarBaja(Comprobante $comprobante, BajaService $bajas): RedirectResponse
    {
        if ($comprobante->baja_estado !== 'enviada') {
            return back()->with('error', 'Este comprobante no tiene una baja pendiente de respuesta.');
        }

        $bajas->consultar($comprobante);
        $comprobante->refresh();

        return match ($comprobante->baja_estado) {
            'aceptada' => back()->with('success', "SUNAT aceptó la baja de {$comprobante->numero}. El comprobante quedó anulado."),
            'enviada' => back()->with('success', 'SUNAT aún está procesando la baja. Vuelve a consultar en unos segundos.'),
            default => back()->with('error', "SUNAT: {$comprobante->baja_descripcion}"),
        };
    }

    public function cdrBaja(Comprobante $comprobante): StreamedResponse
    {
        abort_unless($comprobante->baja_cdr_path && Storage::disk('local')->exists($comprobante->baja_cdr_path), 404, 'Aún no hay CDR de la baja.');

        return Storage::disk('local')->download($comprobante->baja_cdr_path);
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