<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EppRegistro extends Model
{
    public const ESTADO_ACTIVO = 'ACTIVO';

    public const ESTADO_INACTIVO = 'INACTIVO';

    public const POLITICA_RETORNABLE = 'RETORNABLE';

    public const POLITICA_CONSUMIBLE = 'CONSUMIBLE';

    protected $table = 'epp_registro';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'codigo',
        'nombre',
        'categoria',
        'unidad_minera',
        'precio_unitario',
        'precio_alquiler',
        'proveedor',
        'orden_compra',
        'facturacion',
        'stock',
        'vida_util_dias',
        'requiere_talla',
        'tallas',
        'requiere_color',
        'colores',
        'otros_atributos',
        'estado',
        'politica_devolucion',
        'control_inventario',
        'permite_entrega_repetida',
        'requiere_serie',
        'es_requerido_cobertura',
    ];

    protected $casts = [
        'precio_unitario' => 'decimal:2',
        'precio_alquiler' => 'decimal:2',
        'stock' => 'integer',
        'vida_util_dias' => 'integer',
        'requiere_talla' => 'boolean',
        'tallas' => 'array',
        'requiere_color' => 'boolean',
        'colores' => 'array',
        'otros_atributos' => 'array',
        'control_inventario' => 'boolean',
        'permite_entrega_repetida' => 'boolean',
        'requiere_serie' => 'boolean',
        'es_requerido_cobertura' => 'boolean',
    ];

    public function entregas(): HasMany
    {
        return $this->hasMany(EppEntrega::class, 'epp_id');
    }

    public function existencias(): HasMany
    {
        return $this->hasMany(EppExistencia::class, 'epp_id');
    }
}
