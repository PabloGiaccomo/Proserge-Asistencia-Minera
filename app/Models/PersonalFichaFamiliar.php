<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalFichaFamiliar extends Model
{
    protected $table = 'personal_ficha_familiares';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_ficha_id',
        'nombres_apellidos',
        'parentesco',
        'fecha_nacimiento',
        'tipo_documento',
        'numero_documento',
        'telefono',
        'vive_con_trabajador',
        'contacto_emergencia',
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
        'vive_con_trabajador' => 'boolean',
        'contacto_emergencia' => 'boolean',
    ];

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(PersonalFicha::class, 'personal_ficha_id');
    }
}
