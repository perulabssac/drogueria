<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraItem extends Model
{
    protected $fillable = [
        'compra_id', 'producto_id', 'lote_id', 'numero_lote', 'fecha_vencimiento', 'cantidad',
        'bonificacion', 'precio_unitario', 'tipo_afectacion_igv', 'valor', 'igv', 'total',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date',
            'bonificacion' => 'boolean',
            'cantidad' => 'decimal:2',
            'precio_unitario' => 'decimal:3',
            'valor' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }
}