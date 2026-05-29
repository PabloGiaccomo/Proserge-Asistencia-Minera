<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RQMinaFieldOption extends Model
{
    protected $table = 'rq_mina_field_options';

    protected $fillable = [
        'id',
        'field_key',
        'value',
        'value_normalized',
        'usage_count',
        'created_by_usuario_id',
    ];

    public $incrementing = false;

    protected $keyType = 'string';
}
