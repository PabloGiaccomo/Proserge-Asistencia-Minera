<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsistenciaHistorica extends Model
{
    protected $table = 'asistencia_historica';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'lote_id', 'trabajador_id', 'fecha', 'presente', 'source_file',
        'source_sheet', 'source_row', 'source_id', 'source_mina', 'source_key',
    ];

    protected $casts = ['fecha' => 'date', 'presente' => 'boolean'];

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'trabajador_id');
    }
}
