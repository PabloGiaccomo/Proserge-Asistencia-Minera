<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalDocumentoEstado extends Model
{
    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_CARGADO = 'CARGADO';
    public const ESTADO_OBSERVADO = 'OBSERVADO';
    public const ESTADO_APROBADO = 'APROBADO';
    public const ESTADO_NO_APLICA = 'NO_APLICA';

    public const VIDA_LEY_FISICO_ENTREGADO = 'ENTREGADO';
    public const VIDA_LEY_FISICO_NO_ENTREGADO = 'NO_ENTREGADO';
    public const VIDA_LEY_FISICO_PENDIENTE = 'PENDIENTE';
    public const VIDA_LEY_FISICO_NO_APLICA_UBICACION = 'NO_APLICA_UBICACION';

    protected $table = 'personal_documento_estados';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_ficha_id',
        'tipo',
        'estado',
        'observacion',
        'vida_ley_entrega_fisica',
        'vida_ley_entrega_observacion',
        'updated_by_usuario_id',
        'estado_updated_at',
    ];

    protected $casts = [
        'estado_updated_at' => 'datetime',
    ];

    public static function estados(): array
    {
        return [
            self::ESTADO_PENDIENTE,
            self::ESTADO_CARGADO,
            self::ESTADO_OBSERVADO,
            self::ESTADO_APROBADO,
            self::ESTADO_NO_APLICA,
        ];
    }

    public static function vidaLeyEntregaFisicaEstados(): array
    {
        return [
            self::VIDA_LEY_FISICO_ENTREGADO,
            self::VIDA_LEY_FISICO_NO_ENTREGADO,
            self::VIDA_LEY_FISICO_PENDIENTE,
            self::VIDA_LEY_FISICO_NO_APLICA_UBICACION,
        ];
    }

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(PersonalFicha::class, 'personal_ficha_id');
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'updated_by_usuario_id');
    }
}
