<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Serie extends Model
{
    protected $table = 'series';

    protected $fillable = ['sucursal_id', 'tipo_comprobante', 'serie', 'correlativo', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'correlativo' => 'integer'];
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * Reserva el siguiente correlativo bloqueando la fila.
     * El bloqueo evita que dos cajeros obtengan el mismo número al mismo tiempo.
     * Debe usarse dentro de una transacción.
     */
    public static function siguienteCorrelativo(int $serieId): array
    {
        $serie = static::query()->lockForUpdate()->findOrFail($serieId);
        $serie->increment('correlativo');

        return [$serie->serie, $serie->correlativo];
    }
}