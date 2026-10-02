<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Usuarios del sistema (solo administrador).
 * Los usuarios no se eliminan (tienen ventas y cajas a su nombre): se desactivan.
 */
class UsuarioController extends Controller
{
    public const ROLES = [
        'admin' => 'Administrador',
        'vendedor' => 'Vendedor / cajero',
        'almacen' => 'Almacén',
        'contador' => 'Contador (solo lectura)',
    ];

    public function index(Request $request): Response
    {
        $buscar = trim($request->string('buscar')->toString());

        $usuarios = User::query()
            ->with('sucursal:id,nombre')
            // ¿Tiene una caja abierta? (para avisar antes de desactivarlo)
            ->addSelect(['caja_abierta_id' => Caja::query()->select('id')
                ->whereColumn('user_id', 'users.id')->where('estado', 'abierta')->limit(1)])
            ->when($buscar, fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$buscar}%")
                ->orWhere('email', 'like', "%{$buscar}%")))
            ->when($request->filled('rol'), fn ($q) => $q->where('rol', $request->string('rol')->toString()))
            ->orderByDesc('activo')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Usuarios/Index', [
            'usuarios' => $usuarios,
            'filtros' => $request->only('buscar', 'rol'),
            'roles' => self::ROLES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Usuarios/Form', $this->datosFormulario(null));
    }

    public function edit(User $usuario): Response
    {
        return Inertia::render('Usuarios/Form', $this->datosFormulario($usuario));
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request, null);
        $usuario = User::create($datos);

        return redirect('/usuarios')->with('success', "Usuario {$usuario->name} creado. Ya puede ingresar con su correo y contraseña.");
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $datos = $this->validar($request, $usuario);

        // La contraseña solo se cambia si se escribió una nueva
        if (empty($datos['password'])) {
            unset($datos['password']);
        }

        $this->protegerAdministradores($request->user(), $usuario, $datos);
        $usuario->update($datos);

        return redirect('/usuarios')->with('success', "Usuario {$usuario->name} actualizado.");
    }

    /** Activar o desactivar desde la lista. Un usuario inactivo no puede ingresar. */
    public function cambiarEstado(Request $request, User $usuario): RedirectResponse
    {
        $datos = ['activo' => ! $usuario->activo];
        $this->protegerAdministradores($request->user(), $usuario, $datos);
        $usuario->update($datos);

        return back()->with('success', $usuario->activo
            ? "{$usuario->name} fue activado."
            : "{$usuario->name} fue desactivado: ya no podrá ingresar al sistema.");
    }

    private function datosFormulario(?User $usuario): array
    {
        return [
            'usuario' => $usuario?->only(['id', 'name', 'email', 'rol', 'sucursal_id', 'activo']),
            'roles' => self::ROLES,
            'sucursales' => Sucursal::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }

    private function validar(Request $request, ?User $usuario): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users')->ignore($usuario)],
            'rol' => ['required', Rule::in(array_keys(self::ROLES))],
            'sucursal_id' => ['required', 'exists:sucursales,id'],
            'activo' => ['boolean'],
            // Al crear es obligatoria; al editar, solo si se quiere cambiar
            'password' => [$usuario ? 'nullable' : 'required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'name.required' => 'Escribe el nombre del usuario.',
            'email.required' => 'El correo es el usuario con el que ingresará.',
            'email.unique' => 'Ya existe un usuario con ese correo.',
            'sucursal_id.required' => 'Elige la sucursal donde trabaja.',
            'password.required' => 'Escribe una contraseña inicial.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ], [
            'password' => 'contraseña',
        ]);
    }

    /**
     * Evita quedarse sin administradores:
     * - Nadie puede quitarse a sí mismo el rol de admin ni desactivarse.
     * - Siempre debe quedar al menos un administrador activo.
     */
    private function protegerAdministradores(User $actual, User $usuario, array $datos): void
    {
        $dejaDeSerAdmin = $usuario->rol === 'admin'
            && ((isset($datos['rol']) && $datos['rol'] !== 'admin') || (isset($datos['activo']) && ! $datos['activo']));

        if (! $dejaDeSerAdmin) {
            return;
        }

        if ($actual->id === $usuario->id) {
            throw ValidationException::withMessages([
                'rol' => 'No puedes quitarte el rol de administrador ni desactivarte a ti mismo. Pídeselo a otro administrador.',
            ]);
        }

        $otrosAdmins = User::query()->where('rol', 'admin')->where('activo', true)->whereKeyNot($usuario->id)->count();
        if ($otrosAdmins === 0) {
            throw ValidationException::withMessages(['rol' => 'Debe quedar al menos un administrador activo.']);
        }
    }
}