<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AjusteItem extends Model
{
    protected $fillable = [
        'ajuste_id', 'producto_id', 'lote_id', 'numero_lote', 'fecha_vencimiento', 'cantidad', 'costo_unitario', 'valor',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date:Y-m-d',
            'cantidad' => 'decimal:2',
            'costo_unitario' => 'decimal:4',
            'valor' => 'decimal:2',
        ];
    }

    public function ajuste(): BelongsTo
    {
        return $this->belongsTo(Ajuste::class);
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