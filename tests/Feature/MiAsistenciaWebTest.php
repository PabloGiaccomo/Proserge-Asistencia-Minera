<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use App\Models\GrupoTrabajo;
use App\Modules\Asistencia\Services\AsistenciaService;
use Carbon\CarbonImmutable;

class MiAsistenciaWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'America/Lima']);
        $this->travelTo(CarbonImmutable::parse('2026-08-19 06:59:00', 'America/Lima'));
    }

    public function test_horario_marca_solo_pendientes_y_conserva_marcas_existentes(): void
    {
        [$group, $user, $detail] = $this->horarioScenario();
        $this->postMark($group, $user, $detail, ['estado' => 'PRESENTE'])->assertOk();
        $before = DB::table('asistencia_detalle')->where('grupo_trabajo_detalle_id', $detail)->first();
        $this->withSession($this->sessionFor($user))->getJson(route('mi-asistencia.estado', $group))
            ->assertOk()->assertJsonFragment(['estado' => 'PENDIENTE', 'hora' => null]);
        $this->travelTo(CarbonImmutable::parse('2026-08-19 07:00:01', 'America/Lima'));
        $service = app(AsistenciaService::class);
        $this->assertSame(1, $service->marcarAusentesPorHorario(GrupoTrabajo::findOrFail($group)));
        $this->assertSame(0, $service->marcarAusentesPorHorario(GrupoTrabajo::findOrFail($group)));
        $this->assertEquals($before, DB::table('asistencia_detalle')->where('grupo_trabajo_detalle_id', $detail)->first());
        $this->assertDatabaseHas('asistencia_detalle', ['estado' => 'AUSENTE', 'origen_registro' => 'SISTEMA', 'marcado_por_id' => null, 'motivo_estado' => 'Ausencia automatica por horario vencido']);
        $this->assertDatabaseCount('faltas', 0);
    }

    public function test_rechaza_presente_individual_y_todos_despues_del_horario(): void
    {
        [$group, $user, $detail] = $this->horarioScenario();
        $this->travelTo(CarbonImmutable::parse('2026-08-19 07:01:00', 'America/Lima'));
        $this->postMark($group, $user, $detail, ['estado' => 'PRESENTE', 'hora_marcado' => '06:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->withSession($this->sessionFor($user))->postJson(route('mi-asistencia.marcar-todos', $group))
            ->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->assertDatabaseMissing('asistencia_detalle', ['estado' => 'PRESENTE']);
    }

    public function test_ausente_automatico_se_corrige_a_tardanza_o_justificado(): void
    {
        [$group, $user, $detail] = $this->horarioScenario();
        $this->travelTo(CarbonImmutable::parse('2026-08-19 08:30:00', 'America/Lima'));
        $this->postMark($group, $user, $detail, ['estado' => 'TARDANZA'])
            ->assertOk()->assertJsonPath('estado', 'TARDANZA')->assertJsonPath('hora_marcado', '08:30:00');
        $this->postMark($group, $user, $detail, ['estado' => 'JUSTIFICADO'])->assertStatus(422);
        $this->postMark($group, $user, $detail, ['estado' => 'JUSTIFICADO', 'observaciones' => 'Motivo de prueba'])
            ->assertOk()->assertJsonPath('estado', 'JUSTIFICADO');
        app(AsistenciaService::class)->marcarAusentesPorHorario(GrupoTrabajo::findOrFail($group));
        $this->assertDatabaseHas('asistencia_detalle', ['grupo_trabajo_detalle_id' => $detail, 'estado' => 'JUSTIFICADO', 'observaciones' => 'Motivo de prueba', 'marcado_por_id' => $user]);
    }

    public function test_lector_registra_tardanza_y_no_reescribe_una_llegada_repetida(): void
    {
        [$group, $user, $detail] = $this->horarioScenario();
        $this->travelTo(CarbonImmutable::parse('2026-08-19 08:30:00', 'America/Lima'));
        $this->postMark($group, $user, $detail, ['estado' => 'PRESENTE', 'registrar_llegada' => true])
            ->assertOk()->assertJsonPath('estado', 'TARDANZA');
        $before = DB::table('asistencia_detalle')->where('grupo_trabajo_detalle_id', $detail)->first();
        $this->travelTo(CarbonImmutable::parse('2026-08-19 09:30:00', 'America/Lima'));
        $this->postMark($group, $user, $detail, ['estado' => 'PRESENTE', 'registrar_llegada' => true])
            ->assertOk()->assertJsonPath('hora_marcado', '08:30:00');
        $this->assertEquals($before, DB::table('asistencia_detalle')->where('grupo_trabajo_detalle_id', $detail)->first());
    }

    public function test_horario_exacto_y_turno_noche_respetan_la_fecha_local(): void
    {
        [$group, $user, $detail] = $this->horarioScenario();
        DB::table('grupo_trabajo')->where('id', $group)->update(['horario_salida' => '19:00:00', 'turno' => 'NOCHE']);
        $this->travelTo(CarbonImmutable::parse('2026-08-19 19:00:00', 'America/Lima'));
        $this->postMark($group, $user, $detail, ['estado' => 'PRESENTE'])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-08-19 19:00:01', 'America/Lima'));
        $this->postMark($group, $user, $detail, ['estado' => 'PRESENTE'])->assertStatus(422);
        $this->assertSame(0, app(AsistenciaService::class)->marcarAusentesPorHorario(GrupoTrabajo::findOrFail($group)));
    }

    public function test_barrido_no_modifica_grupos_historicos_futuros_cancelados_o_cerrados(): void
    {
        [$group, $user, $detail] = $this->horarioScenario();
        $service = app(AsistenciaService::class);
        foreach (['2026-08-18', '2026-08-20'] as $fecha) {
            DB::table('grupo_trabajo')->where('id', $group)->update(['fecha' => $fecha]);
            $this->assertSame(0, $service->marcarAusentesPorHorario(GrupoTrabajo::findOrFail($group)));
        }
        DB::table('grupo_trabajo')->where('id', $group)->update(['fecha' => '2026-08-19', 'estado' => 'CANCELADO']);
        $this->travelTo(CarbonImmutable::parse('2026-08-19 08:00:00', 'America/Lima'));
        $this->assertSame(0, $service->marcarAusentesPorHorario(GrupoTrabajo::findOrFail($group)));
        $this->assertDatabaseCount('asistencia_encabezado', 0);
        DB::table('grupo_trabajo')->where('id', $group)->update(['estado' => 'PROGRAMADO']);
        $this->postMark($group, $user, $detail, ['estado' => 'TARDANZA'])->assertOk();
        DB::table('asistencia_encabezado')->where('grupo_trabajo_id', $group)->update(['estado' => 'CERRADO']);
        $before = DB::table('asistencia_detalle')->get();
        $this->assertSame(0, $service->marcarAusentesPorHorario(GrupoTrabajo::findOrFail($group)));
        $this->assertEquals($before, DB::table('asistencia_detalle')->get());
    }

    public function test_estado_refresca_ausencias_y_el_comando_es_idempotente(): void
    {
        [$group, $user, $detail] = $this->horarioScenario();
        $this->withSession($this->sessionFor($user))->getJson(route('mi-asistencia.estado', $group))
            ->assertOk()->assertJsonPath('horario.permite_presente', true)->assertJsonPath('integrantes.0.estado', 'PENDIENTE');
        $this->travelTo(CarbonImmutable::parse('2026-08-19 07:00:01', 'America/Lima'));
        $this->artisan('asistencia:ausentes-por-horario')->assertSuccessful();
        $before = DB::table('asistencia_detalle')->get();
        $this->artisan('asistencia:ausentes-por-horario')->expectsOutput('Ausencias registradas: 0. Grupos con errores: 0.')->assertSuccessful();
        $this->assertEquals($before, DB::table('asistencia_detalle')->get());
        $this->withSession($this->sessionFor($user))->getJson(route('mi-asistencia.estado', $group))
            ->assertOk()->assertJsonPath('horario.permite_presente', false)->assertJsonPath('integrantes.0.estado', 'AUSENTE');
    }

    private function horarioScenario(): array
    {
        $role = $this->createRole(['mi_asistencia' => ['ver']]);
        $supervisor = $this->createPersonal('RESPONSABLE PRUEBA HORARIO');
        $user = $this->createUser($role, $supervisor);
        $mina = $this->createMine();
        $rq = $this->createRqMina($mina, $user);
        $group = $this->createGroup($rq, $mina, $user, $supervisor, 'PRUEBA HORARIO');
        $detail = $this->addMember($group, $this->createPersonal('TRABAJADOR PRUEBA HORARIO'));
        return [$group, $user, $detail];
    }

    private function postMark(string $group, string $user, string $detail, array $payload)
    {
        return $this->withSession($this->sessionFor($user))->postJson(route('mi-asistencia.marcar', $group), [
            'grupo_trabajo_detalle_id' => $detail,
            ...$payload,
        ]);
    }

    public function test_responsable_solo_ve_sus_grupos(): void
    {
        $roleId = $this->createRole(['mi_asistencia' => ['ver']]);
        $supervisorId = $this->createPersonal('RESPONSABLE PROPIO');
        $otherSupervisorId = $this->createPersonal('RESPONSABLE AJENO');
        $userId = $this->createUser($roleId, $supervisorId);
        $minaId = $this->createMine();
        $rqMinaId = $this->createRqMina($minaId, $userId);
        $ownGroupId = $this->createGroup($rqMinaId, $minaId, $userId, $supervisorId, 'SERVICIO PROPIO');
        $otherGroupId = $this->createGroup($rqMinaId, $minaId, $userId, $otherSupervisorId, 'SERVICIO AJENO');

        $this->withSession($this->sessionFor($userId))
            ->get(route('mi-asistencia.index', ['fecha' => '2026-08-19']))
            ->assertOk()
            ->assertSee('SERVICIO PROPIO')
            ->assertDontSee('SERVICIO AJENO');

        $this->withSession($this->sessionFor($userId))
            ->get(route('mi-asistencia.show', $ownGroupId))
            ->assertOk();

        $this->withSession($this->sessionFor($userId))
            ->get(route('mi-asistencia.show', $otherGroupId))
            ->assertNotFound();

        $this->withSession($this->sessionFor($userId))
            ->getJson(route('mi-asistencia.estado', $otherGroupId))
            ->assertNotFound();
    }

    public function test_permiso_ver_todas_muestra_grupos_del_alcance(): void
    {
        $roleId = $this->createRole(['mi_asistencia' => ['ver', 'ver_todas_asistencias']]);
        $supervisorId = $this->createPersonal('SUPERVISOR UNO');
        $otherSupervisorId = $this->createPersonal('SUPERVISOR DOS');
        $userId = $this->createUser($roleId, $supervisorId);
        $minaId = $this->createMine();
        $rqMinaId = $this->createRqMina($minaId, $userId);
        $this->assignMineScope($userId, $minaId);
        $this->createGroup($rqMinaId, $minaId, $userId, $supervisorId, 'SERVICIO TURNO DIA', 'DIA');
        $this->createGroup($rqMinaId, $minaId, $userId, $otherSupervisorId, 'SERVICIO TURNO NOCHE', 'NOCHE');

        $this->withSession($this->sessionFor($userId))
            ->get(route('mi-asistencia.index', ['fecha' => '2026-08-19']))
            ->assertOk()
            ->assertSee('Todas las asistencias')
            ->assertSee('SERVICIO TURNO DIA')
            ->assertSee('SERVICIO TURNO NOCHE');
    }

    public function test_responsable_puede_marcar_su_grupo_sin_permiso_global(): void
    {
        $roleId = $this->createRole(['mi_asistencia' => ['ver']]);
        $supervisorId = $this->createPersonal('RESPONSABLE MARCADOR');
        $workerId = $this->createPersonal('TRABAJADOR DEL GRUPO');
        $userId = $this->createUser($roleId, $supervisorId);
        $minaId = $this->createMine();
        $rqMinaId = $this->createRqMina($minaId, $userId);
        $groupId = $this->createGroup($rqMinaId, $minaId, $userId, $supervisorId, 'SERVICIO ASISTENCIA');
        $detailId = $this->addMember($groupId, $workerId);

        $this->withSession([...$this->sessionFor($userId), '_token' => 'mi-asistencia-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'mi-asistencia-csrf')
            ->postJson(route('mi-asistencia.marcar', $groupId), [
                'grupo_trabajo_detalle_id' => $detailId,
                'estado' => 'PRESENTE',
                'hora_marcado' => '07:05',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('asistencia_detalle', [
            'grupo_trabajo_detalle_id' => $detailId,
            'trabajador_id' => $workerId,
            'estado' => 'PRESENTE',
            'marcado_por_id' => $userId,
        ]);
    }

    public function test_catalogo_expone_permiso_para_ver_todas_las_asistencias(): void
    {
        $actions = PermissionCatalog::availableModuleActions();

        $this->assertContains('ver_todas_asistencias', $actions['mi_asistencia']);
        $this->assertSame('Ver y registrar todas las asistencias', PermissionCatalog::actionLabel('ver_todas_asistencias'));
    }

    public function test_usuario_global_registra_grupo_sin_responsable_con_su_identidad(): void
    {
        $roleId = $this->createRole([
            'mi_asistencia' => ['ver', 'ver_todas_asistencias'],
        ]);
        $personalId = $this->createPersonal('USUARIO ASISTENCIA GLOBAL');
        $workerId = $this->createPersonal('TRABAJADOR SIN RESPONSABLE');
        $userId = $this->createUser($roleId, $personalId);
        $minaId = $this->createMine();
        $rqMinaId = $this->createRqMina($minaId, $userId);
        $this->assignMineScope($userId, $minaId);
        $groupId = $this->createGroup($rqMinaId, $minaId, $userId, null, 'SERVICIO SIN RESPONSABLE');
        $detailId = $this->addMember($groupId, $workerId);

        $this->withSession([...$this->sessionFor($userId), '_token' => 'mi-asistencia-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'mi-asistencia-csrf')
            ->postJson(route('mi-asistencia.marcar', $groupId), [
                'grupo_trabajo_detalle_id' => $detailId,
                'estado' => 'PRESENTE',
                'hora_marcado' => '07:05',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('asistencia_encabezado', [
            'grupo_trabajo_id' => $groupId,
            'supervisor_id' => $personalId,
        ]);
        $this->assertDatabaseHas('grupo_trabajo', [
            'id' => $groupId,
            'supervisor_id' => null,
        ]);
    }

    public function test_usuario_global_cierra_grupo_sin_responsable_y_registra_falta_con_su_identidad(): void
    {
        $roleId = $this->createRole([
            'mi_asistencia' => ['ver', 'ver_todas_asistencias'],
        ]);
        $personalId = $this->createPersonal('USUARIO CIERRE GLOBAL');
        $workerId = $this->createPersonal('TRABAJADOR AUSENTE SIN RESPONSABLE');
        $userId = $this->createUser($roleId, $personalId);
        $minaId = $this->createMine();
        $rqMinaId = $this->createRqMina($minaId, $userId);
        $this->assignMineScope($userId, $minaId);
        $groupId = $this->createGroup($rqMinaId, $minaId, $userId, null, 'CIERRE SIN RESPONSABLE');
        $this->addMember($groupId, $workerId);

        $this->withSession([...$this->sessionFor($userId), '_token' => 'mi-asistencia-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'mi-asistencia-csrf')
            ->postJson(route('mi-asistencia.cerrar', $groupId))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('faltas', [
            'trabajador_id' => $workerId,
            'motivo' => 'INASISTENCIA_ASISTENCIA',
            'estado' => 'ACTIVA',
            'registrada_por_id' => $personalId,
        ]);
    }

    public function test_lector_dni_distingue_integrante_personal_externo_y_documento_desconocido(): void
    {
        $roleId = $this->createRole(['mi_asistencia' => ['ver']]);
        $supervisorId = $this->createPersonal('RESPONSABLE DEL LECTOR');
        $memberId = $this->createPersonal('INTEGRANTE DEL LECTOR');
        $outsideId = $this->createPersonal('PERSONAL FUERA DEL GRUPO');
        $memberDni = (string) DB::table('personal')->where('id', $memberId)->value('dni');
        $outsideDni = (string) DB::table('personal')->where('id', $outsideId)->value('dni');
        $userId = $this->createUser($roleId, $supervisorId);
        $minaId = $this->createMine();
        $rqMinaId = $this->createRqMina($minaId, $userId);
        $groupId = $this->createGroup($rqMinaId, $minaId, $userId, $supervisorId, 'SERVICIO CON LECTOR');
        $detailId = $this->addMember($groupId, $memberId);
        $session = $this->sessionFor($userId);

        $this->withSession($session)
            ->getJson(route('mi-asistencia.buscar-dni', ['id' => $groupId, 'codigo' => $memberDni]))
            ->assertOk()
            ->assertJsonPath('reconocido', true)
            ->assertJsonPath('pertenece_grupo', true)
            ->assertJsonPath('grupo_trabajo_detalle_id', $detailId)
            ->assertJsonPath('personal.nombre', 'INTEGRANTE DEL LECTOR');

        $this->withSession($session)
            ->getJson(route('mi-asistencia.buscar-dni', ['id' => $groupId, 'codigo' => $outsideDni]))
            ->assertOk()
            ->assertJsonPath('reconocido', true)
            ->assertJsonPath('pertenece_grupo', false)
            ->assertJsonPath('personal.nombre', 'PERSONAL FUERA DEL GRUPO');

        $this->withSession($session)
            ->getJson(route('mi-asistencia.buscar-dni', ['id' => $groupId, 'codigo' => '00000000']))
            ->assertOk()
            ->assertJsonPath('reconocido', false)
            ->assertJsonPath('pertenece_grupo', false)
            ->assertJsonPath('dni', '00000000');
    }

    public function test_vista_de_asistencia_expone_lector_con_busqueda_segura(): void
    {
        $roleId = $this->createRole(['mi_asistencia' => ['ver']]);
        $supervisorId = $this->createPersonal('RESPONSABLE VISTA LECTOR');
        $userId = $this->createUser($roleId, $supervisorId);
        $minaId = $this->createMine();
        $rqMinaId = $this->createRqMina($minaId, $userId);
        $groupId = $this->createGroup($rqMinaId, $minaId, $userId, $supervisorId, 'VISTA LECTOR');
        $this->addMember($groupId, $this->createPersonal('PERSONAL PARA DESPLEGAR ACCIONES'));

        $this->withSession($this->sessionFor($userId))
            ->get(route('mi-asistencia.show', $groupId))
            ->assertOk()
            ->assertSee('data-dni-lookup-url=', false)
            ->assertSee('loadProsergeBarcodeScanner', false)
            ->assertSee('ProsergeBarcodeScanner', false)
            ->assertSee('BarcodeFormat.PDF_417', false)
            ->assertSee('miaBarcodePhotoInput', false)
            ->assertSee('capture="environment"', false)
            ->assertSee('decodeFromImageElement', false)
            ->assertSee('video.playsInline = true', false)
            ->assertSee('data-toggle-worker aria-expanded="false" aria-controls="miaWorkerPanel-0"', false)
            ->assertSee('id="miaWorkerPanel-0" data-worker-panel hidden', false)
            ->assertSee('aria-label="Presente"', false)
            ->assertSee('aria-label="Justificado"', false)
            ->assertSee('Lecturas fuera del grupo')
            ->assertSee('miaOpenCamera', false);
    }

    private function createRole(array $permissions): string
    {
        $id = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $id,
            'nombre' => 'ASISTENCIA_' . Str::upper(Str::random(8)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createUser(string $roleId, string $personalId): string
    {
        $id = (string) Str::uuid();
        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => Str::lower(Str::random(10)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => $personalId,
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createPersonal(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => $name,
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createMine(): string
    {
        $id = (string) Str::uuid();
        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => 'MINA ' . Str::upper(Str::random(6)),
            'unidad_minera' => 'UM TEST',
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createRqMina(string $minaId, string $userId): string
    {
        $id = (string) Str::uuid();
        DB::table('rq_mina')->insert([
            'id' => $id,
            'mina_id' => $minaId,
            'area' => 'OPERACIONES',
            'fecha_inicio' => '2026-08-19',
            'fecha_fin' => '2026-08-25',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createGroup(
        string $rqMinaId,
        string $minaId,
        string $userId,
        ?string $supervisorId,
        string $service,
        string $turno = 'DIA',
    ): string {
        $id = (string) Str::uuid();
        DB::table('grupo_trabajo')->insert([
            'id' => $id,
            'fecha' => '2026-08-19',
            'supervisor_id' => $supervisorId,
            'mina' => 'MINA TEST',
            'unidad' => 'MINA',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'rq_mina_id' => $rqMinaId,
            'servicio' => $service,
            'area' => 'OPERACIONES',
            'horario_salida' => $turno === 'DIA' ? '07:00:00' : '19:00:00',
            'turno' => $turno,
            'estado' => 'PROGRAMADO',
            'created_by_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($supervisorId) {
            $this->addMember($id, $supervisorId);
        }

        return $id;
    }

    private function addMember(string $groupId, string $personalId): string
    {
        $id = (string) Str::uuid();
        DB::table('grupo_trabajo_detalle')->insert([
            'id' => $id,
            'grupo_trabajo_id' => $groupId,
            'personal_id' => $personalId,
            'puesto_asignado_snapshot' => 'OPERARIO',
            'estado_distribucion' => 'ASIGNADO',
            'estado_asistencia' => 'AUSENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function assignMineScope(string $userId, string $minaId): void
    {
        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $userId,
            'mina_id' => $minaId,
        ]);
    }

    private function sessionFor(string $userId): array
    {
        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'mi-asistencia@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
