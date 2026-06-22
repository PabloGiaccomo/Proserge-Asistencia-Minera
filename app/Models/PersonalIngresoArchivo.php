<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalIngresoArchivo extends Model
{
    protected $table = 'personal_ingreso_archivos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_ingreso_id',
        'tipo',
        'nombre_original',
        'path',
        'mime',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function ingreso(): BelongsTo
    {
        return $this->belongsTo(PersonalIngreso::class, 'personal_ingreso_id');
    }
}
