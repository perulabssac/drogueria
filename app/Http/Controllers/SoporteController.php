<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ayuda y soporte: datos de contacto de Perú Labs (desarrollador del sistema)
 * y datos técnicos que ayudan a resolver un problema más rápido. La ven todos los usuarios.
 */
class SoporteController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $usuario = $request->user();
        $empresa = Empresa::query()->first();

        return Inertia::render('Soporte/Index', [
            'soporte' => config('soporte'),
            'sistema' => [
                'version' => config('soporte.version'),
                'entorno' => $empresa?->entorno === 'produccion' ? 'Producción' : 'Pruebas (beta)',
                'empresa' => $empresa?->razon_social,
                'ruc' => $empresa?->ruc,
                'usuario' => $usuario->name,
                'correo' => $usuario->email,
                'rol' => $usuario->rol,
                'sucursal' => $usuario->sucursal?->nombre,
            ],
        ]);
    }
}