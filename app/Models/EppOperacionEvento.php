<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EppOperacionEvento extends Model
{
    use HasUuids;

    protected $table = 'epp_operacion_eventos';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'operacion_id',
        'actor_usuario_id',
        'accion',
        'origen',
        'motivo',
        'snapshot_json',
    ];

    protected $casts = [
        'snapshot_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(EppOperacion::class, 'operacion_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'actor_usuario_id');
    }
}
