<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LogisticaEppIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_logistica_entregas_renderiza_la_vista_operativa_de_epp(): void
    {
        $session = $this->sessionWithEppPermissions();

        $response = $this
            ->withSession($session)
            ->get(route('logistica.index', ['tab' => 'entregas']));

        $response
            ->assertOk()
            ->assertSee('Entregas y cambios de EPP')
            ->assertSee('Seguimiento de EPP')
            ->assertSee('Registrar entrega')
            ->assertSee('Catalogo de EPP');
    }

    public function test_epps_index_redirige_a_logistica_entregas(): void
    {
        $session = $this->sessionWithEppPermissions();

        $response = $this
            ->withSession($session)
            ->get(route('epps.index', ['estado' => 'ENTREGADO']));

        $response->assertRedirect(route('logistica.index', [
            'tab' => 'entregas',
            'estado' => 'ENTREGADO',
        ]));
    }

    private function sessionWithEppPermissions(): array
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $permissions = PermissionCatalog::matrixFromSelections([
            'epps' => ['ver', 'actualizar'],
        ]);

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'LOGISTICA_EPP_TEST',
            'permisos' => json_encode($permissions),
            'estado' => 'ACTIVO',
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => null,
        ]);

        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'logistica-epp@test.local',
                'permissions' => $permissions,
            ],
        ];
    }
}
