<?php

namespace App\Http\Controllers;

use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\Guia;
use App\Services\GuiaService;
use App\Services\Sunat\GuiaSunatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Guías de remisión electrónicas. Las emiten ventas y almacén (despacho); el contador solo las ve.
 */
class GuiaController extends Controller
{
    public const ESTADOS = [
        'pendiente' => 'Pendiente',
        'enviado' => 'En proceso',
        'aceptado' => 'Aceptada',
        'rechazado' => 'Rechazada',
        'error' => 'Error de envío',
    ];

    public function index(Request $request): Response
    {
        $buscar = trim($request->string('buscar')->toString());

        $guias = Guia::query()
            ->with('comprobante:id,tipo_comprobante,serie,correlativo')
            ->withCount('items')
            ->where('sucursal_id', $request->user()->sucursal_id)
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')->toString()))
            ->when($buscar, fn ($q) => $q->where(fn ($w) => $w
                ->where('destinatario_nombre', 'like', "%{$buscar}%")
                ->orWhere('destinatario_num_doc', 'like', "{$buscar}%")
                ->orWhere('correlativo', (int) preg_replace('/\D/', '', $buscar) ?: 0)))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Guias/Index', [
            'guias' => $guias,
            'filtros' => $request->only('estado', 'buscar'),
            'estados' => self::ESTADOS,
        ]);
    }

    public function create(Request $request, GuiaService $servicio): Response
    {
        $comprobante = $request->filled('comprobante')
            ? Comprobante::query()->where('sucursal_id', $request->user()->sucursal_id)->find($request->integer('comprobante'))
            : null;

        return Inertia::render('Guias/Create', [
            'datos' => $servicio->sugerencia($comprobante, $request->user()),
            'comprobante' => $comprobante?->only('id', 'numero', 'tipo_nombre', 'estado'),
            'motivos' => Guia::MOTIVOS,
            'modalidades' => Guia::MODALIDADES,
        ]);
    }

    public function store(Request $request, GuiaService $servicio, GuiaSunatService $sunat): RedirectResponse
    {
        $datos = $this->validar($request);

        $guia = $servicio->registrar($datos, $request->user());
        $sunat->enviar($guia);

        return redirect("/guias/{$guia->id}")->with(...$this->mensaje($guia->refresh()));
    }

    public function show(Request $request, Guia $guia): Response
    {
        $this->verificarSucursal($request, $guia);
        $guia->load('items', 'usuario:id,name', 'comprobante:id,tipo_comprobante,serie,correlativo');

        return Inertia::render('Guias/Show', [
            'guia' => $guia,
            'motivos' => Guia::MOTIVOS,
            'modalidades' => Guia::MODALIDADES,
            'estados' => self::ESTADOS,
            'puedeEnviar' => $request->user()->tieneRol('vendedor', 'almacen'),
        ]);
    }

    /** Reenvía (si falló) o consulta el ticket (si SUNAT seguía procesando). */
    public function enviar(Request $request, Guia $guia, GuiaSunatService $sunat): RedirectResponse
    {
        $this->verificarSucursal($request, $guia);
        $sunat->enviar($guia);

        return back()->with(...$this->mensaje($guia->refresh()));
    }

    public function imprimir(Request $request, Guia $guia): Response
    {
        $this->verificarSucursal($request, $guia);
        $guia->load('items', 'comprobante:id,tipo_comprobante,serie,correlativo');

        return Inertia::render('Guias/Imprimir', [
            'guia' => $guia,
            'empresa' => Empresa::actual()->only('ruc', 'razon_social', 'nombre_comercial', 'direccion', 'telefono', 'email'),
            'motivos' => Guia::MOTIVOS,
        ]);
    }

    public function xml(Request $request, Guia $guia): StreamedResponse
    {
        $this->verificarSucursal($request, $guia);
        abort_unless($guia->xml_path && Storage::disk('local')->exists($guia->xml_path), 404, 'Aún no se generó el XML.');

        return Storage::disk('local')->download($guia->xml_path);
    }

    public function cdr(Request $request, Guia $guia): StreamedResponse
    {
        $this->verificarSucursal($request, $guia);
        abort_unless($guia->cdr_path && Storage::disk('local')->exists($guia->cdr_path), 404, 'Aún no hay CDR de SUNAT.');

        return Storage::disk('local')->download($guia->cdr_path);
    }

    // ================= Apoyo =================

    private function validar(Request $request): array
    {
        $privado = $request->input('modalidad') === '02';

        return $request->validate([
            'comprobante_id' => ['nullable', 'integer', 'exists:comprobantes,id'],
            'fecha_traslado' => ['required', 'date', 'after_or_equal:today'],
            'motivo' => ['required', Rule::in(array_keys(Guia::MOTIVOS))],
            'motivo_descripcion' => ['nullable', 'required_if:motivo,13', 'string', 'max:100'],
            'modalidad' => ['required', Rule::in(array_keys(Guia::MODALIDADES))],
            'destinatario_tipo_doc' => ['required', Rule::in(['1', '4', '6', '7'])],
            'destinatario_num_doc' => ['required', 'string', 'max:15'],
            'destinatario_nombre' => ['required', 'string', 'max:200'],
            'partida_ubigeo' => ['required', 'digits:6'],
            'partida_direccion' => ['required', 'string', 'max:200'],
            'llegada_ubigeo' => ['required', 'digits:6'],
            'llegada_direccion' => ['required', 'string', 'max:200'],
            'peso_total' => ['required', 'numeric', 'gt:0'],
            'unidad_peso' => ['required', Rule::in(['KGM', 'TNE'])],
            // Transporte público
            'transportista_ruc' => [$privado ? 'nullable' : 'required', 'digits:11'],
            'transportista_nombre' => [$privado ? 'nullable' : 'required', 'string', 'max:200'],
            'transportista_mtc' => ['nullable', 'string', 'max:20'],
            // Transporte privado
            'vehiculo_placa' => [$privado ? 'required' : 'nullable', 'string', 'max:10'],
            'conductor_tipo_doc' => [$privado ? 'required' : 'nullable', Rule::in(['1', '4', '7'])],
            'conductor_num_doc' => [$privado ? 'required' : 'nullable', 'string', 'max:15'],
            'conductor_nombres' => [$privado ? 'required' : 'nullable', 'string', 'max:100'],
            'conductor_apellidos' => [$privado ? 'required' : 'nullable', 'string', 'max:100'],
            'conductor_licencia' => [$privado ? 'required' : 'nullable', 'string', 'max:20'],
            'observaciones' => ['nullable', 'string', 'max:250'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.producto_id' => ['nullable', 'integer', 'exists:productos,id'],
            'items.*.codigo' => ['nullable', 'string', 'max:30'],
            'items.*.descripcion' => ['required', 'string', 'max:250'],
            'items.*.unidad' => ['required', 'string', 'max:3'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
        ], [
            'fecha_traslado.after_or_equal' => 'El traslado no puede ser en una fecha pasada.',
            'motivo_descripcion.required_if' => 'Describe el motivo del traslado.',
            'partida_ubigeo.digits' => 'El ubigeo tiene 6 dígitos (ej. 150101 = Lima, Lima, Lima).',
            'llegada_ubigeo.digits' => 'El ubigeo tiene 6 dígitos (ej. 150101 = Lima, Lima, Lima).',
            'transportista_ruc.required' => 'Indica el RUC de la empresa de transportes.',
            'vehiculo_placa.required' => 'Indica la placa del vehículo.',
            'conductor_num_doc.required' => 'Indica el DNI del conductor.',
            'conductor_licencia.required' => 'Indica la licencia del conductor.',
            'items.required' => 'La guía debe tener al menos un producto.',
        ]);
    }

    private function mensaje(Guia $guia): array
    {
        return match ($guia->estado) {
            'aceptado' => ['success', "SUNAT aceptó la guía {$guia->numero}."],
            'enviado' => ['success', "Guía {$guia->numero} enviada: SUNAT aún la está procesando. Consulta en unos segundos."],
            default => ['error', "Guía {$guia->numero}: {$guia->sunat_descripcion}"],
        };
    }

    private function verificarSucursal(Request $request, Guia $guia): void
    {
        abort_if((int) $guia->sucursal_id !== (int) $request->user()->sucursal_id, 404);
    }
}