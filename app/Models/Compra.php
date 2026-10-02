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
        'op_gravadas', 'op_exoneradas', 'igv', 'total', 'observaciones', 'estado',
    ];

    protected $appends = ['documento'];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'fecha_vencimiento' => 'date',
            'op_gravadas' => 'decimal:2',
            'op_exoneradas' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
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

    /** Ej: "F001-111535" */
    public function getDocumentoAttribute(): string
    {
        return $this->serie.'-'.$this->numero;
    }
}