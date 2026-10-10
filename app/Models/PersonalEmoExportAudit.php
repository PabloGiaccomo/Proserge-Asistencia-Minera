<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalEmoExportAudit extends Model
{
    use HasUuids;

    protected $table = 'personal_emo_export_audits';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'usuario_id', 'filtros_json', 'filtros_hash', 'cantidad_filas', 'exportado_at',
    ];

    protected $casts = [
        'filtros_json' => 'array',
        'cantidad_filas' => 'integer',
        'exportado_at' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
