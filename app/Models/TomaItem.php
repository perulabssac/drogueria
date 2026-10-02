<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TomaItem extends Model
{
    protected $fillable = [
        'toma_id', 'producto_id', 'lote_id', 'numero_lote', 'fecha_vencimiento', 'stock_sistema', 'contado', 'costo_unitario',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date:Y-m-d',
            'stock_sistema' => 'float',
            'contado' => 'float',
            'costo_unitario' => 'float',
        ];
    }

    public function toma(): BelongsTo
    {
        return $this->belongsTo(TomaInventario::class, 'toma_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /** Contado − sistema (en unidad mínima). Null si aún no se contó. */
    public function diferencia(): ?float
    {
        return $this->contado === null ? null : round($this->contado - $this->stock_sistema, 2);
    }
}