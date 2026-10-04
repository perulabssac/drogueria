<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un envío del comprobante al cliente (correo o WhatsApp). */
class ComprobanteEnvio extends Model
{
    public const CANALES = ['correo' => 'Correo', 'whatsapp' => 'WhatsApp'];

    protected $fillable = ['comprobante_id', 'user_id', 'canal', 'destino', 'estado', 'error'];

    protected $appends = ['canal_nombre'];

    /** Fechas en hora de Perú al enviarlas a Vue. */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getCanalNombreAttribute(): string
    {
        return self::CANALES[$this->canal] ?? $this->canal;
    }
}