<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Serie;
use App\Models\Sucursal;
use App\Services\Sunat\CertificadoService;
use App\Services\Sunat\PruebaSunatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ConfiguracionController extends Controller
{
    public const TIPOS_SERIE = [
        '01' => 'Factura',
        '03' => 'Boleta',
        '07' => 'Nota de crédito',
        '08' => 'Nota de débito',
        'NV' => 'Nota de venta (interna)',
    ];

    public function index(CertificadoService $certificados): Response
    {
        $empresa = Empresa::actual();

        return Inertia::render('Configuracion/Index', [
            'empresa' => [
                ...$empresa->toArray(),
                'tiene_clave_sol' => (bool) $empresa->sol_clave,
            ],
            'logoUrl' => $empresa->logoUrl(),
            'certificado' => $certificados->informacion($empresa),
            'soapActivo' => extension_loaded('soap'),
            'sucursales' => Sucursal::orderBy('id')->get(['id', 'nombre', 'codigo_establecimiento']),
            'series' => Serie::with('sucursal:id,nombre')->orderBy('tipo_comprobante')->orderBy('serie')->get(),
            'tiposSerie' => self::TIPOS_SERIE,
            // Resultado del último envío de prueba (se muestra una sola vez)
            'pruebaSunat' => session('pruebaSunat'),
        ]);
    }

    public function actualizarEmpresa(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'ruc' => ['required', 'digits:11'],
            'razon_social' => ['required', 'string', 'max:255'],
            'nombre_comercial' => ['nullable', 'string', 'max:255'],
            'direccion' => ['required', 'string', 'max:255'],
            'ubigeo' => ['required', 'digits:6'],
            'departamento' => ['required', 'string', 'max:100'],
            'provincia' => ['required', 'string', 'max:100'],
            'distrito' => ['required', 'string', 'max:100'],
            'urbanizacion' => ['nullable', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'cuentas_bancarias' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (['razon_social', 'nombre_comercial', 'direccion', 'departamento', 'provincia', 'distrito', 'urbanizacion'] as $campo) {
            if (! empty($datos[$campo])) {
                $datos[$campo] = mb_strtoupper(trim($datos[$campo]));
            }
        }

        Empresa::actual()->update($datos);

        return back()->with('success', 'Datos de la empresa actualizados.');
    }

    /** Sube o reemplaza el logo de la empresa (se guarda en storage/app/private, fuera de GitHub). */
    public function subirLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:min_width=200'],
        ], [
            'logo.required' => 'Selecciona la imagen del logo.',
            'logo.image' => 'El archivo debe ser una imagen.',
            'logo.mimes' => 'El logo debe ser PNG o JPG.',
            'logo.max' => 'El logo no debe pesar más de 1 MB.',
            'logo.dimensions' => 'El logo debe tener al menos 200 px de ancho para que se vea nítido.',
        ]);

        $empresa = Empresa::actual();
        $anterior = $empresa->logo_path;
        $archivo = $request->file('logo');

        // Nombre con fecha: así el navegador no muestra un logo antiguo guardado en caché
        $ruta = $archivo->storeAs('empresa', 'logo-'.now()->format('YmdHis').'.'.$archivo->extension(), 'local');
        $empresa->update(['logo_path' => $ruta]);

        if ($anterior && $anterior !== $ruta) {
            Storage::disk('local')->delete($anterior);
        }

        return back()->with('success', 'Logo actualizado. Ya aparece en el sistema y en los comprobantes.');
    }

    public function eliminarLogo(): RedirectResponse
    {
        $empresa = Empresa::actual();

        if ($empresa->logo_path) {
            Storage::disk('local')->delete($empresa->logo_path);
            $empresa->update(['logo_path' => null]);
        }

        return back()->with('success', 'Logo eliminado.');
    }

    /** Muestra el logo. Es público porque también se usa en la pantalla de inicio de sesión. */
    public function logo(): BinaryFileResponse
    {
        $empresa = Empresa::query()->first();
        abort_unless($empresa?->logo_path && Storage::disk('local')->exists($empresa->logo_path), 404);

        return response()->file(Storage::disk('local')->path($empresa->logo_path), [
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    public function actualizarSunat(Request $request): RedirectResponse
    {
        $empresa = Empresa::actual();

        $datos = $request->validate([
            'entorno' => ['required', 'in:beta,produccion'],
            'sol_usuario' => [Rule::requiredIf($request->input('entorno') === 'produccion'), 'nullable', 'string', 'max:30'],
            'sol_clave' => ['nullable', 'string', 'max:100'],
        ], [
            'sol_usuario.required' => 'Para producción necesitas un usuario SOL secundario.',
        ]);

        if ($datos['entorno'] === 'produccion') {
            if (! $datos['sol_clave'] && ! $empresa->sol_clave) {
                return back()->withErrors(['sol_clave' => 'Ingresa la clave SOL.']);
            }
            if (! $empresa->certificado_path || str_contains($empresa->certificado_path, 'demo-')) {
                return back()->withErrors(['entorno' => 'Para producción debes subir tu certificado digital real (.pfx).']);
            }
        }

        // Si la clave viene vacía, se conserva la que ya estaba guardada
        if (empty($datos['sol_clave'])) {
            unset($datos['sol_clave']);
        }

        $empresa->update($datos);

        return back()->with('success', $datos['entorno'] === 'produccion'
            ? 'Conexión configurada en PRODUCCIÓN: los comprobantes tendrán validez tributaria.'
            : 'Conexión configurada en el entorno de pruebas (beta).');
    }

    public function subirCertificado(Request $request, CertificadoService $certificados): RedirectResponse
    {
        $request->validate([
            'archivo' => ['required', 'file', 'max:200'],
            'password' => ['required', 'string'],
        ], [
            'archivo.required' => 'Selecciona el archivo .pfx o .p12.',
        ]);

        $info = $certificados->importarPfx(Empresa::actual(), $request->file('archivo')->get(), $request->input('password'));

        return back()->with('success', "Certificado de {$info['titular']} cargado. Vence el {$info['vence']->format('d/m/Y')}.");
    }

    public function certificadoDemo(CertificadoService $certificados): RedirectResponse
    {
        $empresa = Empresa::actual();
        if ($empresa->esProduccion()) {
            return back()->with('error', 'El certificado de prueba solo se puede usar en el entorno beta.');
        }

        $certificados->generarDemo($empresa);

        return back()->with('success', 'Certificado de prueba generado. Ya puedes enviar una factura de prueba.');
    }

    public function probarSunat(PruebaSunatService $prueba): RedirectResponse
    {
        $empresa = Empresa::actual();
        if ($empresa->esProduccion()) {
            return back()->with('error', 'La factura de prueba solo se envía en el entorno beta, para no generar comprobantes reales.');
        }

        return back()->with('pruebaSunat', $prueba->enviarFacturaPrueba($empresa));
    }

    public function guardarSerie(Request $request): RedirectResponse
    {
        $id = $request->input('id');
        $datos = $request->validate([
            'id' => ['nullable', 'integer', 'exists:series,id'],
            'sucursal_id' => ['required', 'exists:sucursales,id'],
            'tipo_comprobante' => ['required', Rule::in(array_keys(self::TIPOS_SERIE))],
            'serie' => [
                'required', 'string', 'size:4', 'regex:/^[FBN][A-Z0-9]{3}$/i',
                Rule::unique('series')->where('tipo_comprobante', $request->input('tipo_comprobante'))->ignore($id),
            ],
            'correlativo' => ['required', 'integer', 'min:0'],
            'activo' => ['boolean'],
        ], [
            'serie.regex' => 'La serie tiene 4 caracteres y empieza con F (facturas y sus notas), B (boletas y sus notas) o N (notas de venta).',
            'serie.unique' => 'Esa serie ya existe para ese tipo de comprobante.',
        ]);

        $datos['serie'] = strtoupper($datos['serie']);
        $letra = $datos['serie'][0];
        if ($datos['tipo_comprobante'] === '01' && $letra !== 'F') {
            return back()->withErrors(['serie' => 'Las series de factura empiezan con F (ej. F001).']);
        }
        if ($datos['tipo_comprobante'] === '03' && $letra !== 'B') {
            return back()->withErrors(['serie' => 'Las series de boleta empiezan con B (ej. B001).']);
        }
        // Las series que empiezan con N son solo para notas de venta, y viceversa
        if (($datos['tipo_comprobante'] === 'NV') !== ($letra === 'N')) {
            return back()->withErrors(['serie' => 'Las series de nota de venta empiezan con N (ej. NV01), y solo ellas.']);
        }

        // Proteger contra retroceder el correlativo (generaría números duplicados)
        if ($id) {
            $actual = Serie::findOrFail($id);
            if ($datos['correlativo'] < $actual->correlativo) {
                return back()->withErrors(['correlativo' => "No puedes bajar el correlativo: ya se emitió hasta el {$actual->correlativo}."]);
            }
        }

        Serie::updateOrCreate(['id' => $id], $datos);

        return back()->with('success', "Serie {$datos['serie']} guardada.");
    }
}