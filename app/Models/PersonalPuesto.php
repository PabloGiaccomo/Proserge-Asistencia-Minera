<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonalPuesto extends Model
{
    protected $table = 'personal_puestos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'nombre',
        'funciones',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function setNombreAttribute(mixed $value): void
    {
        $this->attributes['nombre'] = trim((string) $value);
    }

    public function trabajadores(): HasMany
    {
        return $this->hasMany(Personal::class, 'puesto_id');
    }
}
