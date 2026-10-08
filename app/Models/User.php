<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLES = ['admin', 'vendedor', 'almacen', 'contador'];

    // es_superadmin NO está aquí a propósito: no se puede asignar desde un formulario,
    // solo desde el código (forceFill) al instalar el sistema.
    protected $fillable = ['name', 'email', 'password', 'rol', 'activo', 'sucursal_id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'es_superadmin' => 'boolean',
        ];
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /** El admin puede todo; los demás solo lo de su rol. */
    public function tieneRol(string ...$roles): bool
    {
        return $this->rol === 'admin' || in_array($this->rol, $roles, true);
    }

    /** Usuario de soporte de Perú Labs: administrador con permisos técnicos adicionales. */
    public function esSuperadmin(): bool
    {
        return (bool) $this->es_superadmin;
    }
}