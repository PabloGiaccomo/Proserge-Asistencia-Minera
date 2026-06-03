<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalContratoDato extends Model
{
    protected $table = 'personal_contrato_datos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_id',
        'fecha_inicio_contrato',
        'fecha_fin_contrato',
        'periodo_prueba_inicio',
        'periodo_prueba_fin',
        'sueldo_hora_paradas',
        'sueldo_hora_paradas_texto',
        'sueldo_dia_taller',
        'sueldo_dia_taller_texto',
        'funciones',
        'sueldo_num',
        'sueldo_texto',
        'puesto',
        'fecha_firma',
        'downloaded_at',
        'signed_at',
        'signed_contract_path',
        'signed_contract_original_name',
        'signed_contract_mime',
        'signed_contract_size',
        'updated_by_usuario_id',
    ];

    protected $casts = [
        'fecha_inicio_contrato' => 'date',
        'fecha_fin_contrato' => 'date',
        'periodo_prueba_inicio' => 'date',
        'periodo_prueba_fin' => 'date',
        'fecha_firma' => 'date',
        'downloaded_at' => 'datetime',
        'signed_at' => 'datetime',
        'signed_contract_size' => 'integer',
    ];

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'updated_by_usuario_id');
    }
}
