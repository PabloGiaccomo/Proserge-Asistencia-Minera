<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebPermissionMiddlewareTest extends TestCase
{
    use DatabaseTransactions;

    public function test_operational_module_permission_implies_view_permission(): void
    {
        $permissions = PermissionMatrix::normalize([
            'rq_mina' => [
                'crear' => true,
                'ver' => false,
            ],
        ]);

        $this->assertTrue($permissions['rq_mina']['crear']);
        $this->assertTrue($permissions['rq_mina']['ver']);
    }

    public function test_permission_middleware_refreshes_stale_session_permissions(): void
    {
        Route::post('/__test/web-permission-refresh', fn () => response()->json(['ok' => true]))
            ->middleware(['web', 'web.permission:rq_mina,crear']);

        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'PLANNER_TEST',
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                'rq_mina' => ['crear'],
            ])),
            'estado' => 'ACTIVO',
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => null,
        ]);

        $response = $this
            ->withSession([
                'auth_token' => 'test-token',
                'user_id' => $userId,
                'user' => [
                    'id' => $userId,
                    'email' => 'planner@test.local',
                    'permissions' => PermissionCatalog::emptyMatrix(),
                ],
            ])
            ->postJson('/__test/web-permission-refresh');

        $response->assertOk()->assertJsonPath('ok', true);
    }
}
