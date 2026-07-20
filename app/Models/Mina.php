<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    public function scopeOperationalCatalog(Builder $query): Builder
    {
        $query->whereRaw("UPPER(TRIM(minas.nombre)) NOT IN (?, ?, ?, ?)", [
            'SIN MINA',
            'SIN_MINA',
            'NO APLICA',
            'N/A',
        ]);

        foreach (['oficinas' => 'o', 'talleres' => 't'] as $table => $alias) {
            $query->whereNotExists(function ($sub) use ($table, $alias): void {
                $sub->select(DB::raw(1))
                    ->from("{$table} as {$alias}")
                    ->whereRaw("LOWER(TRIM({$alias}.nombre)) = LOWER(TRIM(minas.nombre))");
            });
        }

        return $query;
    }

    public function scopeActiveOperational(Builder $query): Builder
    {
        return $query->operationalCatalog()->where('estado', 'ACTIVO');
    }

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

    public function requisitos(): HasMany
    {
        return $this->hasMany(MinaRequisito::class, 'mina_id');
    }

    public function paraderos(): HasMany
    {
        return $this->hasMany(MinaParadero::class, 'mina_id');
    }
}
