<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Toma de inventario física (conteo del almacén). */
class TomaInventario extends Model
{
    protected $table = 'tomas_inventario';

    public const ESTADOS = ['en_conteo' => 'En conteo', 'aprobada' => 'Aprobada', 'anulada' => 'Anulada'];

    protected $fillable = [
        'sucursal_id', 'user_id', 'alcance', 'alcance_valor', 'alcance_texto', 'estado', 'observacion',
        'aprobado_por', 'aprobada_at', 'ajuste_salida_id', 'ajuste_entrada_id',
    ];

    protected $appends = ['numero'];

    protected function casts(): array
    {
        return ['aprobada_at' => 'datetime'];
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TomaItem::class, 'toma_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function ajusteSalida(): BelongsTo
    {
        return $this->belongsTo(Ajuste::class, 'ajuste_salida_id');
    }

    public function ajusteEntrada(): BelongsTo
    {
        return $this->belongsTo(Ajuste::class, 'ajuste_entrada_id');
    }

    /** Ej: "TI-000003" */
    public function getNumeroAttribute(): string
    {
        return 'TI-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function enConteo(): bool
    {
        return $this->estado === 'en_conteo';
    }
}