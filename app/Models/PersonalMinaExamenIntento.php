<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalMinaExamenIntento extends Model
{
    public const RESULTADO_PENDIENTE = 'PENDIENTE';
    public const RESULTADO_APROBADO = 'APROBADO';
    public const RESULTADO_DESAPROBADO = 'DESAPROBADO';
    public const RESULTADO_NO_ASISTIO = 'NO_ASISTIO';
    public const RESULTADO_ANULADO = 'ANULADO';

    protected $table = 'personal_mina_examen_intentos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_mina_examen_id',
        'numero_intento',
        'fecha_programacion',
        'fecha_realizacion',
        'resultado',
        'nota',
        'precio_aplicado',
        'moneda_aplicada',
        'fecha_precio_aplicado',
        'fuente_precio',
        'archivo_path',
        'archivo_nombre_original',
        'archivo_mime',
        'archivo_size',
        'observacion',
        'usuario_registro_id',
    ];

    protected $casts = [
        'numero_intento' => 'integer',
        'fecha_programacion' => 'date',
        'fecha_realizacion' => 'date',
        'nota' => 'decimal:2',
        'precio_aplicado' => 'decimal:2',
        'fecha_precio_aplicado' => 'date',
        'archivo_size' => 'integer',
    ];

    public function examenTrabajador(): BelongsTo
    {
        return $this->belongsTo(PersonalMinaExamen::class, 'personal_mina_examen_id');
    }

    public function usuarioRegistro(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_registro_id');
    }
}
