<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cada usuario puede cambiar su propia contraseña (debe escribir la actual).
 */
class PerfilController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Perfil/Password');
    }

    public function update(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'password_actual' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:password_actual', Password::min(8)->letters()->numbers()],
        ], [
            'password_actual.required' => 'Escribe tu contraseña actual.',
            'password_actual.current_password' => 'La contraseña actual no es correcta.',
            'password.confirmed' => 'Las contraseñas nuevas no coinciden.',
            'password.different' => 'La nueva contraseña debe ser distinta a la actual.',
        ], [
            'password' => 'contraseña nueva',
        ]);

        $request->user()->update(['password' => $datos['password']]);

        return redirect('/')->with('success', 'Contraseña actualizada. Úsala la próxima vez que ingreses.');
    }
}