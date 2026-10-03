<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cotización (proforma) para un cliente. No mueve stock ni se envía a SUNAT. */
class Cotizacion extends Model
{
    protected $table = 'cotizaciones';

    public const ESTADOS = [
        'pendiente' => 'Pendiente',
        'vendida' => 'Vendida',
        'vencida' => 'Vencida',
        'anulada' => 'Anulada',
    ];

    protected $fillable = [
        'sucursal_id', 'user_id', 'vendedor_id', 'cliente_id', 'fecha', 'validez_dias', 'fecha_vencimiento',
        'forma_pago', 'condiciones', 'observaciones', 'op_gravadas', 'op_exoneradas', 'op_gratuitas', 'igv', 'total',
        'estado', 'comprobante_id',
    ];

    protected $appends = ['numero', 'estado_actual'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'fecha_vencimiento' => 'date:Y-m-d',
            'op_gravadas' => 'decimal:2',
            'op_exoneradas' => 'decimal:2',
            'op_gratuitas' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CotizacionItem::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    /** Ej: "COT-000012" */
    public function getNumeroAttribute(): string
    {
        return 'COT-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /** Estado real: una pendiente cuya validez ya pasó se muestra como vencida. */
    public function getEstadoActualAttribute(): string
    {
        return $this->estaVencida() ? 'vencida' : $this->estado;
    }

    public function estaVencida(): bool
    {
        return $this->estado === 'pendiente' && $this->fecha_vencimiento?->lt(today());
    }

    /** Solo una pendiente y vigente puede editarse o convertirse en venta. */
    public function vigente(): bool
    {
        return $this->estado === 'pendiente' && ! $this->estaVencida();
    }
}