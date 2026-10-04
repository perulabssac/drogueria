<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una carga de productos y stock desde Excel. */
class Importacion extends Model
{
    protected $table = 'importaciones';

    protected $fillable = ['sucursal_id', 'user_id', 'archivo', 'productos_nuevos', 'productos_actualizados', 'lotes', 'valor'];

    protected $appends = ['numero'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2'];
    }

    /** Fechas en hora de Perú al enviarlas a Vue. */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** IMP-000001 */
    public function getNumeroAttribute(): string
    {
        return 'IMP-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}