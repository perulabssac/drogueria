<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Por ahora solo lo que necesita la pantalla de ventas: buscar y registrar rápido.
 * El módulo completo de clientes llega en el Paso 9B.
 */
class ClienteController extends Controller
{
    public function buscar(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        return response()->json(
            Cliente::query()
                ->when($request->filled('tipo'), fn ($b) => $b->where('tipo_documento', $request->string('tipo')->toString()))
                ->where(fn ($w) => $w->where('numero_documento', 'like', "{$q}%")->orWhere('razon_social', 'like', "%{$q}%"))
                ->orderBy('razon_social')
                ->limit(15)
                ->get(['id', 'tipo_documento', 'numero_documento', 'razon_social', 'direccion', 'dias_credito'])
        );
    }

    public function guardarRapido(Request $request): JsonResponse
    {
        $tipo = $request->input('tipo_documento');

        $datos = $request->validate([
            'tipo_documento' => ['required', Rule::in(['1', '4', '6', '7'])],
            'numero_documento' => [
                'required', 'string',
                $tipo === '6' ? 'regex:/^(10|15|17|20)\d{9}$/' : ($tipo === '1' ? 'digits:8' : 'max:15'),
                Rule::unique('clientes')->where('tipo_documento', $tipo),
            ],
            'razon_social' => ['required', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
        ], [
            'numero_documento.regex' => 'El RUC debe tener 11 dígitos y empezar con 10, 15, 17 o 20.',
            'numero_documento.digits' => 'El DNI debe tener 8 dígitos.',
            'numero_documento.unique' => 'Ese cliente ya está registrado: búscalo por su número.',
        ]);

        $datos['razon_social'] = mb_strtoupper(trim($datos['razon_social']));
        $cliente = Cliente::create($datos);

        return response()->json($cliente->only(['id', 'tipo_documento', 'numero_documento', 'razon_social', 'direccion', 'dias_credito']), 201);
    }
}