<?php

namespace App\Modules\Seguridad\Services;

use App\Models\Rol;
use App\Models\Usuario;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AdminProtectionService
{
    private const ADMIN_ROLE_NAMES = ['ADMIN', 'SUPERADMIN'];

    public const MESSAGE_ADMIN_FULL_ACCESS = 'El rol ADMIN debe conservar acceso total, incluyendo Usuarios y Roles.';
    public const MESSAGE_ADMIN_ROLE_ACTIVE = 'No se puede desactivar ni renombrar el rol ADMIN.';
    public const MESSAGE_LAST_ADMIN_USER = 'No se puede desactivar ni quitar el rol ADMIN al ultimo usuario ADMIN activo.';
    public const MESSAGE_LAST_ADMIN_DELETE = 'No se puede eliminar al ultimo usuario administrador activo.';

    public function isAdminRoleName(?string $roleName): bool
    {
        return in_array(strtoupper(trim((string) $roleName)), self::ADMIN_ROLE_NAMES, true);
    }

    public function assertRoleUpdateAllowed(Rol $rol, array $payload): void
    {
        $currentName = strtoupper(trim((string) $rol->nombre));
        $nextName = strtoupper(trim((string) ($payload['nombre'] ?? $rol->nombre)));

        if (!$this->isAdminRoleName($currentName) && !$this->isAdminRoleName($nextName)) {
            return;
        }

        if ($this->isAdminRoleName($currentName) && !$this->isAdminRoleName($nextName)) {
            $this->block(self::MESSAGE_ADMIN_ROLE_ACTIVE);
        }

        if (strtoupper(trim((string) ($payload['estado'] ?? $rol->estado))) !== 'ACTIVO') {
            $this->block(self::MESSAGE_ADMIN_ROLE_ACTIVE);
        }

        $permissions = PermissionMatrix::normalize($payload['permisos'] ?? $rol->permisos ?? []);
        if (!$this->hasAdminCriticalPermissions($permissions)) {
            $this->block(self::MESSAGE_ADMIN_FULL_ACCESS);
        }
    }

    public function assertRoleToggleAllowed(Rol $rol): void
    {
        if ($this->isAdminRoleName($rol->nombre) && strtoupper((string) $rol->estado) === 'ACTIVO') {
            $this->block(self::MESSAGE_ADMIN_ROLE_ACTIVE);
        }
    }

    public function assertUserUpdateAllowed(Usuario $usuario, array $payload): void
    {
        if (!$this->isActiveAdminUser((string) $usuario->id)) {
            return;
        }

        if ($this->activeAdminUserCount() > 1) {
            return;
        }

        if (!$this->payloadKeepsActiveAdminUser($payload)) {
            $this->block(self::MESSAGE_LAST_ADMIN_USER);
        }
    }

    public function assertUserToggleAllowed(Usuario $usuario): void
    {
        if (!$this->isActiveAdminUser((string) $usuario->id)) {
            return;
        }

        if ($this->activeAdminUserCount() <= 1) {
            $this->block(self::MESSAGE_LAST_ADMIN_USER);
        }
    }

    public function assertUserDeleteAllowed(Usuario $usuario): void
    {
        if (!$this->isActiveAdminUser((string) $usuario->id)) {
            return;
        }

        if ($this->activeAdminUserCount() <= 1) {
            $this->block(self::MESSAGE_LAST_ADMIN_DELETE);
        }
    }

    public function isSuperAdminUser(?Usuario $usuario): bool
    {
        if (!$usuario) {
            return false;
        }

        return in_array('SUPERADMIN', PermissionMatrix::activeRoleNames($usuario), true);
    }

    public function getTransactionalDependencies(Usuario $usuario): array
    {
        $dependencies = [];

        $checks = [
            ['personal_bloqueo', 'bloqueado_por_id', 'Bloqueos de personal registrados'],
            ['personal_bloqueos', 'bloqueado_por_id', 'Bloqueos de personal registrados'],
            ['personal_contratos', 'creado_por', 'Contratos de personal creados'],
            ['personal_contratos', 'actualizado_por', 'Contratos de personal actualizados'],
            ['personal_contratos', 'anulado_por_usuario_id', 'Contratos de personal anulados'],
            ['personal_contratos', 'cerrado_por_usuario_id', 'Contratos de personal cerrados'],
            ['personal_contrato_datos', 'actualizado_por', 'Datos de contrato actualizados'],
            ['personal_contrato_datos', 'updated_by_usuario_id', 'Datos de contrato actualizados'],
            ['personal_contrato_correcciones', 'usuario_id', 'Correcciones de contratos registradas'],
            ['personal_fichas', 'revisado_por', 'Fichas de personal revisadas'],
            ['personal_fichas', 'aprobado_por', 'Fichas de personal aprobadas'],
            ['personal_fichas', 'creado_por', 'Fichas de personal creadas'],
            ['personal_ingresos', 'reviewed_by_usuario_id', 'Ingresos de personal revisados'],
            ['personal_documento_estados', 'usuario_id', 'Estados de documentos de personal gestionados'],
            ['personal', 'cesado_by_usuario', 'Ceses de personal registrados'],
            ['personal', 'created_by_usuario_id', 'Trabajadores registrados en Personal'],
            ['personal', 'updated_by_usuario_id', 'Trabajadores actualizados en Personal'],
            ['personal', 'lista_negra_by_usuario_id', 'Registros en lista negra de personal'],
            ['personal_mina_examenes', 'usuario_convalidacion_id', 'Convalidaciones de exámenes mineros'],
            ['personal_mina_examenes', 'usuario_actualizacion_id', 'Actualizaciones de exámenes mineros'],
            ['personal_mina_intentos', 'usuario_registro_id', 'Intentos de exámenes mineros registrados'],
            ['personal_mina_requisitos', 'created_by_usuario_id', 'Requisitos mineros creados'],
            ['personal_mina_requisitos', 'updated_by_usuario_id', 'Requisitos mineros actualizados'],
            ['rq_mina', 'created_by', 'Requerimientos de mina (RQ Mina) creados'],
            ['rq_mina', 'updated_by', 'Requerimientos de mina (RQ Mina) actualizados'],
            ['rq_mina_planes', 'creado_por', 'Planes de RQ Mina creados'],
            ['rq_mina_planes', 'actualizado_por', 'Planes de RQ Mina actualizados'],
            ['rq_mina_detalle_cambios', 'usuario_id', 'Historial de cambios en RQ Mina'],
            ['rq_mina_field_options', 'created_by_usuario_id', 'Opciones de RQ Mina configuradas'],
            ['asistencia_encabezado', 'creado_por', 'Asistencias operativas creadas'],
            ['asistencia_encabezado', 'cerrado_por', 'Asistencias operativas cerradas'],
            ['faltas', 'corregido_por_usuario_id', 'Correcciones de faltas registradas'],
            ['faltas', 'anulado_por_usuario_id', 'Faltas anuladas'],
            ['evaluacion_supervisor', 'created_by_usuario_id', 'Evaluaciones de supervisor creadas'],
            ['evaluacion_supervisor', 'updated_by_usuario_id', 'Evaluaciones de supervisor actualizadas'],
            ['evaluaciones_supervisor', 'created_by_usuario_id', 'Evaluaciones de supervisor registradas'],
            ['evaluaciones_supervisor', 'usuario_id', 'Evaluaciones de supervisor registradas'],
            ['evaluacion_residente', 'created_by_usuario_id', 'Evaluaciones de residente creadas'],
            ['evaluacion_residente', 'updated_by_usuario_id', 'Evaluaciones de residente actualizadas'],
            ['evaluaciones_residente', 'created_by_usuario_id', 'Evaluaciones de residente registradas'],
            ['evaluaciones_residente', 'usuario_id', 'Evaluaciones de residente registradas'],
            ['evaluacion_desempeno', 'evaluado_por_usuario_id', 'Evaluaciones de desempeño registradas'],
            ['evaluacion_desempeno', 'evaluador_id', 'Evaluaciones de desempeño registradas'],
            ['evaluaciones_desempeno', 'evaluado_por_usuario_id', 'Evaluaciones de desempeño registradas'],
            ['parada_herramientas', 'created_by_usuario_id', 'Herramientas de parada creadas'],
            ['parada_herramientas', 'updated_by_usuario_id', 'Herramientas de parada actualizadas'],
            ['parada_herramientas', 'created_by', 'Herramientas de parada creadas'],
            ['parada_herramientas', 'updated_by', 'Herramientas de parada actualizadas'],
            ['parada_herramienta_catalogos', 'created_by_usuario_id', 'Catálogos de herramientas creados'],
            ['parada_herramienta_catalogos', 'updated_by_usuario_id', 'Catálogos de herramientas actualizados'],
        ];

        foreach ($checks as [$table, $column, $label]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                if (DB::table($table)->where($column, $usuario->id)->exists()) {
                    $dependencies[] = $label;
                }
            }
        }

        return array_values(array_unique($dependencies));
    }

    public function activeAdminUserCount(): int
    {
        if (!Schema::hasTable('usuarios') || !Schema::hasTable('roles')) {
            return 0;
        }

        return $this->activeAdminUserQuery()->count();
    }

    public function isActiveAdminUser(string $usuarioId): bool
    {
        if ($usuarioId === '' || !Schema::hasTable('usuarios') || !Schema::hasTable('roles')) {
            return false;
        }

        return $this->activeAdminUserQuery()
            ->where('usuarios.id', $usuarioId)
            ->exists();
    }

    private function payloadKeepsActiveAdminUser(array $payload): bool
    {
        if ($this->hasUsuarioEstadoColumn() && strtoupper(trim((string) ($payload['estado'] ?? 'ACTIVO'))) !== 'ACTIVO') {
            return false;
        }

        $roleIds = collect([
                $payload['rol_id'] ?? null,
                ...($payload['area_role_ids'] ?? []),
                ...($payload['cargo_role_ids'] ?? []),
            ])
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($roleIds->isEmpty()) {
            return false;
        }

        return Rol::query()
            ->whereIn('id', $roleIds->all())
            ->whereIn('nombre', self::ADMIN_ROLE_NAMES)
            ->where('estado', 'ACTIVO')
            ->exists();
    }

    private function activeAdminUserQuery()
    {
        $query = Usuario::query();

        if ($this->hasUsuarioEstadoColumn()) {
            $query->where('usuarios.estado', 'ACTIVO');
        }

        return $query->where(function ($adminQuery): void {
            $adminQuery->whereHas('rol', function ($roleQuery): void {
                $this->activeAdminRoleScope($roleQuery);
            });

            if (Schema::hasTable('usuario_roles')) {
                $adminQuery->orWhereHas('rolesAdicionales', function ($roleQuery): void {
                    $this->activeAdminRoleScope($roleQuery);
                });
            }
        });
    }

    private function activeAdminRoleScope($query): void
    {
        $query->whereIn('nombre', self::ADMIN_ROLE_NAMES)
            ->where('estado', 'ACTIVO');
    }

    private function hasAdminCriticalPermissions(array $permissions): bool
    {
        $moduleActions = PermissionCatalog::availableModuleActions();

        foreach (['usuarios', 'roles'] as $module) {
            foreach (($moduleActions[$module] ?? ['ver']) as $action) {
                if (($permissions[$module][$action] ?? false) !== true) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hasUsuarioEstadoColumn(): bool
    {
        return Schema::hasTable('usuarios') && Schema::hasColumn('usuarios', 'estado');
    }

    private function block(string $message): void
    {
        throw ValidationException::withMessages([
            'admin' => $message,
        ]);
    }
}
