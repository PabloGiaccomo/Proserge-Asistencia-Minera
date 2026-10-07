<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EppAceptacion extends Model
{
    use HasUuids;

    public const TIPO_TRABAJADOR = 'TRABAJADOR';

    public const TIPO_TESTIGO_AUTORIZADO = 'TESTIGO_AUTORIZADO';

    public const INTEGRIDAD_PENDIENTE = 'PENDIENTE_VERIFICACION';

    public const INTEGRIDAD_VALIDA = 'FIRMA_VALIDA';

    public const INTEGRIDAD_INVALIDA = 'FIRMA_INVALIDA';

    public const INTEGRIDAD_ARCHIVO_FALTANTE = 'ARCHIVO_FALTANTE';

    protected $table = 'epp_aceptaciones';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'operacion_id',
        'personal_id',
        'tipo_firmante',
        'firmante_nombre',
        'documento_snapshot',
        'testigo_usuario_id',
        'aprobador_usuario_id',
        'motivo_excepcion',
        'operador_usuario_id',
        'firma_path',
        'firma_sha256',
        'contenido_json',
        'contenido_sha256',
        'codigo_verificacion',
        'submission_uuid',
        'firmado_at',
        'firmado_at_utc',
        'zona_horaria',
        'declaracion_version',
        'user_agent',
        'sesion_sha256',
        'ip_sha256',
        'estado_integridad',
    ];

    protected $casts = [
        'contenido_json' => 'array',
        'firmado_at' => 'datetime',
        'firmado_at_utc' => 'datetime',
    ];

    protected $hidden = ['firma_path', 'documento_snapshot', 'ip_sha256', 'sesion_sha256', 'user_agent'];

    public function getFirmadoAtUtcAttribute(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    /**
     * The acceptance service supplies canonical JSON and hashes these exact bytes.
     * Arrays use fixed encoding flags but preserve their supplied key order.
     */
    public function setContenidoJsonAttribute(array|string $value): void
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new \InvalidArgumentException('El contenido firmado debe ser un objeto o lista JSON.');
            }

            $this->attributes['contenido_json'] = $value;

            return;
        }

        $this->attributes['contenido_json'] = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(EppOperacion::class, 'operacion_id');
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function operador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'operador_usuario_id');
    }

    public function testigo(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'testigo_usuario_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aprobador_usuario_id');
    }
}
