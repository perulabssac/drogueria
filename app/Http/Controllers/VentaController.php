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
use App\Support\ProductoVenta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VentaController extends Controller
{
    /**
     * Nueva venta. Con ?cotizacion=ID se carga una cotización vigente (cliente, productos y precios cotizados).
     * Con ?nota=ID se convierte una nota de venta en boleta o factura (mismos productos, precios y pago).
     */
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

        $desdeNota = null;
        if ($request->filled('nota')) {
            $nota = Comprobante::query()
                ->where('sucursal_id', $request->user()->sucursal_id)
                ->with(['cliente', 'items', 'cuotas', 'pagos'])
                ->findOrFail($request->integer('nota'));

            if ($impedimento = $nota->impedimentoConversion()) {
                return redirect("/comprobantes/{$nota->id}")->with('error', $impedimento);
            }

            $desdeNota = $this->datosNota($nota, $request->user()->sucursal_id);
            if (! $desdeNota) {
                return redirect("/comprobantes/{$nota->id}")
                    ->with('error', 'Uno de los productos de la nota ya no está activo: actívalo en Productos o anula la nota y registra una venta nueva.');
            }
        }

        return Inertia::render('Ventas/Create', [
            'cotizacion' => $desdeCotizacion,
            'notaVenta' => $desdeNota,
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
            // Al convertir una nota de venta no se cobra de nuevo: se trasladan los pagos de la nota
            'pagos' => ['nullable', Rule::requiredIf(fn () => $request->input('forma_pago') === 'contado' && ! $request->filled('nota_venta_id')), 'array'],
            'pagos.*.medio' => ['required', Rule::in(array_keys(ComprobantePago::MEDIOS))],
            'pagos.*.monto' => ['required', 'numeric', 'gt:0'],
            'pagos.*.recibido' => ['nullable', 'numeric', 'gt:0'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:50'],
            'guia_remision' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]{4}-\d{1,8}$/i'],
            'orden_compra' => ['nullable', 'string', 'max:30'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'receta_verificada' => ['boolean'],
            'cotizacion_id' => ['nullable', 'integer', 'exists:cotizaciones,id'],
            'nota_venta_id' => ['nullable', 'integer', 'exists:comprobantes,id'],
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
        $origen = ! empty($datos['nota_venta_id']) ? ' Reemplaza a la nota de venta, que quedó anulada.' : '';

        $mensaje = match ($comprobante->estado) {
            'aceptado' => "{$comprobante->tipo_nombre} {$comprobante->numero} emitida y aceptada por SUNAT.",
            'observado' => "{$comprobante->tipo_nombre} {$comprobante->numero} aceptada por SUNAT con observaciones.",
            'enviado' => "{$comprobante->tipo_nombre} {$comprobante->numero} emitida. SUNAT aún procesa el resumen: consulta en unos segundos.",
            default => null,
        };

        return redirect("/comprobantes/{$comprobante->id}")->with(
            $mensaje ? 'success' : 'error',
            ($mensaje ?? "{$comprobante->tipo_nombre} {$comprobante->numero} registrada, pero no se pudo enviar a SUNAT: {$comprobante->sunat_descripcion}").$origen
        );
    }

    /**
     * Datos de la nota de venta para llenar la pantalla de venta: cliente, productos agrupados
     * (como se vendieron, sin separar por lote), cuotas y pagos.
     * Al stock de cada producto se le suma lo de la nota, porque al convertir vuelve a los lotes.
     */
    private function datosNota(Comprobante $nota, int $sucursalId): ?array
    {
        $ids = $nota->items->pluck('producto_id')->unique()->values();
        $productos = ProductoVenta::consulta($sucursalId)->whereKey($ids)->get()->keyBy('id');
        if ($productos->count() !== $ids->count()) {
            return null; // algún producto se desactivó después de la venta
        }

        // Unidades mínimas que salieron con la nota, por producto
        $unidadesNota = $nota->items->groupBy('producto_id')->map(fn ($lineas) => $lineas->sum(
            fn ($l) => (float) $l->cantidad * ($l->es_fraccion ? 1 : $productos[$l->producto_id]->factor())
        ));

        $items = $nota->items
            ->groupBy(fn ($l) => implode('|', [$l->producto_id, (int) $l->es_fraccion, (int) $l->bonificacion, $l->bonificacion ? '' : $l->precio_unitario]))
            ->map(function ($lineas) use ($productos, $unidadesNota) {
                $l = $lineas->first();
                $datos = ProductoVenta::datos($productos[$l->producto_id]);
                $datos['stock'] = (float) ($datos['stock'] ?? 0) + (float) $unidadesNota[$l->producto_id];

                return [
                    'producto' => $datos,
                    'cantidad' => round((float) $lineas->sum('cantidad'), 2),
                    'por_fraccion' => (bool) $l->es_fraccion,
                    'precio_unitario' => $l->bonificacion ? null : (float) $l->precio_unitario,
                    'bonificacion' => (bool) $l->bonificacion,
                ];
            })
            ->values();

        return [
            'id' => $nota->id,
            'numero' => $nota->numero,
            'total' => (float) $nota->total,
            'forma_pago' => $nota->forma_pago,
            'cliente' => $nota->cliente,
            'vendedor_id' => $nota->vendedor_id,
            'observaciones' => $nota->observaciones,
            'items' => $items,
            'cuotas' => $nota->cuotas->map(fn ($c) => ['monto' => (float) $c->monto, 'fecha' => Carbon::parse($c->fecha_vencimiento)->toDateString()])->values(),
            'pagos' => $nota->pagos->map(fn ($p) => ['medio_nombre' => $p->medio_nombre, 'monto' => (float) $p->monto, 'referencia' => $p->referencia])->values(),
        ];
    }
}