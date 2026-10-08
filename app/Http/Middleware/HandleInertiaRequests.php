<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Datos que reciben TODAS las páginas Vue.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'rol' => $user->rol,
                    'es_superadmin' => (bool) $user->es_superadmin,
                    'sucursal' => $user->sucursal?->nombre,
                ] : null,
            ],
            'empresa' => fn () => $user ? Empresa::query()->first(['razon_social', 'ruc', 'entorno']) : null,
                        // Nombre y logo de la empresa: también en la pantalla de inicio de sesión
            'marca' => function () {
                $empresa = Empresa::query()->first();

                return $empresa ? [
                    'nombre' => $empresa->nombre_comercial ?: $empresa->razon_social,
                    'logo' => $empresa->logoUrl(),
                ] : null;
            },
            // Mensajes que el controlador envía con ->with('success', '...')
            'notificacion' => [
                'exito' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}