<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CajaMovimiento extends Model
{
    protected $table = 'caja_movimientos';

    protected $fillable = ['caja_id', 'user_id', 'tipo', 'medio', 'monto', 'concepto', 'origen_type', 'origen_id'];

    protected $appends = ['medio_nombre'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2'];
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Documento que originó el movimiento (ej. nota de crédito). */
    public function origen(): MorphTo
    {
        return $this->morphTo();
    }

    public function getMedioNombreAttribute(): string
    {
        return ComprobantePago::MEDIOS[$this->medio] ?? $this->medio;
    }
}