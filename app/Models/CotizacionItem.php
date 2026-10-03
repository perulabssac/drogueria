<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotizacionItem extends Model
{
    protected $table = 'cotizacion_items';

    protected $fillable = [
        'cotizacion_id', 'producto_id', 'descripcion', 'unidad', 'cantidad', 'por_fraccion',
        'precio_unitario', 'bonificacion', 'importe',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:2',
            'por_fraccion' => 'boolean',
            'precio_unitario' => 'decimal:4',
            'bonificacion' => 'boolean',
            'importe' => 'decimal:2',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}