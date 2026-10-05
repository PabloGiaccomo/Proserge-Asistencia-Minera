<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluacionesImportLote extends Model
{
    protected $table = 'evaluaciones_import_lotes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'hash', 'estado', 'manifest_path', 'resumen',
        'created_by_usuario_id', 'confirmed_by_usuario_id', 'confirmed_at',
        'confirmation_key',
    ];

    protected $casts = ['resumen' => 'array', 'confirmed_at' => 'datetime'];
}
