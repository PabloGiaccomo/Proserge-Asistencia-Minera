<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Personal extends Model
{
    protected $table = 'personal';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'dni',
        'tipo_documento',
        'numero_documento',
        'nombre_completo',
        'puesto',
        'puesto_id',
        'ocupacion',
        'contrato',
        'es_supervisor',
        'qr_code',
        'fecha_ingreso',
        'fecha_cese',
        'motivo_cese',
        'cesado_at',
        'cesado_by_usuario_id',
        'estado',
        'origen_registro',
        'observacion_historica',
        'pendiente_regularizacion',
        'registrado_como_antiguo_at',
        'registrado_como_antiguo_by_usuario_id',
        'telefono',
        'telefono_1',
        'telefono_2',
        'correo',
    ];

    protected $casts = [
        'es_supervisor' => 'boolean',
        'fecha_ingreso' => 'date',
        'fecha_cese' => 'date',
        'cesado_at' => 'datetime',
        'pendiente_regularizacion' => 'boolean',
        'registrado_como_antiguo_at' => 'datetime',
    ];

    public function setNombreCompletoAttribute(mixed $value): void
    {
        $this->attributes['nombre_completo'] = mb_strtoupper(trim((string) $value), 'UTF-8');
    }

    public function minas(): BelongsToMany
    {
        return $this->belongsToMany(Mina::class, 'personal_mina', 'personal_id', 'mina_id')
            ->withPivot(['id', 'estado', 'estado_habilitacion', 'activo'])
            ->where(function ($query): void {
                $query->where('personal_mina.activo', true)
                    ->orWhereNull('personal_mina.activo');
            })
            ->withTimestamps();
    }

    public function relacionesMina(): HasMany
    {
        return $this->hasMany(PersonalMina::class, 'personal_id');
    }

    public function usuario(): HasOne
    {
        return $this->hasOne(Usuario::class, 'personal_id');
    }

    public function cesadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cesado_by_usuario_id');
    }

    public function puestoCatalogo(): BelongsTo
    {
        return $this->belongsTo(PersonalPuesto::class, 'puesto_id');
    }

    public function bloqueos(): HasMany
    {
        return $this->hasMany(PersonalBloqueo::class, 'personal_id');
    }

    public function rqProsergeDetalles(): HasMany
    {
        return $this->hasMany(RQProsergeDetalle::class, 'personal_id');
    }

    public function fichas(): HasMany
    {
        return $this->hasMany(PersonalFicha::class, 'personal_id');
    }

    public function fichaColaborador(): HasOne
    {
        return $this->hasOne(PersonalFicha::class, 'personal_id')->latestOfMany();
    }

    public function contratosLaborales(): HasMany
    {
        return $this->hasMany(PersonalContrato::class, 'personal_id')
            ->orderBy('contrato_numero');
    }

    public function contratoLaboralActual(): HasOne
    {
        return $this->hasOne(PersonalContrato::class, 'personal_id')
            ->whereIn('estado', [PersonalContrato::ESTADO_PREPARACION, PersonalContrato::ESTADO_ACTIVO])
            ->latestOfMany('contrato_numero');
    }

    public function contratoDatos(): HasOne
    {
        return $this->hasOne(PersonalContratoDato::class, 'personal_id');
    }
}
