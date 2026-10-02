<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Services\ClienteService;
use App\Services\DecolectaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clientes: lista, ficha, consulta a SUNAT/RENIEC y búsqueda rápida para la pantalla de ventas.
 * Las condiciones de crédito (días y límite) solo las cambia el administrador.
 */
class ClienteController extends Controller
{
    private const CAMPOS_JSON = ['id', 'tipo_documento', 'numero_documento', 'razon_social', 'direccion', 'dias_credito', 'limite_credito', 'estado_sunat', 'condicion_sunat'];

    public function index(Request $request): Response
    {
        $buscar = trim($request->string('buscar')->toString());

        $clientes = Cliente::query()
            ->where('numero_documento', '!=', '00000000')
            // Deuda actual (ventas al crédito con saldo)
            ->withSum(['comprobantes as deuda' => fn ($q) => $q->where('forma_pago', 'credito')->where('estado', '!=', 'rechazado')], 'saldo')
            ->when($buscar, fn ($q) => $q->where(fn ($w) => $w
                ->where('razon_social', 'like', "%{$buscar}%")
                ->orWhere('nombre_comercial', 'like', "%{$buscar}%")
                ->orWhere('numero_documento', 'like', "{$buscar}%")))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo_documento', $request->string('tipo')->toString()))
            ->when($request->input('filtro') === 'con_deuda', fn ($q) => $q->whereHas('comprobantes', fn ($c) => $c
                ->where('forma_pago', 'credito')->where('estado', '!=', 'rechazado')->where('saldo', '>', 0)))
            ->when($request->input('filtro') === 'sunat', fn ($q) => $q->where('tipo_documento', Cliente::RUC)
                ->where(fn ($w) => $w->where('estado_sunat', '!=', 'ACTIVO')->orWhere('condicion_sunat', '!=', 'HABIDO')))
            ->when($request->input('filtro') === 'inactivos', fn ($q) => $q->where('activo', false))
            ->orderBy('razon_social')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Clientes/Index', [
            'clientes' => $clientes,
            'filtros' => $request->only('buscar', 'tipo', 'filtro'),
            'tipos' => Cliente::TIPOS_DOCUMENTO,
            'consultaActiva' => DecolectaService::configurado(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Clientes/Form', $this->datosFormulario(null));
    }

    public function edit(Cliente $cliente): Response
    {
        abort_if($cliente->esClientesVarios(), 403, '"Clientes varios" no se edita.');

        return Inertia::render('Clientes/Form', $this->datosFormulario($cliente));
    }

    public function store(Request $request): RedirectResponse
    {
        $cliente = Cliente::create($this->validar($request, null));

        return redirect('/clientes')->with('success', "Cliente {$cliente->razon_social} registrado.");
    }

    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        abort_if($cliente->esClientesVarios(), 403);
        $cliente->update($this->validar($request, $cliente));

        return redirect('/clientes')->with('success', "Cliente {$cliente->razon_social} actualizado.");
    }

    /** Botón "Actualizar desde SUNAT/RENIEC" de la ficha (gasta 1 consulta). */
    public function verificar(Cliente $cliente, ClienteService $servicio): RedirectResponse
    {
        $servicio->actualizarDesdeSunat($cliente);
        $problema = $cliente->problemaSunat();

        return back()->with($problema ? 'error' : 'success', $problema
            ? "Datos actualizados. Atención: {$problema}"
            : 'Datos actualizados desde '.($cliente->esRuc() ? 'SUNAT' : 'RENIEC').'.');
    }

    // ================= JSON para la pantalla de ventas y el formulario =================

    /** Búsqueda por nombre o número entre los clientes ya registrados. */
    public function buscar(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        return response()->json(
            Cliente::query()
                ->where('activo', true)
                ->when($request->filled('tipo'), fn ($b) => $b->where('tipo_documento', $request->string('tipo')->toString()))
                ->where(fn ($w) => $w->where('numero_documento', 'like', "{$q}%")
                    ->orWhere('razon_social', 'like', "%{$q}%")
                    ->orWhere('nombre_comercial', 'like', "%{$q}%"))
                ->orderBy('razon_social')
                ->limit(15)
                ->get(self::CAMPOS_JSON)
        );
    }

    /**
     * Consulta por DNI/RUC: si ya existe lo devuelve; si no, lo trae de SUNAT/RENIEC y lo guarda.
     * Se usa en la venta (escribir el número y pulsar "Buscar") y en el formulario de cliente.
     */
    public function consultar(Request $request, ClienteService $servicio): JsonResponse
    {
        $request->validate(['numero' => ['required', 'string', 'max:15']]);
        ['cliente' => $cliente, 'nuevo' => $nuevo] = $servicio->buscarOCrear($request->string('numero')->toString());

        return response()->json([
            'cliente' => $cliente->only(self::CAMPOS_JSON),
            'nuevo' => $nuevo,
            'aviso' => $cliente->problemaSunat(),
        ], $nuevo ? 201 : 200);
    }

    /** Registro manual (cuando no hay consulta o es carnet de extranjería/pasaporte). */
    public function guardarRapido(Request $request): JsonResponse
    {
        $cliente = Cliente::create($this->validar($request, null, rapido: true));

        return response()->json($cliente->only(self::CAMPOS_JSON), 201);
    }

    // ================= Apoyo =================

    private function datosFormulario(?Cliente $cliente): array
    {
        return [
            'cliente' => $cliente ? [
                ...$cliente->toArray(),
                'deuda' => $cliente->deuda(),
                'deuda_vencida' => $cliente->tieneDeudaVencida(),
                'problema_sunat' => $cliente->problemaSunat(),
            ] : null,
            'tipos' => collect(Cliente::TIPOS_DOCUMENTO)->except('0')->all(),
            'consultaActiva' => DecolectaService::configurado(),
            'puedeEditarCredito' => request()->user()->tieneRol('admin'),
        ];
    }

    private function validar(Request $request, ?Cliente $cliente, bool $rapido = false): array
    {
        $tipo = $request->input('tipo_documento');

        $reglas = [
            'tipo_documento' => ['required', Rule::in([Cliente::DNI, '4', Cliente::RUC, '7'])],
            'numero_documento' => [
                'required', 'string',
                $tipo === Cliente::RUC ? 'regex:/^(10|15|17|20)\d{9}$/' : ($tipo === Cliente::DNI ? 'digits:8' : 'max:15'),
                Rule::unique('clientes')->where('tipo_documento', $tipo)->ignore($cliente),
            ],
            'razon_social' => ['required', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
        ];

        if (! $rapido) {
            $reglas += [
                'nombre_comercial' => ['nullable', 'string', 'max:255'],
                'distrito' => ['nullable', 'string', 'max:80'],
                'provincia' => ['nullable', 'string', 'max:80'],
                'departamento' => ['nullable', 'string', 'max:80'],
                'telefono' => ['nullable', 'string', 'max:30'],
                'email' => ['nullable', 'email', 'max:150'],
                'contacto' => ['nullable', 'string', 'max:120'],
                'observaciones' => ['nullable', 'string', 'max:500'],
                'activo' => ['boolean'],
            ];
            // Condiciones de crédito: solo el administrador
            if ($request->user()->tieneRol('admin')) {
                $reglas += [
                    'dias_credito' => ['required', 'integer', 'min:0', 'max:365'],
                    'limite_credito' => ['required', 'numeric', 'min:0', 'max:9999999'],
                ];
            }
        }

        $datos = $request->validate($reglas, [
            'numero_documento.regex' => 'El RUC debe tener 11 dígitos y empezar con 10, 15, 17 o 20.',
            'numero_documento.digits' => 'El DNI debe tener 8 dígitos.',
            'numero_documento.unique' => 'Ese cliente ya está registrado: búscalo por su número.',
            'razon_social.required' => 'Escribe la razón social o el nombre.',
        ]);

        $datos['razon_social'] = mb_strtoupper(trim($datos['razon_social']));

        return $datos;
    }
}