<?php

namespace App\Http\Controllers;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Empresa;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Services\InventarioService;
use App\Support\Precios;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CompraController extends Controller
{
    public const TASA_IGV = 0.18;

    public function index(Request $request): Response
    {
        $compras = Compra::query()
            ->with('proveedor:id,ruc,razon_social')
            ->withCount('items')
            ->where('sucursal_id', $request->user()->sucursal_id)
            ->when($request->filled('buscar'), function ($q) use ($request) {
                $b = $request->string('buscar')->toString();
                $q->where(fn ($w) => $w
                    ->where('numero', 'like', "%{$b}%")
                    ->orWhereHas('proveedor', fn ($p) => $p->where('razon_social', 'like', "%{$b}%")->orWhere('ruc', 'like', "{$b}%")));
            })
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha_emision', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha_emision', '<=', $request->date('hasta')))
            ->latest('fecha_emision')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Compras/Index', [
            'compras' => $compras,
            'filtros' => $request->only('buscar', 'desde', 'hasta'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Compras/Create', [
            'tiposDocumento' => Compra::TIPOS_DOCUMENTO,
        ]);
    }

    public function store(Request $request, InventarioService $inventario): RedirectResponse
    {
        $datos = $this->validar($request, conItems: true);
        $this->verificarDuplicada($datos);

        $usuario = $request->user();

        $compra = DB::transaction(function () use ($datos, $usuario, $inventario) {
            $compra = Compra::create([
                ...collect($datos)->except('items')->all(),
                'sucursal_id' => $usuario->sucursal_id,
                'user_id' => $usuario->id,
                'fecha_vencimiento' => $datos['forma_pago'] === 'credito' ? $datos['fecha_vencimiento'] : null,
            ]);

            $this->registrarItems($compra, $datos['items'], $usuario->id, $inventario);
            $this->recalcularTotales($compra);

            return $compra;
        });

        $mensaje = "Compra {$compra->documento} registrada. El stock ya está disponible.";
        if ($this->preciosSugeridos($compra)['sugeridos']) {
            $mensaje .= ' Revisa abajo los nuevos precios de venta sugeridos.';
        }

        return redirect("/compras/{$compra->id}")->with('success', $mensaje);
    }

    public function show(Request $request, Compra $compra): Response
    {
        $compra->load([
            'proveedor',
            'usuario:id,name',
            'items.producto:id,codigo,nombre,concentracion,presentacion,unidad_venta',
            'pagos.usuario:id,name',
        ]);

        // Solo almacén y admin cambian precios; en una compra anulada no tiene sentido
        $puedeActualizarPrecios = $compra->estado !== 'anulada' && $request->user()->tieneRol('almacen');
        $precios = $puedeActualizarPrecios ? $this->preciosSugeridos($compra) : ['sugeridos' => [], 'sinMargen' => []];

        $esAlmacen = $request->user()->tieneRol('almacen');
        $edicion = $this->estadoEdicion($compra);

        return Inertia::render('Compras/Show', [
            'compra' => $compra,
            'tiposDocumento' => Compra::TIPOS_DOCUMENTO,
            'puedeEditar' => $esAlmacen && $compra->estado !== 'anulada',
            'puedeEliminar' => $esAlmacen && $edicion['eliminar'],
            'motivoNoEliminar' => $edicion['motivoNoEliminar'],
            'puedeActualizarPrecios' => $puedeActualizarPrecios,
            'preciosSugeridos' => $precios['sugeridos'],
            'sinMargen' => $precios['sinMargen'],
        ]);
    }

    /**
     * Actualiza el precio de venta de los productos elegidos con su margen y su costo actual.
     * El precio se vuelve a calcular aquí (no se confía en el que manda el navegador).
     */
    public function actualizarPrecios(Request $request, Compra $compra): RedirectResponse
    {
        if ($compra->estado === 'anulada') {
            return back()->with('error', 'Esta compra está anulada.');
        }

        $deLaCompra = $compra->items()->where('bonificacion', false)->pluck('producto_id')->unique()->values()->all();

        $datos = $request->validate([
            'productos' => ['required', 'array', 'min:1'],
            'productos.*' => ['integer', Rule::in($deLaCompra)],
        ], [
            'productos.required' => 'Selecciona al menos un producto.',
            'productos.min' => 'Selecciona al menos un producto.',
            'productos.*.in' => 'Uno de los productos no pertenece a esta compra.',
        ]);

        $empresa = Empresa::actual();

        $actualizados = DB::transaction(function () use ($datos, $empresa) {
            $total = 0;
            foreach (Producto::whereIn('id', $datos['productos'])->lockForUpdate()->get() as $producto) {
                $nuevo = Precios::sugeridosProducto($producto, null, $empresa);
                if ($nuevo === null || $nuevo['precio_venta'] <= 0) {
                    continue;
                }

                $cambios = ['precio_venta' => $nuevo['precio_venta']];
                if ($nuevo['precio_fraccion'] !== null) {
                    $cambios['precio_fraccion'] = $nuevo['precio_fraccion'];
                }
                $producto->update($cambios);
                $total++;
            }

            return $total;
        });

        return back()->with('success', $actualizados === 1
            ? 'Se actualizó el precio de 1 producto.'
            : "Se actualizaron los precios de {$actualizados} productos.");
    }

    /** Anula la compra y retira del almacén lo que ingresó (si aún está). */
    public function anular(Request $request, Compra $compra, InventarioService $inventario): RedirectResponse
    {
        if ($compra->estado === 'anulada') {
            return back()->with('error', 'Esta compra ya está anulada.');
        }
        if ($compra->pagos()->where('estado', 'activo')->exists()) {
            return back()->with('error', 'Esta compra tiene pagos registrados al proveedor: anúlalos primero en Cuentas por pagar.');
        }

        DB::transaction(function () use ($compra, $request, $inventario) {
            $inventario->revertirEntradas($compra, $request->user()->id, 'anulacion_compra', neto: true);
            $compra->update(['estado' => 'anulada', 'saldo' => 0]);
        });

        return back()->with('success', "Compra {$compra->documento} anulada y stock retirado.");
    }

    /** Formulario para corregir una compra (usa la misma pantalla que "Registrar compra"). */
    public function edit(Compra $compra): Response|RedirectResponse
    {
        if ($compra->estado === 'anulada') {
            return redirect("/compras/{$compra->id}")->with('error', 'Una compra anulada no se puede editar.');
        }

        $compra->load(['proveedor:id,ruc,razon_social', 'items.producto.laboratorio:id,nombre']);
        $edicion = $this->estadoEdicion($compra);

        return Inertia::render('Compras/Create', [
            'tiposDocumento' => Compra::TIPOS_DOCUMENTO,
            'compra' => [
                'id' => $compra->id,
                'documento' => $compra->documento,
                'proveedor' => $compra->proveedor,
                'tipo_documento' => $compra->tipo_documento,
                'serie' => $compra->serie,
                'numero' => $compra->numero,
                'fecha_emision' => $compra->fecha_emision->toDateString(),
                'forma_pago' => $compra->forma_pago,
                'fecha_vencimiento' => $compra->fecha_vencimiento?->toDateString(),
                'observaciones' => $compra->observaciones,
                'total' => (float) $compra->total,
                'items' => $compra->items->map(fn ($item) => [
                    'producto' => [
                        'id' => $item->producto->id,
                        'codigo' => $item->producto->codigo,
                        'descripcion' => $item->producto->descripcionCompleta(),
                        'laboratorio' => $item->producto->laboratorio?->nombre,
                        'unidad_venta' => $item->producto->unidad_venta,
                        'tipo_afectacion_igv' => $item->producto->tipo_afectacion_igv,
                        'cadena_frio' => $item->producto->cadena_frio,
                        'costo' => (float) $item->producto->costo,
                    ],
                    'cantidad' => (float) $item->cantidad,
                    'numero_lote' => $item->numero_lote,
                    'vence' => $item->fecha_vencimiento->format('Y-m-01'),
                    'precio_unitario' => (float) $item->precio_unitario,
                    'valor' => (float) $item->valor,
                    'bonificacion' => $item->bonificacion,
                ])->values(),
            ],
            'puedeEditarItems' => $edicion['items'],
            'motivoSoloCabecera' => $edicion['motivoSoloCabecera'],
        ]);
    }

    /**
     * Guarda la corrección. Si la mercadería sigue intacta y no hay pagos, se pueden cambiar
     * también los productos: se retira del almacén lo que ingresó y se vuelve a ingresar
     * corregido (queda en el kárdex como "Corrección de compra").
     * Si no, solo se corrigen los datos del documento.
     */
    public function update(Request $request, Compra $compra, InventarioService $inventario): RedirectResponse
    {
        if ($compra->estado === 'anulada') {
            return back()->with('error', 'Una compra anulada no se puede editar.');
        }

        $edicion = $this->estadoEdicion($compra);
        $conItems = $edicion['items'];
        $datos = $this->validar($request, $conItems, $compra);
        $this->verificarDuplicada($datos, $compra);

        if (! $conItems) {
            // Con pagos registrados no se cambia la condición de pago (el saldo depende de ella)
            if ($compra->pagos()->where('estado', 'activo')->exists() && $datos['forma_pago'] !== $compra->forma_pago) {
                throw ValidationException::withMessages(['forma_pago' => 'Esta compra tiene pagos registrados: no se puede cambiar la condición de pago.']);
            }
            if ($compra->forma_pago !== $datos['forma_pago']) {
                throw ValidationException::withMessages(['forma_pago' => $edicion['motivoSoloCabecera']]);
            }
        }

        $usuario = $request->user();

        DB::transaction(function () use ($compra, $datos, $conItems, $usuario, $inventario) {
            $compra->update([
                ...collect($datos)->except('items')->all(),
                'fecha_vencimiento' => $datos['forma_pago'] === 'credito' ? $datos['fecha_vencimiento'] : null,
            ]);

            if (! $conItems) {
                return;
            }

            // Retira del almacén lo que ingresó esta compra y la vuelve a registrar corregida
            $inventario->revertirEntradas($compra, $usuario->id, 'edicion_compra', neto: true, accion: 'corregir');
            $this->corregirVencimientos($compra, $datos['items']);
            $compra->items()->delete();
            $this->registrarItems($compra, $datos['items'], $usuario->id, $inventario);
            $this->recalcularTotales($compra);
        });

        return redirect("/compras/{$compra->id}")->with('success', "Compra {$compra->documento} actualizada.".($conItems ? ' El stock ya refleja los cambios.' : ''));
    }

    /**
     * Elimina definitivamente una compra registrada por error, junto con sus lotes y su kárdex.
     * Solo si no tiene pagos y sus lotes no tuvieron ningún otro movimiento (ventas, ajustes,
     * otras compras con el mismo lote). En los demás casos se debe anular.
     */
    public function destroy(Compra $compra): RedirectResponse
    {
        $edicion = $this->estadoEdicion($compra);
        if (! $edicion['eliminar']) {
            return back()->with('error', $edicion['motivoNoEliminar']);
        }

        $documento = $compra->documento;
        $loteIds = $this->lotesDeLaCompra($compra);
        $productoIds = $compra->items()->pluck('producto_id')->unique();

        try {
            DB::transaction(function () use ($compra, $loteIds, $productoIds) {
                $compra->items()->delete();
                $this->movimientosDeLaCompra($compra)->delete();
                Lote::whereIn('id', $loteIds)->delete();
                $compra->delete();

                // El costo del producto vuelve al de su compra vigente anterior
                foreach (Producto::whereIn('id', $productoIds)->get() as $producto) {
                    $this->restaurarCosto($producto);
                }
            });
        } catch (QueryException) {
            return back()->with('error', "No se puede eliminar la compra {$documento}: sus lotes están relacionados con otros registros. Anúlala en su lugar.");
        }

        return redirect('/compras')->with('success', "Compra {$documento} eliminada.");
    }

    /**
     * Qué se puede hacer con la compra:
     * - items: corregir productos, cantidades, lotes y precios (mercadería intacta y sin pagos)
     * - eliminar: borrarla del todo (sin pagos y lotes usados solo por esta compra)
     *
     * @return array{items: bool, motivoSoloCabecera: ?string, eliminar: bool, motivoNoEliminar: ?string}
     */
    private function estadoEdicion(Compra $compra): array
    {
        $inventario = app(InventarioService::class);
        $anulada = $compra->estado === 'anulada';
        $pagosActivos = $compra->pagos()->where('estado', 'activo')->exists();
        $intacta = ! $anulada && $inventario->entradasIntactas($compra);

        $motivoSoloCabecera = match (true) {
            $anulada => 'La compra está anulada.',
            $pagosActivos => 'Tiene pagos registrados al proveedor: solo se pueden corregir los datos del documento. Para cambiar productos, anula los pagos en Cuentas por pagar.',
            ! $intacta => 'Parte de la mercadería ya se vendió o se movió: solo se pueden corregir los datos del documento. Para cambiar productos, anula la compra y regístrala de nuevo.',
            default => null,
        };

        // Para eliminar: ningún pago (ni anulado) y lotes que solo se movieron por esta compra
        $motivoNoEliminar = null;
        if ($compra->pagos()->exists()) {
            $motivoNoEliminar = 'No se puede eliminar: tiene pagos al proveedor registrados (aunque estén anulados). Anula la compra en su lugar.';
        } else {
            $compartido = $this->lotesDeLaCompra($compra)->contains(fn ($id) => ! $this->loteExclusivo($id, $compra));

            if ($compartido) {
                $motivoNoEliminar = 'No se puede eliminar: sus lotes ya tuvieron otros movimientos (ventas, ajustes u otras compras del mismo lote). Anula la compra en su lugar.';
            }
        }

        return [
            'items' => $motivoSoloCabecera === null,
            'motivoSoloCabecera' => $motivoSoloCabecera,
            'eliminar' => $motivoNoEliminar === null,
            'motivoNoEliminar' => $motivoNoEliminar,
        ];
    }

    /**
     * Si se corrigió la fecha de vencimiento de un lote que solo usa esta compra (y que ya quedó
     * en cero al revertirla), se actualiza la fecha del lote para poder volver a ingresarlo.
     */
    private function corregirVencimientos(Compra $compra, array $lineas): void
    {
        foreach (Lote::whereIn('id', $this->lotesDeLaCompra($compra))->lockForUpdate()->get() as $lote) {
            $linea = collect($lineas)->first(fn ($l) => (int) $l['producto_id'] === $lote->producto_id
                && mb_strtoupper(trim($l['numero_lote'])) === $lote->numero_lote);

            if (! $linea || $lote->fecha_vencimiento->toDateString() === $linea['fecha_vencimiento']) {
                continue;
            }
            if ((float) $lote->cantidad > 0.0001 || ! $this->loteExclusivo($lote->id, $compra)) {
                continue; // lo usa otro documento: se mantiene su fecha (ingresarLote avisará)
            }

            $lote->update(['fecha_vencimiento' => $linea['fecha_vencimiento']]);
        }
    }

    /** true si el lote solo tuvo movimientos de esta compra. */
    private function loteExclusivo(int $loteId, Compra $compra): bool
    {
        $otrosMovimientos = MovimientoInventario::query()
            ->where('lote_id', $loteId)
            ->where(fn ($q) => $q
                ->where('referencia_type', '!=', $compra->getMorphClass())
                ->orWhere('referencia_id', '!=', $compra->getKey())
                ->orWhereNull('referencia_type'))
            ->exists();

        return ! $otrosMovimientos && ! CompraItem::query()
            ->where('lote_id', $loteId)
            ->where('compra_id', '!=', $compra->id)
            ->exists();
    }

    private function movimientosDeLaCompra(Compra $compra)
    {
        return MovimientoInventario::query()
            ->where('referencia_type', $compra->getMorphClass())
            ->where('referencia_id', $compra->getKey());
    }

    /** Lotes que tocó la compra (por su detalle y por su kárdex, incluidas correcciones anteriores). */
    private function lotesDeLaCompra(Compra $compra)
    {
        return $compra->items()->whereNotNull('lote_id')->pluck('lote_id')
            ->merge($this->movimientosDeLaCompra($compra)->pluck('lote_id'))
            ->unique()
            ->values();
    }

    /** Costo del producto = el de su última compra vigente (si no tiene, se deja como está). */
    private function restaurarCosto(Producto $producto): void
    {
        $ultimo = CompraItem::query()
            ->where('producto_id', $producto->id)
            ->where('bonificacion', false)
            ->where('cantidad', '>', 0)
            ->whereHas('compra', fn ($q) => $q->where('estado', '!=', 'anulada'))
            ->latest('id')
            ->first();

        if ($ultimo) {
            $producto->update(['costo' => round((float) $ultimo->valor / (float) $ultimo->cantidad, 4)]);
        }
    }

    /** Reglas del formulario (registrar y editar). */
    private function validar(Request $request, bool $conItems, ?Compra $compra = null): array
    {
        $reglas = [
            'proveedor_id' => ['required', 'exists:proveedores,id'],
            'tipo_documento' => ['required', Rule::in(array_keys(Compra::TIPOS_DOCUMENTO))],
            'serie' => ['required', 'string', 'max:4'],
            'numero' => ['required', 'digits_between:1,10'],
            'fecha_emision' => ['required', 'date', 'before_or_equal:today'],
            'forma_pago' => ['required', 'in:contado,credito'],
            'fecha_vencimiento' => ['nullable', 'required_if:forma_pago,credito', 'date', 'after_or_equal:fecha_emision'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ];

        if ($conItems) {
            $reglas += [
                'items' => ['required', 'array', 'min:1'],
                'items.*.producto_id' => ['required', 'exists:productos,id'],
                'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
                'items.*.numero_lote' => ['required', 'string', 'max:50'],
                // Al corregir una compra antigua, el lote solo debe vencer después de la compra
                'items.*.fecha_vencimiento' => ['required', 'date', $compra ? 'after:fecha_emision' : 'after:today'],
                'items.*.bonificacion' => ['boolean'],
                'items.*.precio_unitario' => ['nullable', 'numeric', 'min:0'],
                // "Valor venta" de la línea tal cual en la factura (sin IGV y con descuentos)
                'items.*.valor_venta' => ['nullable', 'numeric', 'min:0'],
            ];
        }

        $datos = $request->validate($reglas, [
            'items.required' => 'Agrega al menos un producto.',
            'items.*.numero_lote.required' => 'Falta el lote.',
            'items.*.fecha_vencimiento.required' => 'Falta el vencimiento.',
            'items.*.fecha_vencimiento.after' => $compra ? 'Vence antes de la fecha de la compra.' : 'Producto vencido.',
            'fecha_vencimiento.required_if' => 'Indica la fecha de pago del crédito.',
        ]);

        $datos['serie'] = mb_strtoupper(trim($datos['serie']));
        $datos['numero'] = ltrim($datos['numero'], '0') ?: '0';

        return $datos;
    }

    /** El mismo documento del mismo proveedor no se registra dos veces. */
    private function verificarDuplicada(array $datos, ?Compra $excepto = null): void
    {
        $duplicada = Compra::query()
            ->where('proveedor_id', $datos['proveedor_id'])
            ->where('tipo_documento', $datos['tipo_documento'])
            ->where('serie', $datos['serie'])
            ->where('numero', $datos['numero'])
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto->id))
            ->exists();

        if ($duplicada) {
            throw ValidationException::withMessages(['numero' => 'Este documento del proveedor ya fue registrado.']);
        }
    }

    /** Crea el detalle de la compra e ingresa cada línea a su lote. Va dentro de una transacción. */
    private function registrarItems(Compra $compra, array $lineas, int $userId, InventarioService $inventario): void
    {
        foreach ($lineas as $i => $linea) {
            $producto = Producto::findOrFail($linea['producto_id']);
            $bonificacion = (bool) ($linea['bonificacion'] ?? false);
            $cantidad = round((float) $linea['cantidad'], 2);
            $gravado = $producto->tipo_afectacion_igv === '10';
            $valorVenta = $bonificacion ? 0 : round((float) ($linea['valor_venta'] ?? 0), 2);

            if ($valorVenta > 0) {
                // Modo factura: se usa el "Valor venta" impreso tal cual (exacto, sin recalcular)
                $valor = $valorVenta;
                $total = $gravado ? round($valor * (1 + self::TASA_IGV), 2) : $valor;
                $precio = round($total / $cantidad, 3); // solo referencial
            } else {
                $precio = $bonificacion ? 0 : round((float) ($linea['precio_unitario'] ?? 0), 3);
                if (! $bonificacion && $precio <= 0) {
                    throw ValidationException::withMessages(["items.{$i}.precio_unitario" => 'Falta el precio.']);
                }

                // Precio unitario con IGV: separamos la base imponible
                $total = round($cantidad * $precio, 2);
                $valor = $gravado ? round($total / (1 + self::TASA_IGV), 2) : $total;
            }
            $costoSinIgv = $bonificacion ? 0 : round($valor / $cantidad, 4); // por presentación

            $item = $compra->items()->create([
                'producto_id' => $producto->id,
                'numero_lote' => mb_strtoupper(trim($linea['numero_lote'])),
                'fecha_vencimiento' => $linea['fecha_vencimiento'],
                'cantidad' => $cantidad,
                'bonificacion' => $bonificacion,
                'precio_unitario' => $precio,
                'tipo_afectacion_igv' => $producto->tipo_afectacion_igv,
                'valor' => $valor,
                'igv' => round($total - $valor, 2),
                'total' => $total,
            ]);

            // Ingreso al almacén: la cantidad se guarda en unidad mínima
            $lote = $inventario->ingresarLote([
                'producto_id' => $producto->id,
                'sucursal_id' => $compra->sucursal_id,
                'numero_lote' => $item->numero_lote,
                'fecha_vencimiento' => $item->fecha_vencimiento->toDateString(),
                'cantidad' => $cantidad * $producto->factor(),
                'costo_unitario' => $costoSinIgv,
            ], $userId, $bonificacion ? 'bonificacion' : 'compra', $compra);

            $item->update(['lote_id' => $lote->id]);

            // El último costo de compra queda como costo del producto
            if (! $bonificacion) {
                $producto->update(['costo' => $costoSinIgv]);
            }
        }
    }

    private function recalcularTotales(Compra $compra): void
    {
        $items = $compra->items()->get();
        $gravadas = round($items->where('tipo_afectacion_igv', '10')->sum('valor'), 2);
        $exoneradas = round($items->where('tipo_afectacion_igv', '!=', '10')->sum('valor'), 2);
        // Como en la factura del proveedor: el IGV se calcula una vez sobre el total gravado
        // (sumar el IGV de cada línea puede diferir en céntimos)
        $igv = round($gravadas * self::TASA_IGV, 2);
        $total = round($gravadas + $exoneradas + $igv, 2);

        $compra->update([
            'op_gravadas' => $gravadas,
            'op_exoneradas' => $exoneradas,
            'igv' => $igv,
            'total' => $total,
            // Al crédito se le debe todo al proveedor hasta registrar sus pagos (Cuentas por pagar)
            'saldo' => $compra->forma_pago === 'credito' ? $total : 0,
        ]);
    }

    /**
     * Precios de venta sugeridos para los productos de la compra (sin bonificaciones),
     * con el margen de cada producto y su último costo. Solo se listan los que cambian.
     *
     * @return array{sugeridos: array, sinMargen: array}
     */
    private function preciosSugeridos(Compra $compra): array
    {
        $empresa = Empresa::actual();
        $ids = $compra->items()->where('bonificacion', false)->pluck('producto_id')->unique();
        $sugeridos = [];
        $sinMargen = [];

        foreach (Producto::whereIn('id', $ids)->orderBy('nombre')->get() as $producto) {
            $nombre = trim("{$producto->nombre} {$producto->concentracion} {$producto->presentacion}");
            $nuevo = Precios::sugeridosProducto($producto, null, $empresa);

            if ($nuevo === null) {
                $sinMargen[] = ['id' => $producto->id, 'codigo' => $producto->codigo, 'nombre' => $nombre];

                continue;
            }

            $precioActual = (float) $producto->precio_venta;
            $fraccionActual = $producto->precio_fraccion !== null ? (float) $producto->precio_fraccion : null;
            $cambiaPrecio = abs($nuevo['precio_venta'] - $precioActual) >= 0.005;
            $cambiaFraccion = $nuevo['precio_fraccion'] !== null && abs($nuevo['precio_fraccion'] - (float) $fraccionActual) >= 0.005;

            if ($nuevo['precio_venta'] <= 0 || (! $cambiaPrecio && ! $cambiaFraccion)) {
                continue;
            }

            $sugeridos[] = [
                'id' => $producto->id,
                'codigo' => $producto->codigo,
                'nombre' => $nombre,
                'unidad_venta' => $producto->unidad_venta,
                'unidad_fraccion' => $producto->unidad_fraccion,
                'costo' => (float) $producto->costo,
                'margen' => $nuevo['margen'],
                'precio_actual' => $precioActual,
                'precio_sugerido' => $nuevo['precio_venta'],
                'fraccion_actual' => $fraccionActual,
                'fraccion_sugerida' => $nuevo['precio_fraccion'],
            ];
        }

        return ['sugeridos' => $sugeridos, 'sinMargen' => $sinMargen];
    }
}
