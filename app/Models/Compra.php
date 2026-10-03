<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Compra extends Model
{
    public const TIPOS_DOCUMENTO = [
        '01' => 'Factura',
        '03' => 'Boleta',
    ];

    protected $fillable = [
        'proveedor_id', 'sucursal_id', 'user_id', 'tipo_documento', 'serie', 'numero',
        'fecha_emision', 'forma_pago', 'fecha_vencimiento', 'moneda',
        'op_gravadas', 'op_exoneradas', 'igv', 'total', 'saldo', 'observaciones', 'estado',
    ];

    protected $appends = ['documento', 'estado_pago'];

    /** Situación del pago al proveedor (se calcula). */
    public const ESTADOS_PAGO = [
        'contado' => 'Contado',
        'pendiente' => 'Por pagar',
        'vencida' => 'Vencida',
        'pagada' => 'Pagada',
        'anulada' => 'Anulada',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'fecha_vencimiento' => 'date',
            'op_gravadas' => 'decimal:2',
            'op_exoneradas' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
            'saldo' => 'decimal:2',
        ];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CompraItem::class);
    }

    /** Pagos hechos al proveedor (solo compras al crédito). */
    public function pagos(): HasMany
    {
        return $this->hasMany(CompraPago::class)->orderBy('fecha')->orderBy('id');
    }

    public function esCredito(): bool
    {
        return $this->forma_pago === 'credito';
    }

    /** Días de atraso del pago (0 si aún no vence o ya se pagó). */
    public function diasVencida(): int
    {
        if ($this->estado === 'anulada' || (float) $this->saldo <= 0 || ! $this->fecha_vencimiento) {
            return 0;
        }

        return max(0, (int) $this->fecha_vencimiento->diffInDays(today(), false));
    }

    public function getEstadoPagoAttribute(): string
    {
        return match (true) {
            $this->estado === 'anulada' => 'anulada',
            ! $this->esCredito() => 'contado',
            (float) $this->saldo <= 0 => 'pagada',
            $this->diasVencida() > 0 => 'vencida',
            default => 'pendiente',
        };
    }

    /** Ej: "F001-111535" */
    public function getDocumentoAttribute(): string
    {
        return $this->serie.'-'.$this->numero;
    }
}