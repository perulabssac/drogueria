<?php

namespace App\Models;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comprobante extends Model
{
    public const TIPOS = [
        '01' => 'Factura',
        '03' => 'Boleta de venta',
        '07' => 'Nota de crédito',
        '08' => 'Nota de débito',
        'NV' => 'Nota de venta',
    ];

    /** Documento de uso interno: no es comprobante de pago y no se envía a SUNAT. */
    public const NOTA_VENTA = 'NV';

    /** Estados en que el comprobante no tiene validez: no cuenta en ventas, deudas ni reportes. */
    public const ESTADOS_SIN_VALIDEZ = ['rechazado', 'anulado'];

    /** Plazo de SUNAT para comunicar la baja (días calendario desde la emisión). */
    public const DIAS_PARA_BAJA = 7;

    // Catálogo 09 - motivos de nota de crédito más usados
    public const MOTIVOS_NC = [
        '01' => 'Anulación de la operación',
        '02' => 'Anulación por error en el RUC',
        '03' => 'Corrección por error en la descripción',
        '06' => 'Devolución total',
        '07' => 'Devolución por ítem',
    ];

    // saldo: lo que el cliente aún debe de una venta al crédito (0 en ventas al contado)
    protected $fillable = [
        'sucursal_id', 'user_id', 'vendedor_id', 'cliente_id', 'tipo_comprobante', 'serie', 'correlativo',
        'fecha_emision', 'moneda', 'forma_pago', 'fecha_vencimiento', 'guia_remision', 'orden_compra',
        'observaciones', 'op_gravadas', 'op_exoneradas', 'op_inafectas', 'op_gratuitas',
        'igv', 'total', 'saldo', 'comprobante_referencia_id', 'motivo_codigo', 'motivo_descripcion',
        'estado', 'sunat_codigo', 'sunat_descripcion', 'sunat_observaciones', 'hash', 'resumen', 'ticket',
        'xml_path', 'cdr_path', 'intentos_envio', 'enviado_at',
        'baja_estado', 'baja_motivo', 'baja_documento', 'baja_ticket', 'baja_codigo', 'baja_descripcion',
        'baja_cdr_path', 'baja_user_id', 'baja_at',
    ];

    // Valores por defecto al crear (la base de datos también los tiene, pero así el
    // modelo recién creado ya los trae sin volver a leerlo)
    protected $attributes = [
        'moneda' => 'PEN',
    ];

    // Campos calculados que se envían a Vue junto con el comprobante
    protected $appends = ['numero', 'tipo_nombre'];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'datetime',
            'fecha_vencimiento' => 'date',
            'enviado_at' => 'datetime',
            'baja_at' => 'datetime',
            'sunat_observaciones' => 'array',
            'op_gravadas' => 'decimal:2',
            'op_exoneradas' => 'decimal:2',
            'op_inafectas' => 'decimal:2',
            'op_gratuitas' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
            'saldo' => 'decimal:2',
            'correlativo' => 'integer',
        ];
    }

    /**
     * Las fechas se envían a Vue en hora de Perú (por defecto Laravel las envía en UTC
     * y una venta de las 8 p. m. aparecería con fecha del día siguiente).
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComprobanteItem::class);
    }

    /** Guías de remisión electrónicas emitidas desde este comprobante. */
    public function guias(): HasMany
    {
        return $this->hasMany(Guia::class)->orderBy('id');
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(ComprobanteCuota::class)->orderBy('numero');
    }

    /**
     * Dinero recibido por esta venta: pagos al contado (tipo "venta") y
     * cobranzas de la venta al crédito (tipo "cobranza").
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(ComprobantePago::class)->orderBy('id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    /** Para notas de crédito: el comprobante que modifican. */
    public function referencia(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_referencia_id');
    }

    public function notas(): HasMany
    {
        return $this->hasMany(Comprobante::class, 'comprobante_referencia_id');
    }

    /** Usuario que solicitó la comunicación de baja. */
    public function bajaUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'baja_user_id');
    }

    public function getNumeroAttribute(): string
    {
        return $this->serie.'-'.$this->correlativo;
    }

    public function getTipoNombreAttribute(): string
    {
        return self::TIPOS[$this->tipo_comprobante] ?? $this->tipo_comprobante;
    }

    public function esNota(): bool
    {
        return in_array($this->tipo_comprobante, ['07', '08'], true);
    }

    /** La nota de venta es interna: nunca se envía a SUNAT. */
    public function esInterno(): bool
    {
        return $this->tipo_comprobante === self::NOTA_VENTA;
    }

    public function esCredito(): bool
    {
        return $this->forma_pago === 'credito';
    }

    /** Nombre de archivo que exige SUNAT: RUC-TIPO-SERIE-CORRELATIVO */
    public function nombreArchivo(string $ruc): string
    {
        return "{$ruc}-{$this->tipo_comprobante}-{$this->serie}-{$this->correlativo}";
    }

    /** ¿Tiene validez? (no fue rechazado por SUNAT ni dado de baja) */
    public function tieneValidez(): bool
    {
        return ! in_array($this->estado, self::ESTADOS_SIN_VALIDEZ, true);
    }

    /** Solo comprobantes con validez: Comprobante::validos()->... */
    public function scopeValidos(Builder $query): Builder
    {
        return $query->whereNotIn('estado', self::ESTADOS_SIN_VALIDEZ);
    }

    /** Fecha límite para dar de baja (7 días calendario desde la emisión). */
    public function fechaLimiteBaja(): CarbonInterface
    {
        return $this->fecha_emision->copy()->startOfDay()->addDays(self::DIAS_PARA_BAJA);
    }

    /** Por qué no se puede dar de baja (null = sí se puede). */
    public function impedimentoBaja(): ?string
    {
        return match (true) {
            ! in_array($this->tipo_comprobante, ['01', '03'], true) => 'Solo se dan de baja facturas y boletas.',
            in_array($this->baja_estado, ['enviada', 'aceptada'], true) => 'Este comprobante ya tiene una comunicación de baja.',
            ! in_array($this->estado, ['aceptado', 'observado'], true) => 'Solo se da de baja un comprobante aceptado por SUNAT.',
            today()->gt($this->fechaLimiteBaja()) => 'Pasaron más de '.self::DIAS_PARA_BAJA.' días desde la emisión: anúlalo con una nota de crédito.',
            $this->notas()->validos()->exists() => 'Tiene notas de crédito o débito: ya no se puede dar de baja.',
            $this->pagos()->where('tipo', ComprobantePago::TIPO_COBRANZA)->exists() => 'Ya tiene cobranzas registradas: anúlalo con una nota de crédito.',
            default => null,
        };
    }

    public function puedeReenviarse(): bool
    {
        return ! $this->esInterno() && in_array($this->estado, ['pendiente', 'error'], true);
    }

    /**
     * Las boletas y las notas que las modifican se informan a SUNAT con resumen diario;
     * las facturas y sus notas, de forma individual.
     */
    public function vaPorResumen(): bool
    {
        if ($this->tipo_comprobante === '03') {
            return true;
        }

        return $this->esNota() && str_starts_with($this->serie, 'B');
    }
}