<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Empresa extends Model
{
    protected $fillable = [
        'ruc', 'razon_social', 'nombre_comercial', 'direccion', 'ubigeo', 'departamento',
        'provincia', 'distrito', 'urbanizacion', 'telefono', 'email', 'cuentas_bancarias', 'entorno',
        'sol_usuario', 'sol_clave', 'certificado_path', 'certificado_vence',
        'redondeo_precio', 'logo_path',
    ];

    // La clave SOL nunca se envía al navegador
    protected $hidden = ['sol_clave'];

    protected function casts(): array
    {
        return [
            'sol_clave' => 'encrypted', // se cifra al guardar y se descifra al leer
            'certificado_vence' => 'date',
            'redondeo_precio' => 'decimal:2',
        ];
    }

    public static function actual(): self
    {
        return static::query()->firstOrFail();
    }

    public function esProduccion(): bool
    {
        return $this->entorno === 'produccion';
    }

    /** URL del logo (cambia con cada logo nuevo, así el navegador no muestra uno antiguo). */
    public function logoUrl(): ?string
    {
        if (! $this->logo_path || ! Storage::disk('local')->exists($this->logo_path)) {
            return null;
        }

        return url('/logo-empresa').'?v='.substr(md5($this->logo_path), 0, 8);
    }

    /** Logo como imagen incrustada para el PDF (dompdf no descarga imágenes de internet). */
    public function logoDataUri(): ?string
    {
        if (! $this->logo_path || ! Storage::disk('local')->exists($this->logo_path)) {
            return null;
        }
        $mime = Storage::disk('local')->mimeType($this->logo_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($this->logo_path));
    }
}