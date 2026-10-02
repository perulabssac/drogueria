<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComprobantePago extends Model
{
    /** Medios de pago aceptados. Los que no son efectivo llevan n° de operación. */
    public const MEDIOS = [
        'efectivo' => 'Efectivo',
        'yape' => 'Yape',
        'plin' => 'Plin',
        'transferencia' => 'Transferencia bancaria',
        'deposito' => 'Depósito en cuenta',
        'tarjeta_debito' => 'Tarjeta de débito',
        'tarjeta_credito' => 'Tarjeta de crédito',
    ];

    /** venta = pago al contado; cobranza = abono de una venta al crédito. */
    public const TIPO_VENTA = 'venta';

    public const TIPO_COBRANZA = 'cobranza';

    protected $fillable = ['comprobante_id', 'caja_id', 'user_id', 'tipo', 'medio', 'monto', 'recibido', 'referencia', 'fecha'];

    protected $appends = ['medio_nombre', 'vuelto'];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'recibido' => 'decimal:2',
            'fecha' => 'datetime',
        ];
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    /** Caja en la que se cobró. */
    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    /** Quién registró el pago o la cobranza. */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getMedioNombreAttribute(): string
    {
        return self::MEDIOS[$this->medio] ?? $this->medio;
    }

    /** Vuelto entregado (solo efectivo). */
    public function getVueltoAttribute(): float
    {
        return $this->recibido ? round((float) $this->recibido - (float) $this->monto, 2) : 0;
    }
}