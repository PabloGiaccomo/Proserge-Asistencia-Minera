<?php

namespace Tests\Feature;

use App\Models\GrupoTrabajo;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Asistencia\Policies\AsistenciaPolicy;
use App\Modules\Asistencia\Services\AsistenciaDashboardService;
use App\Modules\Asistencia\Services\AsistenciaService;
use App\Modules\Dashboard\Policies\DashboardPolicy;
use App\Modules\Faltas\Policies\FaltaPolicy;
use App\Modules\Faltas\Services\FaltasService;
use App\Modules\ManPower\Policies\ManPowerPolicy;
use App\Modules\ManPower\Services\GrupoTrabajoService;
use App\Modules\ManPower\Services\ManPowerParadasService;
use App\Modules\ManPower\Services\ManPowerPlanningService;
use App\Modules\ParadaHerramientas\Services\ParadaHerramientaService;
use App\Modules\RQMina\Policies\RQMinaPolicy;
use App\Modules\RQMina\Services\RQMinaService;
use App\Modules\RQProserge\Policies\RQProsergePolicy;
use App\Modules\Transporte\Services\TransportePlanningService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RbacOperationalScopeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
    }

    public static function policies(): array
    {
        return [
            'asistencia' => [AsistenciaPolicy::class],
            'faltas' => [FaltaPolicy::class],
            'man power' => [ManPowerPolicy::class],
            'rq mina' => [RQMinaPolicy::class],
            'rq proserge' => [RQProsergePolicy::class],
            'dashboard' => [DashboardPolicy::class],
        ];
    }

    #[DataProvider('policies')]
    public function test_inactive_privileged_roles_never_bypass_operational_scope(string $policyClass): void
    {
        foreach (['ADMIN', 'SUPERADMIN', 'GERENTE'] as $name) {
            $user = $this->operator();
            $privileged = $this->privilegedRole($name, 'INACTIVO');
            $operatorRole = $user->rol_id;
            $user->update(['rol_id' => $privileged->id]);
            $this->attach($user, $operatorRole);
            $mine = (string) Str::uuid();
            $this->assertFalse($this->hasScope($policyClass, $user->fresh(), $mine), $name.' inactivo');
            $privileged->update(['estado' => 'ACTIVO']);
            $this->assertTrue($this->hasScope($policyClass, $user->fresh(), $mine), $name.' activo');
        }
    }

    #[DataProvider('policies')]
    public function test_additional_privileged_role_respects_activation_and_revocation(string $policyClass): void
    {
        $user = $this->operator();
        $privileged = $this->privilegedRole('SUPERADMIN', 'ACTIVO');
        $this->attach($user, $privileged->id);
        $mine = (string) Str::uuid();
        $this->assertTrue($this->hasScope($policyClass, $user->fresh(), $mine));
        $privileged->update(['estado' => 'INACTIVO']);
        $this->assertFalse($this->hasScope($policyClass, $user->fresh(), $mine));
    }

    public function test_gerente_does_not_gain_attendance_write_permission_from_name(): void
    {
        $user = $this->operator();
        $gerente = $this->privilegedRole('GERENTE', 'ACTIVO');
        $user->update(['rol_id' => $gerente->id]);
        $mine = $this->operationalContext($user);
        $group = GrupoTrabajo::where('destino_id', $mine)->firstOrFail();
        $policy = app(AsistenciaPolicy::class);

        $this->assertFalse($policy->canViewAllAttendances($user->fresh()));
        $this->assertFalse($policy->canRegisterGrupo($user->fresh(), $group));
        $this->assertFalse($policy->canCloseGrupo($user->fresh(), $group));

        $gerente->update(['permisos' => PermissionCatalog::matrixFromSelections(['asistencias' => ['ver']])]);
        $this->assertTrue($policy->manageGrupo($user->fresh(), $group));
        $this->assertFalse($policy->canRegisterGrupo($user->fresh(), $group));
        $this->assertFalse($policy->canCloseGrupo($user->fresh(), $group));
    }

    public static function operationalLists(): array
    {
        return array_map(fn ($name) => [$name], array_combine(
            ['rq mines', 'groups', 'attendance', 'attendance dashboard', 'absences', 'man power units', 'man power planning', 'transport planning', 'tools'],
            ['rq mines', 'groups', 'attendance', 'attendance dashboard', 'absences', 'man power units', 'man power planning', 'transport planning', 'tools'],
        ));
    }

    #[DataProvider('operationalLists')]
    public function test_operational_lists_keep_scope_when_privileged_primary_role_is_inactive(string $list): void
    {
        $user = $this->operator();
        $privileged = $this->privilegedRole('SUPERADMIN', 'INACTIVO');
        $operatorRole = $user->rol_id;
        $user->update(['rol_id' => $privileged->id]);
        $this->attach($user, $operatorRole);
        $mine = $this->operationalContext($user);

        $this->assertEmpty($this->listItems($list, $user->fresh()), 'Must not expose an unassigned mine');
        DB::table('usuario_mina_scope')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $user->id, 'mina_id' => $mine]);
        $this->assertNotEmpty($this->listItems($list, $user->fresh()), 'The remaining active role must still work in its mine');
    }

    public static function administratedLists(): array
    {
        return [
            'attendance' => ['attendance', 'asistencias', AsistenciaPolicy::class],
            'absences' => ['absences', 'faltas', FaltaPolicy::class],
            'man power units' => ['man power units', 'man_power', ManPowerPolicy::class],
            'man power planning' => ['man power planning', 'man_power', ManPowerPolicy::class],
        ];
    }

    #[DataProvider('administratedLists')]
    public function test_explicit_administration_has_same_scope_in_policy_and_lists(string $list, string $module, string $policyClass): void
    {
        $user = $this->operator();
        $mine = $this->operationalContext($user);
        $user->rol->update(['permisos' => PermissionCatalog::matrixFromSelections([$module => ['ver', 'administrar']])]);

        $this->assertTrue($this->hasScope($policyClass, $user->fresh(), $mine));
        $this->assertNotEmpty($this->listItems($list, $user->fresh()), 'An authorized mine must not disappear from lists');

        $user->rol->update(['permisos' => PermissionCatalog::matrixFromSelections([$module => ['ver']])]);
        $this->assertFalse($this->hasScope($policyClass, $user->fresh(), $mine));
        $this->assertEmpty($this->listItems($list, $user->fresh()), 'Removing administrar must restore the configured mine scope');
    }

    private function hasScope(string $policyClass, Usuario $user, string $mine): bool
    {
        $policy = app($policyClass);
        if ($policy instanceof DashboardPolicy) {
            return $policy->isPrivileged($user);
        }
        if ($policy instanceof FaltaPolicy) {
            return $policy->canAccessDestino($user, 'MINA', $mine);
        }

        return $policy->canAccessMina($user, $mine);
    }

    private function listItems(string $list, Usuario $user): mixed
    {
        return match ($list) {
            'rq mines' => app(RQMinaService::class)->getAvailableMinas($user),
            'groups' => app(GrupoTrabajoService::class)->listForUser($user, []),
            'attendance' => app(AsistenciaService::class)->listGrupos($user, []),
            'attendance dashboard' => app(AsistenciaDashboardService::class)->build($user, [])['groups'],
            'absences' => app(FaltasService::class)->list($user, []),
            'man power units' => app(ManPowerParadasService::class)->listUnidades($user),
            'man power planning' => app(ManPowerPlanningService::class)->buildContext($user, ['fecha' => '2026-10-06'])['paradas'],
            'transport planning' => app(TransportePlanningService::class)->buildContext($user, ['fecha' => '2026-10-06'])['paradas'],
            'tools' => app(ParadaHerramientaService::class)->listParadas($user),
        };
    }

    private function operator(): Usuario
    {
        $role = Rol::create(['id' => (string) Str::uuid(), 'nombre' => 'AUDIT-SCOPE-'.Str::random(12), 'estado' => 'ACTIVO',
            'permisos' => PermissionCatalog::matrixFromSelections([
                'inicio' => ['ver'], 'asistencias' => ['ver'], 'faltas' => ['ver'], 'man_power' => ['ver'],
                'rq_mina' => ['ver'], 'rq_proserge' => ['ver'], 'transportes' => ['ver'], 'herramientas' => ['ver'],
            ])]);

        return Usuario::create(['id' => (string) Str::uuid(), 'email' => 'scope-'.Str::random(12).'@test.local',
            'password' => 'audit-test-password', 'estado' => 'ACTIVO', 'rol_id' => $role->id]);
    }

    private function privilegedRole(string $name, string $state): Rol
    {
        $role = Rol::firstOrCreate(['nombre' => $name], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::emptyMatrix()]);
        $role->update(['estado' => $state, 'permisos' => PermissionCatalog::emptyMatrix()]);

        return $role;
    }

    private function attach(Usuario $user, string $roleId): void
    {
        DB::table('usuario_roles')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $user->id, 'rol_id' => $roleId, 'tipo' => 'CARGO']);
    }

    private function operationalContext(Usuario $user): string
    {
        $mine = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $mine, 'nombre' => 'AUDIT SCOPE MINE', 'unidad_minera' => 'AUDIT UNIT', 'estado' => 'ACTIVO']);
        $worker = (string) Str::uuid();
        DB::table('personal')->insert(['id' => $worker, 'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'AUDIT SCOPE WORKER', 'puesto' => 'TECNICO', 'qr_code' => Str::uuid(), 'estado' => 'ACTIVO']);
        $rq = (string) Str::uuid();
        DB::table('rq_mina')->insert(['id' => $rq, 'mina_id' => $mine, 'area' => 'AUDIT', 'fecha_inicio' => '2026-10-01',
            'fecha_fin' => '2026-10-31', 'estado' => 'ENVIADO', 'created_by_usuario_id' => $user->id]);
        $detail = (string) Str::uuid();
        DB::table('rq_mina_detalle')->insert(['id' => $detail, 'rq_mina_id' => $rq, 'puesto' => 'TECNICO', 'cantidad' => 1]);
        $rp = (string) Str::uuid();
        DB::table('rq_proserge')->insert(['id' => $rp, 'rq_mina_id' => $rq, 'mina_id' => $mine,
            'responsable_rrhh_id' => $user->id, 'estado' => 'BORRADOR']);
        DB::table('rq_proserge_detalle')->insert(['id' => (string) Str::uuid(), 'rq_proserge_id' => $rp,
            'rq_mina_detalle_id' => $detail, 'personal_id' => $worker, 'puesto_asignado' => 'TECNICO',
            'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31', 'estado' => 'ASIGNADO']);
        DB::table('grupo_trabajo')->insert(['id' => (string) Str::uuid(), 'rq_mina_id' => $rq, 'fecha' => '2026-10-06',
            'supervisor_id' => $worker, 'mina' => 'AUDIT SCOPE MINE', 'unidad' => 'MINA', 'destino_tipo' => 'MINA', 'destino_id' => $mine,
            'servicio' => 'AUDIT', 'area' => 'AUDIT', 'horario_salida' => '07:00:00', 'turno' => 'DIA',
            'estado' => 'PROGRAMADO', 'created_by_id' => $user->id]);
        DB::table('faltas')->insert(['id' => (string) Str::uuid(), 'trabajador_id' => $worker, 'fecha' => '2026-10-06',
            'motivo' => 'INASISTENCIA_ASISTENCIA', 'estado' => 'ACTIVA', 'registrada_por_id' => $worker,
            'destino_tipo' => 'MINA', 'destino_id' => $mine]);

        return $mine;
    }
}
