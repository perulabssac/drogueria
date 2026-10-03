<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\ComprobantePago;
use App\Models\Cotizacion;
use App\Models\Serie;
use App\Models\User;
use App\Services\CotizacionService;
use App\Services\Sunat\SunatService;
use App\Services\VentaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VentaController extends Controller
{
    /** Nueva venta. Con ?cotizacion=ID se carga una cotización vigente (cliente, productos y precios cotizados). */
    public function create(Request $request, CotizacionService $cotizaciones): Response|RedirectResponse
    {
        $desdeCotizacion = null;
        if ($request->filled('cotizacion')) {
            $cotizacion = Cotizacion::query()
                ->where('sucursal_id', $request->user()->sucursal_id)
                ->with('cliente')
                ->findOrFail($request->integer('cotizacion'));

            if (! $cotizacion->vigente()) {
                return redirect("/cotizaciones/{$cotizacion->id}")
                    ->with('error', "La cotización {$cotizacion->numero} ya no está vigente (vendida, vencida o anulada).");
            }

            $desdeCotizacion = [
                'id' => $cotizacion->id,
                'numero' => $cotizacion->numero,
                'cliente' => $cotizacion->cliente->only(CotizacionService::CAMPOS_CLIENTE),
                'vendedor_id' => $cotizacion->vendedor_id,
                'forma_pago' => $cotizacion->forma_pago,
                'observaciones' => $cotizacion->observaciones,
                'items' => $cotizaciones->lineasParaFormulario($cotizacion, $request->user()->sucursal_id),
            ];
        }

        return Inertia::render('Ventas/Create', [
            'cotizacion' => $desdeCotizacion,
            'series' => Serie::query()
                ->where('sucursal_id', $request->user()->sucursal_id)
                ->whereIn('tipo_comprobante', ['01', '03', Comprobante::NOTA_VENTA])
                ->where('activo', true)
                ->orderBy('serie')
                ->get(['id', 'tipo_comprobante', 'serie', 'correlativo']),
            'clienteVarios' => Cliente::clientesVarios()->only(['id', 'tipo_documento', 'numero_documento', 'razon_social', 'direccion', 'dias_credito']),
            'vendedores' => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
            'montoIdentificarBoleta' => VentaService::BOLETA_MONTO_IDENTIFICAR,
            'mediosPago' => ComprobantePago::MEDIOS,
            'cajaAbierta' => (bool) Caja::abiertaDe($request->user()),
        ]);
    }

    public function store(Request $request, VentaService $ventas, SunatService $sunat): RedirectResponse
    {
        $datos = $request->validate([
            'serie_id' => ['required', 'integer', 'exists:series,id'],
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'vendedor_id' => ['nullable', 'integer', 'exists:users,id'],
            'forma_pago' => ['required', 'in:contado,credito'],
            'cuotas' => ['nullable', 'required_if:forma_pago,credito', 'array'],
            'cuotas.*.monto' => ['required', 'numeric', 'gt:0'],
            'cuotas.*.fecha_vencimiento' => ['required', 'date'],
            'pagos' => ['nullable', 'required_if:forma_pago,contado', 'array'],
            'pagos.*.medio' => ['required', Rule::in(array_keys(ComprobantePago::MEDIOS))],
            'pagos.*.monto' => ['required', 'numeric', 'gt:0'],
            'pagos.*.recibido' => ['nullable', 'numeric', 'gt:0'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:50'],
            'guia_remision' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]{4}-\d{1,8}$/i'],
            'orden_compra' => ['nullable', 'string', 'max:30'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'receta_verificada' => ['boolean'],
            'cotizacion_id' => ['nullable', 'integer', 'exists:cotizaciones,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'integer', 'exists:productos,id'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.por_fraccion' => ['boolean'],
            'items.*.bonificacion' => ['boolean'],
            'items.*.precio_unitario' => ['nullable', 'required_unless:items.*.bonificacion,true', 'numeric', 'gt:0'],
        ], [
            'items.required' => 'Agrega al menos un producto.',
            'cuotas.required_if' => 'Indica las cuotas de la venta al crédito.',
            'pagos.required_if' => 'Indica con qué medio se pagó la venta.',
            'guia_remision.regex' => 'La guía debe tener serie y número separados por guion, por ejemplo T001-123.',
            'items.*.precio_unitario.required_unless' => 'Falta el precio.',
        ]);

        // 1. Se registra la venta y se descuenta el stock (todo o nada)
        $comprobante = $ventas->registrar($datos, $request->user());

        // La nota de venta es interna: no se envía a SUNAT
        if ($comprobante->esInterno()) {
            return redirect("/comprobantes/{$comprobante->id}")
                ->with('success', "Nota de venta {$comprobante->numero} registrada (documento interno, no se envía a SUNAT).");
        }

        // 2. Se envía a SUNAT en el momento
        $sunat->enviar($comprobante);
        $comprobante->refresh();

        $mensaje = match ($comprobante->estado) {
            'aceptado' => "{$comprobante->tipo_nombre} {$comprobante->numero} emitida y aceptada por SUNAT.",
            'observado' => "{$comprobante->tipo_nombre} {$comprobante->numero} aceptada por SUNAT con observaciones.",
            'enviado' => "{$comprobante->tipo_nombre} {$comprobante->numero} emitida. SUNAT aún procesa el resumen: consulta en unos segundos.",
            default => null,
        };

        return redirect("/comprobantes/{$comprobante->id}")->with(
            $mensaje ? 'success' : 'error',
            $mensaje ?? "{$comprobante->tipo_nombre} {$comprobante->numero} registrada, pero no se pudo enviar a SUNAT: {$comprobante->sunat_descripcion}"
        );
    }
}