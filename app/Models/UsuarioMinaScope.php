<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsuarioMinaScope extends Model
{
    protected $table = 'usuario_mina_scope';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'usuario_id',
        'mina_id',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function mina(): BelongsTo
    {
        return $this->belongsTo(Mina::class, 'mina_id');
    }
}
