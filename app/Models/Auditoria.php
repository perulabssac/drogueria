<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Un registro de auditoría (solo se crea; nunca se modifica). */
class Auditoria extends Model
{
    public const UPDATED_AT = null;

    public const EVENTOS = [
        'creado' => 'Creó',
        'actualizado' => 'Modificó',
        'eliminado' => 'Eliminó',
        'login' => 'Inició sesión',
        'logout' => 'Cerró sesión',
        'login_fallido' => 'Intento de acceso fallido',
    ];

    protected $fillable = ['user_id', 'sucursal_id', 'evento', 'modulo', 'auditable_type', 'auditable_id', 'descripcion', 'cambios', 'ip', 'navegador'];

    protected $appends = ['evento_nombre'];

    protected function casts(): array
    {
        return ['cambios' => 'array'];
    }

    /** Fechas en hora de Perú al enviarlas a Vue. */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getEventoNombreAttribute(): string
    {
        return self::EVENTOS[$this->evento] ?? $this->evento;
    }
}