<?php

namespace App\Models;

use DateTimeInterface;
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