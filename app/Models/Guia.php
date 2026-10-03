<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Guía de remisión electrónica - remitente (tipo 09). */
class Guia extends Model
{
    public const TIPO = '09';

    /** Catálogo 20 de SUNAT (los motivos que usa una droguería). */
    public const MOTIVOS = [
        '01' => 'Venta',
        '14' => 'Venta sujeta a confirmación del comprador',
        '04' => 'Traslado entre establecimientos de la misma empresa',
        '06' => 'Devolución',
        '13' => 'Otros',
    ];

    /** Catálogo 18 de SUNAT. */
    public const MODALIDADES = [
        '01' => 'Transporte público (empresa de transportes)',
        '02' => 'Transporte privado (vehículo propio)',
    ];

    protected $fillable = [
        'sucursal_id', 'user_id', 'comprobante_id', 'serie', 'correlativo', 'fecha_emision', 'fecha_traslado',
        'motivo', 'motivo_descripcion', 'modalidad',
        'destinatario_tipo_doc', 'destinatario_num_doc', 'destinatario_nombre',
        'partida_ubigeo', 'partida_direccion', 'llegada_ubigeo', 'llegada_direccion',
        'peso_total', 'unidad_peso',
        'transportista_ruc', 'transportista_nombre', 'transportista_mtc',
        'vehiculo_placa', 'conductor_tipo_doc', 'conductor_num_doc', 'conductor_nombres', 'conductor_apellidos', 'conductor_licencia',
        'observaciones', 'estado', 'ticket', 'sunat_codigo', 'sunat_descripcion', 'enlace_qr',
        'xml_path', 'cdr_path', 'intentos_envio', 'enviado_at',
    ];

    protected $appends = ['numero'];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'datetime',
            'fecha_traslado' => 'date:Y-m-d',
            'peso_total' => 'decimal:3',
            'enviado_at' => 'datetime',
        ];
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GuiaItem::class);
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /** Ej: "T001-25" */
    public function getNumeroAttribute(): string
    {
        return $this->serie.'-'.$this->correlativo;
    }

    /** Nombre de archivo que exige SUNAT: RUC-09-SERIE-NUMERO */
    public function nombreArchivo(string $ruc): string
    {
        return "{$ruc}-".self::TIPO."-{$this->serie}-{$this->correlativo}";
    }

    public function esTransportePrivado(): bool
    {
        return $this->modalidad === '02';
    }

    /** ¿Se puede (re)enviar o consultar en SUNAT? */
    public function pendienteDeSunat(): bool
    {
        return in_array($this->estado, ['pendiente', 'enviado', 'error'], true);
    }
}