<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EppOperacion extends Model
{
    use HasUuids;

    public const TIPO_ENTREGA = 'ENTREGA';

    public const TIPO_DEVOLUCION = 'DEVOLUCION';

    public const TIPO_CORRECCION = 'CORRECCION';

    public const TIPO_ANULACION = 'ANULACION';

    public const ESTADO_BORRADOR = 'BORRADOR';

    public const ESTADO_RESERVADO = 'RESERVADO';

    public const ESTADO_LISTO_PARA_FIRMA = 'LISTO_PARA_FIRMA';

    public const ESTADO_FIRMADO = 'FIRMADO';

    public const ESTADO_FIRMADO_PARCIAL = 'FIRMADO_PARCIAL';

    public const ESTADO_PENDIENTE_STOCK = 'PENDIENTE_STOCK';

    public const ESTADO_RECHAZADO = 'RECHAZADO';

    public const ESTADO_EXPIRADO = 'EXPIRADO';

    public const ESTADO_ANULADO = 'ANULADO';

    protected $table = 'epp_operaciones';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_id',
        'almacen_id',
        'mina_id',
        'tipo',
        'estado',
        'submission_uuid',
        'operacion_origen_id',
        'registrado_por_usuario_id',
        'confirmado_por_usuario_id',
        'resumen_sha256',
        'resumen_json',
        'version',
        'reserva_expires_at',
        'firmado_at',
        'motivo',
        'observacion',
    ];

    protected $casts = [
        'resumen_json' => 'array',
        'version' => 'integer',
        'reserva_expires_at' => 'datetime',
        'firmado_at' => 'datetime',
    ];

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(EppAlmacen::class, 'almacen_id');
    }

    public function mina(): BelongsTo
    {
        return $this->belongsTo(Mina::class, 'mina_id');
    }

    public function operacionOrigen(): BelongsTo
    {
        return $this->belongsTo(EppOperacion::class, 'operacion_origen_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por_usuario_id');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'confirmado_por_usuario_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(EppOperacionDetalle::class, 'operacion_id');
    }

    public function aceptacion(): HasOne
    {
        return $this->hasOne(EppAceptacion::class, 'operacion_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EppOperacionEvento::class, 'operacion_id');
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(EppEntrega::class, 'operacion_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(EppReserva::class, 'operacion_id');
    }
}
