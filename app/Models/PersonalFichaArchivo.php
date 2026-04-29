<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalFichaArchivo extends Model
{
    protected $table = 'personal_ficha_archivos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_ficha_id',
        'tipo',
        'nombre_original',
        'path',
        'mime',
        'size',
        'uploaded_by_usuario_id',
        'uploaded_by_public',
    ];

    protected $casts = [
        'uploaded_by_public' => 'boolean',
        'size' => 'integer',
    ];

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(PersonalFicha::class, 'personal_ficha_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'uploaded_by_usuario_id');
    }
}
