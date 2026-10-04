<?php

namespace App\Http\Controllers;

use App\Models\Importacion;
use App\Services\ImportacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Importación de productos y stock desde Excel: plantilla, vista previa y confirmación.
 * El archivo subido se guarda temporalmente hasta que se confirma o se cancela.
 */
class ImportacionController extends Controller
{
    private const SESION = 'importacion';

    public function index(Request $request, ImportacionService $servicio): Response
    {
        $pendiente = $this->pendiente($request);
        $actualizar = $request->boolean('actualizar');
        $vista = null;

        if ($pendiente) {
            try {
                $analisis = $servicio->analizar(Storage::disk('local')->path($pendiente['ruta']), $request->user()->sucursal_id, $actualizar);
                $vista = [
                    'archivo' => $pendiente['nombre'],
                    'resumen' => $analisis['resumen'],
                    // Los datos internos (_producto, _lote) no se envían a la pantalla
                    'filas' => array_map(fn ($f) => array_diff_key($f, ['_producto' => 1, '_lote' => 1]), $analisis['filas']),
                ];
            } catch (ValidationException $e) {
                $this->descartar($request);
                session()->now('error', collect($e->errors())->flatten()->first());
            }
        }

        return Inertia::render('Importacion/Index', [
            'vista' => $vista,
            'actualizar' => $actualizar,
            'historial' => Importacion::query()
                ->with('usuario:id,name')
                ->where('sucursal_id', $request->user()->sucursal_id)
                ->latest('id')
                ->limit(10)
                ->get(),
            'maxFilas' => ImportacionService::MAX_FILAS,
        ]);
    }

    public function plantilla(ImportacionService $servicio): BinaryFileResponse
    {
        return response()->download($servicio->guardarPlantilla(), 'plantilla-productos.xlsx')->deleteFileAfterSend();
    }

    /** Sube el Excel y muestra la vista previa (todavía no guarda nada). */
    public function subir(Request $request): RedirectResponse
    {
        $request->validate([
            'archivo' => ['required', 'file', 'extensions:xlsx', 'max:5120'],
        ], [
            'archivo.required' => 'Elige el archivo Excel.',
            'archivo.extensions' => 'El archivo debe ser .xlsx (Excel). Usa la plantilla.',
            'archivo.max' => 'El archivo no debe pasar de 5 MB.',
        ]);

        $this->descartar($request); // si había otro pendiente

        $archivo = $request->file('archivo');
        $ruta = $archivo->storeAs('importaciones', Str::uuid().'.xlsx', 'local');

        $request->session()->put(self::SESION, [
            'ruta' => $ruta,
            'nombre' => $archivo->getClientOriginalName(),
            'user_id' => $request->user()->id,
        ]);

        return redirect('/importar');
    }

    public function confirmar(Request $request, ImportacionService $servicio): RedirectResponse
    {
        $pendiente = $this->pendiente($request);
        if (! $pendiente) {
            return redirect('/importar')->with('error', 'No hay un archivo pendiente. Vuelve a subirlo.');
        }

        $actualizar = $request->boolean('actualizar');
        $importacion = $servicio->importar(
            Storage::disk('local')->path($pendiente['ruta']),
            $pendiente['nombre'],
            $request->user(),
            $actualizar,
        );
        $this->descartar($request);

        return redirect('/importar')->with('success',
            "Importación {$importacion->numero} completada: {$importacion->productos_nuevos} producto(s) nuevo(s)"
            .($actualizar ? ", {$importacion->productos_actualizados} actualizado(s)" : '')
            .", {$importacion->lotes} lote(s) ingresado(s) al stock.");
    }

    public function cancelar(Request $request): RedirectResponse
    {
        $this->descartar($request);

        return redirect('/importar');
    }

    /** Archivo subido por este usuario que aún no se importa. */
    private function pendiente(Request $request): ?array
    {
        $pendiente = $request->session()->get(self::SESION);

        return $pendiente
            && (int) $pendiente['user_id'] === (int) $request->user()->id
            && Storage::disk('local')->exists($pendiente['ruta'])
            ? $pendiente
            : null;
    }

    private function descartar(Request $request): void
    {
        if ($pendiente = $request->session()->pull(self::SESION)) {
            Storage::disk('local')->delete($pendiente['ruta']);
        }
    }
}