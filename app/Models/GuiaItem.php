<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuiaItem extends Model
{
    protected $fillable = ['guia_id', 'producto_id', 'codigo', 'descripcion', 'unidad', 'cantidad'];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:2'];
    }

    public function guia(): BelongsTo
    {
        return $this->belongsTo(Guia::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}