<?php

namespace App\Http\Controllers;

use App\Models\Proveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProveedorController extends Controller
{
    public function index(Request $request): Response
    {
        $buscar = $request->string('buscar')->toString();

        return Inertia::render('Proveedores/Index', [
            'proveedores' => Proveedor::query()
                ->withCount('compras')
                ->when($buscar, fn ($q) => $q->where(fn ($w) => $w
                    ->where('razon_social', 'like', "%{$buscar}%")
                    ->orWhere('ruc', 'like', "{$buscar}%")))
                ->orderBy('razon_social')
                ->paginate(15)
                ->withQueryString(),
            'filtros' => ['buscar' => $buscar],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Proveedores/Form', ['proveedor' => null]);
    }

    public function edit(Proveedor $proveedor): Response
    {
        return Inertia::render('Proveedores/Form', ['proveedor' => $proveedor]);
    }

    public function store(Request $request): RedirectResponse
    {
        $proveedor = Proveedor::create($this->validar($request));

        return redirect('/proveedores')->with('success', "Proveedor {$proveedor->razon_social} registrado.");
    }

    public function update(Request $request, Proveedor $proveedor): RedirectResponse
    {
        $proveedor->update($this->validar($request, $proveedor));

        return redirect('/proveedores')->with('success', "Proveedor {$proveedor->razon_social} actualizado.");
    }

    /** Búsqueda rápida (JSON) para el formulario de compras. */
    public function buscar(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        return response()->json(
            Proveedor::query()
                ->where('activo', true)
                ->where(fn ($w) => $w->where('ruc', 'like', "{$q}%")->orWhere('razon_social', 'like', "%{$q}%"))
                ->orderBy('razon_social')
                ->limit(15)
                ->get(['id', 'ruc', 'razon_social'])
        );
    }

    private function validar(Request $request, ?Proveedor $proveedor = null): array
    {
        $datos = $request->validate([
            'ruc' => ['required', 'regex:/^(10|15|17|20)\d{9}$/', Rule::unique('proveedores')->ignore($proveedor)],
            'razon_social' => ['required', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'contacto' => ['nullable', 'string', 'max:100'],
            'activo' => ['boolean'],
        ], [
            'ruc.regex' => 'El RUC debe tener 11 dígitos y empezar con 10, 15, 17 o 20.',
        ]);

        $datos['razon_social'] = mb_strtoupper(trim($datos['razon_social']));

        return $datos;
    }
}