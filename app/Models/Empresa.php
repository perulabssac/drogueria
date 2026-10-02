<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empresa extends Model
{
    protected $fillable = [
        'ruc', 'razon_social', 'nombre_comercial', 'direccion', 'ubigeo', 'departamento',
        'provincia', 'distrito', 'urbanizacion', 'telefono', 'email', 'cuentas_bancarias', 'entorno',
        'sol_usuario', 'sol_clave', 'certificado_path', 'certificado_vence',
    ];

    // La clave SOL nunca se envía al navegador
    protected $hidden = ['sol_clave'];

    protected function casts(): array
    {
        return [
            'sol_clave' => 'encrypted', // se cifra al guardar y se descifra al leer
            'certificado_vence' => 'date',
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
}