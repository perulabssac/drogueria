<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\Comprobante;
use App\Models\ComprobantePago;
use App\Services\NotaCreditoService;
use App\Services\Sunat\SunatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notas de crédito: anulaciones y devoluciones de facturas y boletas aceptadas.
 */
class NotaCreditoController extends Controller
{
    public function create(Request $request, Comprobante $comprobante, NotaCreditoService $notas): Response|RedirectResponse
    {
        if ($error = $notas->impedimento($comprobante)) {
            return redirect("/comprobantes/{$comprobante->id}")->with('error', $error);
        }

        $comprobante->load(['items', 'cliente', 'notas' => fn ($q) => $q->validos()]);

        return Inertia::render('Comprobantes/NotaCredito', [
            'comprobante' => $comprobante,
            'pendientes' => $notas->pendientes($comprobante),
            'motivos' => NotaCreditoService::MOTIVOS,
            'motivosTotales' => NotaCreditoService::MOTIVOS_TOTALES,
            'serie' => $notas->serieParaNota($comprobante)?->serie,
            // Para devolver dinero al cliente hace falta tener la caja abierta
            'cajaAbierta' => (bool) Caja::abiertaDe($request->user()),
            'mediosPago' => ComprobantePago::MEDIOS,
        ]);
    }

    public function store(Request $request, Comprobante $comprobante, NotaCreditoService $notas, SunatService $sunat): RedirectResponse
    {
        $datos = $request->validate([
            'motivo_codigo' => ['required', Rule::in(array_keys(NotaCreditoService::MOTIVOS))],
            'motivo_descripcion' => ['required', 'string', 'max:250'],
            'reingresar_stock' => ['boolean'],
            'devolver_dinero' => ['boolean'],
            'medio_devolucion' => ['nullable', 'required_if:devolver_dinero,true', Rule::in(array_keys(ComprobantePago::MEDIOS))],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.cantidad' => ['required', 'numeric', 'min:0'],
        ], [
            'motivo_descripcion.required' => 'Describe brevemente el motivo (sale en la nota de crédito).',
        ]);

        // 1. Se registra la nota y, si corresponde, la mercadería vuelve a sus lotes
        $nota = $notas->emitir($comprobante, $datos, $request->user());

        // 2. Se envía a SUNAT en el momento (la de boleta, por resumen)
        $sunat->enviar($nota);
        $nota->refresh();

        $mensaje = match ($nota->estado) {
            'aceptado', 'observado' => "Nota de crédito {$nota->numero} emitida y aceptada por SUNAT.",
            'enviado' => "Nota de crédito {$nota->numero} emitida. SUNAT aún procesa el resumen: consulta en unos segundos.",
            default => null,
        };

        return redirect("/comprobantes/{$nota->id}")->with(
            $mensaje ? 'success' : 'error',
            $mensaje ?? "Nota de crédito {$nota->numero} registrada, pero SUNAT respondió: {$nota->sunat_descripcion}"
        );
    }
}