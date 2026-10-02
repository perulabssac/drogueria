<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ajuste de inventario (entrada o salida de stock con un motivo justificado). */
class Ajuste extends Model
{
    public const TIPOS = ['salida' => 'Salida', 'entrada' => 'Entrada'];

    /** Motivos permitidos según el tipo de ajuste. */
    public const MOTIVOS = [
        'salida' => [
            'rotura' => 'Rotura o deterioro',
            'vencimiento' => 'Producto vencido',
            'faltante' => 'Faltante en conteo',
            'robo' => 'Robo o pérdida',
            'muestra_medica' => 'Muestra médica o donación',
            'consumo_interno' => 'Consumo interno',
            'otro_salida' => 'Otro motivo',
        ],
        'entrada' => [
            'sobrante' => 'Sobrante en conteo',
            'inventario_inicial' => 'Inventario inicial',
            'otro_entrada' => 'Otro motivo',
        ],
    ];

    protected $fillable = ['sucursal_id', 'user_id', 'tipo', 'motivo', 'observacion', 'valor'];

    protected $appends = ['numero', 'motivo_nombre'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2'];
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AjusteItem::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /** Ej: "AJ-000012" */
    public function getNumeroAttribute(): string
    {
        return 'AJ-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function getMotivoNombreAttribute(): string
    {
        return self::MOTIVOS[$this->tipo][$this->motivo] ?? $this->motivo;
    }
}