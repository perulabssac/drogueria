<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MovimientoInventario extends Model
{
    protected $table = 'movimientos_inventario';

    /** Nombre de cada motivo para mostrarlo en el kárdex. */
    public const MOTIVOS = [
        'inventario_inicial' => 'Inventario inicial',
        'compra' => 'Compra',
        'bonificacion' => 'Bonificación',
        'venta' => 'Venta',
        'devolucion_venta' => 'Devolución de cliente (nota de crédito)',
        'rechazo_sunat' => 'Reversión (comprobante rechazado por SUNAT)',
        'baja_sunat' => 'Reversión (comunicación de baja aceptada)',
        'anulacion_compra' => 'Anulación de compra',
        'ajuste' => 'Ajuste de inventario',
    ];

    protected $fillable = [
        'producto_id', 'lote_id', 'sucursal_id', 'user_id', 'tipo', 'motivo', 'cantidad',
        'saldo_lote', 'costo_unitario', 'referencia_type', 'referencia_id', 'observacion',
    ];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:2', 'saldo_lote' => 'decimal:2'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function referencia(): MorphTo
    {
        return $this->morphTo();
    }
}