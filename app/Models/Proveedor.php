<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    protected $table = 'proveedores';

    protected $fillable = ['ruc', 'razon_social', 'direccion', 'telefono', 'email', 'contacto', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class);
    }
}