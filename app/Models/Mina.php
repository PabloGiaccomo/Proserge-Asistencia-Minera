<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mina extends Model
{
    protected $table = 'minas';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'nombre',
        'unidad_minera',
        'ubicacion',
        'link_ubicacion',
        'color',
        'estado',
    ];

    public function personal(): BelongsToMany
    {
        return $this->belongsToMany(Personal::class, 'personal_mina', 'mina_id', 'personal_id')
            ->withPivot(['id', 'estado'])
            ->withTimestamps();
    }

    public function relacionesPersonal(): HasMany
    {
        return $this->hasMany(PersonalMina::class, 'mina_id');
    }

    public function paraderos(): HasMany
    {
        return $this->hasMany(MinaParadero::class, 'mina_id');
    }
}
