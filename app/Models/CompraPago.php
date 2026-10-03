<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pago (total o parcial) de una compra al crédito a su proveedor. */
class CompraPago extends Model
{
    protected $table = 'compra_pagos';

    /** Medios con los que se le paga a un proveedor. */
    public const MEDIOS = [
        'transferencia' => 'Transferencia bancaria',
        'deposito' => 'Depósito en cuenta',
        'efectivo' => 'Efectivo',
        'cheque' => 'Cheque',
        'yape' => 'Yape',
        'plin' => 'Plin',
    ];

    protected $fillable = [
        'compra_id', 'user_id', 'caja_id', 'fecha', 'medio', 'monto', 'referencia', 'observacion',
        'estado', 'anulado_por', 'anulado_at',
    ];

    protected $appends = ['medio_nombre'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'monto' => 'decimal:2',
            'anulado_at' => 'datetime',
        ];
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function getMedioNombreAttribute(): string
    {
        return self::MEDIOS[$this->medio] ?? $this->medio;
    }
}