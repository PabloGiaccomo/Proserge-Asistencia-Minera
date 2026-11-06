<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonalIngresoLink extends Model
{
    protected $table = 'personal_ingreso_links';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'ingreso_id', 'personal_id', 'proposito', 'token_hash', 'expires_at',
        'revoked_at', 'submitted_at', 'actor_id', 'revoked_by_actor_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    protected $hidden = ['token_hash'];
}
