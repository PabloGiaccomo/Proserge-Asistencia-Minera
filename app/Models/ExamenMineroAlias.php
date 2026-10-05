<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamenMineroAlias extends Model
{
    protected $table = 'examen_minero_aliases';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
}
