<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sucursal extends Model
{
    protected $table = 'sucursales';

    protected $fillable = ['nombre', 'direccion', 'codigo_establecimiento', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function series(): HasMany
    {
        return $this->hasMany(Serie::class);
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }
}