<?php

namespace App\Support;

use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Registra eventos en la auditoría. Nunca interrumpe la operación:
 * si por algo falla el registro, la venta, compra, etc. sigue su curso.
 */
class Auditor
{
    /**
     * $usuario: quién lo hizo. Por defecto el usuario logueado (en el inicio de sesión
     * aún no está logueado, por eso se pasa explícitamente).
     */
    public static function registrar(string $evento, string $modulo, string $descripcion, ?Model $registro = null, ?array $cambios = null, ?User $usuario = null): void
    {
        try {
            $usuario ??= auth()->user();
            $request = app()->runningInConsole() ? null : request();

            Auditoria::create([
                'user_id' => $usuario?->id,
                'sucursal_id' => $usuario?->sucursal_id,
                'evento' => $evento,
                'modulo' => $modulo,
                'auditable_type' => $registro?->getMorphClass(),
                'auditable_id' => $registro?->getKey(),
                'descripcion' => mb_substr($descripcion, 0, 255),
                'cambios' => $cambios ?: null,
                'ip' => $request?->ip(),
                'navegador' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : 'Sistema (tarea automática)',
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}