<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComprobanteItem extends Model
{
    protected $fillable = [
        'comprobante_id', 'item_referencia_id', 'producto_id', 'lote_id', 'numero_lote', 'fecha_vencimiento',
        'codigo', 'descripcion', 'unidad', 'unidad_sunat', 'es_fraccion', 'cantidad',
        'valor_unitario', 'precio_unitario', 'tipo_afectacion_igv', 'bonificacion',
        'valor_venta', 'igv', 'total',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date',
            'es_fraccion' => 'boolean',
            'bonificacion' => 'boolean',
            'cantidad' => 'decimal:2',
            'valor_unitario' => 'decimal:6',
            'precio_unitario' => 'decimal:3',
            'valor_venta' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /** En una nota de crédito: la línea de la factura/boleta que se devuelve. */
    public function itemReferencia(): BelongsTo
    {
        return $this->belongsTo(ComprobanteItem::class, 'item_referencia_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }
}