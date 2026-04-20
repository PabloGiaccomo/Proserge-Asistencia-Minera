<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RQMinaApiTest extends TestCase
{
    use DatabaseTransactions;

    private string $adminRoleId;

    private string $userRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRoleId = (string) Str::uuid();
        $this->userRoleId = (string) Str::uuid();

        DB::table('roles')->insert([
            [
                'id' => $this->adminRoleId,
                'nombre' => 'ADMIN',
                'permisos' => json_encode(['rq_mina.read', 'rq_mina.write']),
                'estado' => 'ACTIVO',
            ],
            [
                'id' => $this->userRoleId,
                'nombre' => 'PLANNER',
                'permisos' => json_encode(['rq_mina.read', 'rq_mina.write']),
                'estado' => 'ACTIVO',
            ],
        ]);
    }

    public function test_crea_rq_mina_en_borrador(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/rq-mina', [
            'mina_id' => $minaId,
            'area' => 'Mantenimiento',
            'fecha_inicio' => '2026-04-10',
            'fecha_fin' => '2026-04-14',
            'observaciones' => 'Solicitud inicial',
            'detalle' => [
                ['puesto' => 'Tecnico', 'cantidad' => 2],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.estado', 'BORRADOR');
    }

    public function test_no_deja_editar_rq_mina_cerrado(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);
        $rqId = $this->createRQMina($minaId, $usuarioId, 'CERRADO');

        $response = $this->withToken($token)->putJson('/api/v1/rq-mina/'.$rqId, [
            'mina_id' => $minaId,
            'area' => 'Area editada',
            'fecha_inicio' => '2026-04-10',
            'fecha_fin' => '2026-04-15',
            'observaciones' => null,
            'detalle' => [
                ['puesto' => 'Soldador', 'cantidad' => 1],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'RQ_MINA_NOT_EDITABLE');
    }

    public function test_usuario_sin_scope_no_puede_ver_rq_mina(): void
    {
        $minaId = $this->createMina();
        $creadorId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($creadorId, $minaId);
        $rqId = $this->createRQMina($minaId, $creadorId, 'BORRADOR');

        $otroUsuarioId = $this->createUsuario($this->userRoleId);
        $token = $this->createToken($otroUsuarioId);

        $response = $this->withToken($token)->getJson('/api/v1/rq-mina/'.$rqId);

        $response->assertStatus(404)
            ->assertJsonPath('code', 'RQ_MINA_NOT_FOUND');
    }

    public function test_creador_puede_editar_rq_mina_en_borrador(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);
        $rqId = $this->createRQMina($minaId, $usuarioId, 'BORRADOR');

        $response = $this->withToken($token)->putJson('/api/v1/rq-mina/'.$rqId, [
            'mina_id' => $minaId,
            'area' => 'Area actualizada',
            'fecha_inicio' => '2026-04-11',
            'fecha_fin' => '2026-04-16',
            'observaciones' => 'Actualizacion creador',
            'detalle' => [
                ['puesto' => 'Electricista', 'cantidad' => 3],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('code', 'RQ_MINA_UPDATE_OK')
            ->assertJsonPath('data.area', 'Area actualizada');
    }

    public function test_otro_usuario_sin_privilegios_no_puede_editar(): void
    {
        $minaId = $this->createMina();
        $creadorId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($creadorId, $minaId);
        $rqId = $this->createRQMina($minaId, $creadorId, 'BORRADOR');

        $otroUsuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($otroUsuarioId, $minaId);
        $token = $this->createToken($otroUsuarioId);

        $response = $this->withToken($token)->putJson('/api/v1/rq-mina/'.$rqId, [
            'mina_id' => $minaId,
            'area' => 'Intento no autorizado',
            'fecha_inicio' => '2026-04-11',
            'fecha_fin' => '2026-04-16',
            'observaciones' => null,
            'detalle' => [
                ['puesto' => 'Tecnico', 'cantidad' => 1],
            ],
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'RQ_MINA_FORBIDDEN_EDIT');
    }

    public function test_enviar_cambia_estado_de_borrador_a_enviado(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);
        $rqId = $this->createRQMina($minaId, $usuarioId, 'BORRADOR');

        $response = $this->withToken($token)->postJson('/api/v1/rq-mina/'.$rqId.'/enviar', []);

        $response->assertStatus(200)
            ->assertJsonPath('code', 'RQ_MINA_SEND_OK')
            ->assertJsonPath('data.estado', 'ENVIADO');
    }

    private function createMina(): string
    {
        $id = (string) Str::uuid();

        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => 'Mina '.Str::upper(Str::random(4)),
            'unidad_minera' => 'UM '.Str::upper(Str::random(3)),
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createUsuario(string $rolId): string
    {
        $id = (string) Str::uuid();

        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $rolId,
            'personal_id' => null,
        ]);

        return $id;
    }

    private function assignMinaScope(string $usuarioId, string $minaId): void
    {
        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuarioId,
            'mina_id' => $minaId,
        ]);
    }

    private function createToken(string $usuarioId): string
    {
        $plain = Str::random(80);

        DB::table('auth_tokens')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuarioId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plain;
    }

    private function createRQMina(string $minaId, string $creadorId, string $estado): string
    {
        $id = (string) Str::uuid();

        DB::table('rq_mina')->insert([
            'id' => $id,
            'mina_id' => $minaId,
            'area' => 'Area base',
            'fecha_inicio' => '2026-04-10',
            'fecha_fin' => '2026-04-12',
            'observaciones' => null,
            'estado' => $estado,
            'created_by_usuario_id' => $creadorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_detalle')->insert([
            'id' => (string) Str::uuid(),
            'rq_mina_id' => $id,
            'puesto' => 'Tecnico',
            'cantidad' => 1,
            'cantidad_atendida' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
