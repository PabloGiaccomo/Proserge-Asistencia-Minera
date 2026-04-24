<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Personal extends Model
{
    protected $table = 'personal';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'dni',
        'nombre_completo',
        'puesto',
        'ocupacion',
        'contrato',
        'es_supervisor',
        'qr_code',
        'fecha_ingreso',
        'estado',
        'telefono',
        'telefono_1',
        'telefono_2',
        'correo',
    ];

    protected $casts = [
        'es_supervisor' => 'boolean',
        'fecha_ingreso' => 'date',
    ];

    public function minas(): BelongsToMany
    {
        return $this->belongsToMany(Mina::class, 'personal_mina', 'personal_id', 'mina_id')
            ->withPivot(['id', 'estado'])
            ->withTimestamps();
    }

    public function relacionesMina(): HasMany
    {
        return $this->hasMany(PersonalMina::class, 'personal_id');
    }

    public function usuario(): HasOne
    {
        return $this->hasOne(Usuario::class, 'personal_id');
    }
}
