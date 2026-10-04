<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    // Catálogo 06 de SUNAT
    public const TIPOS_DOCUMENTO = [
        '0' => 'Sin documento',
        '1' => 'DNI',
        '4' => 'Carnet de extranjería',
        '6' => 'RUC',
        '7' => 'Pasaporte',
    ];

    public const DNI = '1';

    public const RUC = '6';

    protected $fillable = [
        'tipo_documento', 'numero_documento', 'razon_social', 'nombre_comercial',
        'direccion', 'distrito', 'provincia', 'departamento', 'ubigeo', 'ciudad',
        'email', 'telefono', 'contacto', 'dias_credito', 'limite_credito',
        'estado_sunat', 'condicion_sunat', 'verificado_at', 'observaciones', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'dias_credito' => 'integer',
            'limite_credito' => 'decimal:2',
            'verificado_at' => 'datetime',
            'activo' => 'boolean',
        ];
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class);
    }

    /** Cliente genérico para boletas sin identificar al comprador. */
    public static function clientesVarios(): self
    {
        return static::firstOrCreate(
            ['tipo_documento' => self::DNI, 'numero_documento' => '00000000'],
            ['razon_social' => 'CLIENTES VARIOS'],
        );
    }

    public function esRuc(): bool
    {
        return $this->tipo_documento === self::RUC;
    }

    public function esClientesVarios(): bool
    {
        return $this->numero_documento === '00000000';
    }

    /**
     * Problema del RUC según la última verificación con SUNAT (null si está bien o no se verificó).
     * No se debe facturar a un RUC dado de baja o "no habido".
     */
    public function problemaSunat(): ?string
    {
        if (! $this->esRuc()) {
            return null;
        }
        if ($this->estado_sunat && $this->estado_sunat !== 'ACTIVO') {
            return "El RUC figura en SUNAT como {$this->estado_sunat}.";
        }
        if ($this->condicion_sunat && $this->condicion_sunat !== 'HABIDO') {
            return "El RUC figura en SUNAT como {$this->condicion_sunat}.";
        }

        return null;
    }

    /** Lo que el cliente debe en total (ventas al crédito con saldo). */
    public function deuda(): float
    {
        return round((float) $this->comprobantes()
            ->where('forma_pago', 'credito')
            ->validos()
            ->sum('saldo'), 2);
    }

    /** ¿Tiene alguna venta al crédito con cuotas ya vencidas y sin pagar? */
    public function tieneDeudaVencida(): bool
    {
        return $this->comprobantes()
            ->where('forma_pago', 'credito')
            ->validos()
            ->where('saldo', '>', 0)
            ->get(['id', 'total', 'saldo'])
            ->contains(function (Comprobante $c) {
                // Consulta directa (la relación cuotas() tiene orderBy, que MySQL no acepta con SUM)
                $vencidoHastaHoy = (float) ComprobanteCuota::query()
                    ->where('comprobante_id', $c->id)
                    ->where('fecha_vencimiento', '<', today()->toDateString())
                    ->sum('monto');

                // Lo ya pagado se aplica a las cuotas más antiguas primero
                return $vencidoHastaHoy > ((float) $c->total - (float) $c->saldo) + 0.009;
            });
    }
}