<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    public const AFECTACIONES_IGV = [
        '10' => 'Gravado - Operación onerosa',
        '20' => 'Exonerado - Operación onerosa',
        '30' => 'Inafecto - Operación onerosa',
    ];

    public const CONDICIONES_VENTA = [
        'sin_receta' => 'Venta libre',
        'con_receta' => 'Con receta médica',
        'receta_retenida' => 'Receta médica retenida',
    ];

    /** Unidad que se imprime en la factura => código SUNAT (catálogo 03). */
    public const UNIDADES_VENTA = [
        'CJA' => ['nombre' => 'Caja', 'sunat' => 'BX'],
        'FCO' => ['nombre' => 'Frasco', 'sunat' => 'NIU'],
        'AMP' => ['nombre' => 'Ampolla', 'sunat' => 'NIU'],
        'TBO' => ['nombre' => 'Tubo', 'sunat' => 'NIU'],
        'SOB' => ['nombre' => 'Sobre', 'sunat' => 'NIU'],
        'BLS' => ['nombre' => 'Blíster', 'sunat' => 'NIU'],
        'UND' => ['nombre' => 'Unidad', 'sunat' => 'NIU'],
    ];

    public const UNIDADES_FRACCION = ['TAB' => 'Tableta', 'CAP' => 'Cápsula', 'GRA' => 'Gragea', 'AMP' => 'Ampolla', 'SOB' => 'Sobre', 'UND' => 'Unidad'];

    protected $fillable = [
        'codigo', 'codigo_barras', 'nombre', 'principio_activo', 'concentracion',
        'forma_farmaceutica', 'presentacion', 'categoria', 'laboratorio_id', 'registro_sanitario',
        'condicion_venta', 'controlado', 'cadena_frio', 'tipo_afectacion_igv',
        'unidad_venta', 'unidad_sunat', 'precio_venta',
        'fraccionable', 'unidades_por_presentacion', 'unidad_fraccion', 'precio_fraccion',
        'costo', 'margen', 'stock_minimo', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'controlado' => 'boolean',
            'cadena_frio' => 'boolean',
            'fraccionable' => 'boolean',
            'activo' => 'boolean',
            'precio_venta' => 'decimal:3',
            'precio_fraccion' => 'decimal:3',
            'costo' => 'decimal:4',
            'margen' => 'decimal:2',
            'unidades_por_presentacion' => 'integer',
            'stock_minimo' => 'integer',
        ];
    }

    public function laboratorio(): BelongsTo
    {
        return $this->belongsTo(Laboratorio::class);
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }

    /** Lotes con stock y no vencidos de una sucursal, del que vence primero al último (FEFO). */
    public function lotesDisponibles(int $sucursalId): HasMany
    {
        return $this->lotes()
            ->where('sucursal_id', $sucursalId)
            ->where('cantidad', '>', 0)
            ->whereDate('fecha_vencimiento', '>=', now()->toDateString())
            ->orderBy('fecha_vencimiento');
    }

    /** Búsqueda por nombre, principio activo, código o código de barras. */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        if (! $texto) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($texto) {
            $q->where('nombre', 'like', "%{$texto}%")
                ->orWhere('principio_activo', 'like', "%{$texto}%")
                ->orWhere('codigo', $texto)
                ->orWhere('codigo_barras', $texto);
        });
    }

    /**
     * Cuántas unidades mínimas hay en una presentación. El stock de los lotes se guarda
     * siempre en unidades mínimas: si no es fraccionable, la unidad mínima es la presentación.
     */
    public function factor(): int
    {
        return $this->fraccionable ? max(1, $this->unidades_por_presentacion) : 1;
    }

    public static function unidadSunat(string $unidadVenta): string
    {
        return self::UNIDADES_VENTA[$unidadVenta]['sunat'] ?? 'NIU';
    }

    /** Ej: "PARACETAMOL 500 MG CJA X 100 TAB" */
    public function descripcionCompleta(): string
    {
        return trim(implode(' ', array_filter([
            $this->nombre, $this->concentracion, $this->presentacion,
        ])));
    }
}