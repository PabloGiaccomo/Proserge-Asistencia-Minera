<?php

namespace Tests\Feature;

use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\RQMina;
use App\Models\RQProserge;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalNotificationServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_contrato_decision_notifica_solo_a_usuarios_con_permiso_personal_actualizar(): void
    {
        $this->ensureNotificationType([
            'code' => 'contrato_decision_renovacion',
            'module' => 'personal',
            'category' => 'operacion',
            'default_priority' => 'high',
            'required_permission_module' => 'personal',
            'required_permission_action' => 'actualizar',
            'default_title' => 'Decision de renovacion registrada',
            'default_action_label' => 'Ver vencimientos',
            'default_action_route' => '/personal/contratos/vencimientos',
        ]);

        $actorId = $this->createUser($this->createRole('ACTOR_CONTRATOS_' . Str::upper(Str::random(6)), [
            'personal' => ['actualizar'],
            'notificaciones' => ['ver'],
        ]), 'actor-contratos');
        $recipientId = $this->createUser($this->createRole('RRHH_CONTRATOS_' . Str::upper(Str::random(6)), [
            'personal' => ['actualizar'],
            'notificaciones' => ['ver'],
        ]), 'rrhh-contratos');
        $deniedId = $this->createUser($this->createRole('SOLO_LECTURA_' . Str::upper(Str::random(6)), [
            'personal' => ['ver'],
            'notificaciones' => ['ver'],
        ]), 'solo-lectura');

        $personal = $this->createPersonal('Trabajador decision contrato');
        $contract = PersonalContrato::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'contrato_numero' => 1,
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'fecha_inicio' => now()->subMonth()->toDateString(),
            'fecha_fin' => now()->addMonth()->toDateString(),
            'decision_final' => PersonalContrato::DECISION_RENOVAR,
            'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
            'fecha_decision' => now(),
            'usuario_decision_id' => $actorId,
        ]);

        app(OperationalNotificationService::class)->contratoDecision($contract->fresh(['personal']), Usuario::query()->findOrFail($actorId));

        $eventId = DB::table('notification_events')
            ->join('notification_types', 'notification_types.id', '=', 'notification_events.notification_type_id')
            ->where('notification_types.code', 'contrato_decision_renovacion')
            ->where('notification_events.entity_id', $contract->id)
            ->value('notification_events.id');

        $this->assertNotNull($eventId);
        $this->assertDatabaseHas('notification_recipients', [
            'notification_event_id' => $eventId,
            'usuario_id' => $recipientId,
        ]);
        $this->assertDatabaseMissing('notification_recipients', [
            'notification_event_id' => $eventId,
            'usuario_id' => $actorId,
        ]);
        $this->assertDatabaseMissing('notification_recipients', [
            'notification_event_id' => $eventId,
            'usuario_id' => $deniedId,
        ]);
    }

    public function test_rq_mina_pedido_modificado_respeta_permiso_y_scope_de_mina(): void
    {
        $this->ensureNotificationType([
            'code' => 'rq_mina_pedido_modificado',
            'module' => 'rq_proserge',
            'category' => 'accion_requerida',
            'default_priority' => 'high',
            'required_permission_module' => 'rq_proserge',
            'required_permission_action' => 'asignar',
            'default_title' => 'Pedido de RQ Mina modificado',
            'default_action_label' => 'Revisar RQ Proserge',
            'default_action_route' => '/rq-proserge/{entity_id}',
        ]);

        $actorId = $this->createUser($this->createRole('PLANNER_RQ_' . Str::upper(Str::random(6)), [
            'rq_mina' => ['actualizar'],
            'notificaciones' => ['ver'],
        ]), 'planner-rq');
        $recipientId = $this->createUser($this->createRole('RRHH_RQ_' . Str::upper(Str::random(6)), [
            'rq_proserge' => ['asignar'],
            'notificaciones' => ['ver'],
        ]), 'rrhh-rq');
        $noScopeId = $this->createUser($this->createRole('RRHH_SIN_MINA_' . Str::upper(Str::random(6)), [
            'rq_proserge' => ['asignar'],
            'notificaciones' => ['ver'],
        ]), 'rrhh-sin-mina');

        $mine = Mina::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => 'MINA NOTIFICACION ' . Str::upper(Str::random(5)),
            'unidad_minera' => 'UNIDAD TEST',
            'estado' => 'ACTIVO',
        ]);

        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $recipientId,
            'mina_id' => $mine->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rqMina = RQMina::query()->create([
            'id' => (string) Str::uuid(),
            'mina_id' => $mine->id,
            'destino_tipo' => 'MINA',
            'destino_id' => $mine->id,
            'destino_nombre' => $mine->nombre,
            'area' => 'Parada prueba',
            'fecha_inicio' => now()->addWeek()->toDateString(),
            'fecha_fin' => now()->addWeeks(2)->toDateString(),
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $actorId,
            'enviado_at' => now(),
        ]);
        $rqProserge = RQProserge::query()->create([
            'id' => (string) Str::uuid(),
            'rq_mina_id' => $rqMina->id,
            'mina_id' => $mine->id,
            'responsable_rrhh_id' => $recipientId,
            'estado' => 'PENDIENTE',
        ]);

        app(OperationalNotificationService::class)->rqMinaPedidoModificado(
            $rqMina->fresh(['mina']),
            $rqProserge,
            Usuario::query()->findOrFail($actorId),
            [[
                'id' => (string) Str::uuid(),
                'tipo' => 'CANTIDAD_AUMENTADA',
                'puesto' => 'Mecanico',
                'mensaje' => 'Aumento la cantidad solicitada para Mecanico.',
            ]]
        );

        $eventId = DB::table('notification_events')
            ->join('notification_types', 'notification_types.id', '=', 'notification_events.notification_type_id')
            ->where('notification_types.code', 'rq_mina_pedido_modificado')
            ->where('notification_events.entity_id', $rqProserge->id)
            ->value('notification_events.id');

        $this->assertNotNull($eventId);
        $this->assertDatabaseHas('notification_recipients', [
            'notification_event_id' => $eventId,
            'usuario_id' => $recipientId,
        ]);
        $this->assertDatabaseMissing('notification_recipients', [
            'notification_event_id' => $eventId,
            'usuario_id' => $noScopeId,
        ]);
        $this->assertDatabaseMissing('notification_recipients', [
            'notification_event_id' => $eventId,
            'usuario_id' => $actorId,
        ]);
    }

    private function createRole(string $name, array $permissions): string
    {
        $id = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $id,
            'nombre' => $name,
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createUser(string $roleId, string $prefix): string
    {
        $id = (string) Str::uuid();

        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => $prefix . '-' . Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => null,
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createPersonal(string $name): Personal
    {
        return Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => $name,
            'puesto' => 'Operario',
            'qr_code' => 'QR-' . Str::upper(Str::random(8)),
            'estado' => 'ACTIVO',
        ]);
    }

    private function ensureNotificationType(array $type): void
    {
        DB::table('notification_types')->updateOrInsert(
            ['code' => $type['code']],
            [
                'id' => DB::table('notification_types')->where('code', $type['code'])->value('id') ?? (string) Str::uuid(),
                'module' => $type['module'],
                'category' => $type['category'],
                'default_priority' => $type['default_priority'],
                'required_permission_module' => $type['required_permission_module'],
                'required_permission_action' => $type['required_permission_action'],
                'default_title' => $type['default_title'],
                'default_action_label' => $type['default_action_label'],
                'default_action_route' => $type['default_action_route'],
                'is_active' => true,
                'created_at' => DB::table('notification_types')->where('code', $type['code'])->value('created_at') ?? now(),
                'updated_at' => now(),
            ],
        );
    }
}
