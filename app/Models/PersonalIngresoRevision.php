<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonalIngresoRevision extends Model
{
    protected $table = 'personal_ingreso_revisiones';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'ingreso_id', 'revision', 'evento', 'snapshot', 'base_hash', 'review_hash', 'actor_id', 'origen',
    ];

    protected $casts = ['snapshot' => 'encrypted:array'];

    protected $hidden = ['snapshot'];
}
