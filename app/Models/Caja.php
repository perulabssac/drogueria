<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caja extends Model
{
    protected $fillable = [
        'sucursal_id', 'user_id', 'estado', 'abierta_at', 'cerrada_at', 'monto_inicial',
        'efectivo_esperado', 'efectivo_contado', 'diferencia', 'conteo', 'resumen', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'abierta_at' => 'datetime',
            'cerrada_at' => 'datetime',
            'monto_inicial' => 'decimal:2',
            'efectivo_esperado' => 'decimal:2',
            'efectivo_contado' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'conteo' => 'array',
            'resumen' => 'array',
        ];
    }

    /** Fechas en hora de Perú al enviarlas a Vue. */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    /** Caja abierta del usuario (cada usuario tiene como máximo una). */
    public static function abiertaDe(User $usuario): ?self
    {
        return static::query()->where('user_id', $usuario->id)->where('estado', 'abierta')->latest('id')->first();
    }

    public function estaAbierta(): bool
    {
        return $this->estado === 'abierta';
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(ComprobantePago::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(CajaMovimiento::class)->orderBy('id');
    }
}