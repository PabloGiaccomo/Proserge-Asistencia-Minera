<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicaEmo extends Model
{
    use HasUuids;

    protected $table = 'clinicas_emo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'nombre', 'nombre_normalizado', 'activo', 'created_by_usuario_id', 'updated_by_usuario_id',
    ];

    protected $casts = ['activo' => 'boolean'];

    public function citas(): HasMany
    {
        return $this->hasMany(PersonalEmoCita::class, 'clinica_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by_usuario_id');
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'updated_by_usuario_id');
    }
}
