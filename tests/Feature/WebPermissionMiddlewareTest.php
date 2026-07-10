<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;
use App\Modules\Seguridad\Services\AdminProtectionService;
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

    public function test_permission_catalog_includes_sidebar_modules_without_legacy_duplicates(): void
    {
        $modules = PermissionCatalog::availableModules();
        $moduleActions = PermissionCatalog::availableModuleActions();

        foreach ([
            'inicio',
            'personal',
            'vencimientos',
            'habilitacion_minera',
            'bienestar',
            'rq_mina',
            'rq_proserge',
            'man_power',
            'logistica',
            'mi_asistencia',
            'evaluaciones',
            'asistencias',
            'faltas',
            'catalogos',
            'usuarios',
            'roles',
        ] as $module) {
            $this->assertArrayHasKey($module, $modules);
            $this->assertContains('ver', $moduleActions[$module] ?? []);
        }

        $this->assertArrayNotHasKey('personal_vencimientos', $modules);
    }

    public function test_canonical_permissions_keep_legacy_module_access(): void
    {
        $legacyLogistica = PermissionCatalog::matrixFromSelections([
            'epps' => ['ver', 'actualizar'],
        ]);
        $this->assertTrue(PermissionMatrix::allows($legacyLogistica, 'logistica', 'ver'));
        $this->assertTrue(PermissionMatrix::allows($legacyLogistica, 'logistica', 'actualizar'));
        $this->assertFalse(PermissionMatrix::allows($legacyLogistica, 'herramientas', 'ver'));

        $canonicalLogistica = PermissionCatalog::matrixFromSelections([
            'logistica' => ['ver', 'actualizar'],
        ]);
        $this->assertTrue(PermissionMatrix::allows($canonicalLogistica, 'epps', 'ver'));
        $this->assertTrue(PermissionMatrix::allows($canonicalLogistica, 'herramientas', 'actualizar'));

        $legacyVencimientos = PermissionCatalog::matrixFromSelections([
            'personal_vencimientos' => ['ver'],
        ]);
        $this->assertTrue(PermissionMatrix::allows($legacyVencimientos, 'vencimientos', 'ver'));

        $canonicalVencimientos = PermissionCatalog::matrixFromSelections([
            'vencimientos' => ['renovar'],
        ]);
        $this->assertTrue(PermissionMatrix::allows($canonicalVencimientos, 'personal_vencimientos', 'renovar'));
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

    public function test_web_permission_view_requires_direct_module_permission(): void
    {
        Route::get('/__test/web-permission-logistica-direct', fn () => response()->json(['ok' => true]))
            ->middleware(['web', 'web.permission:logistica,ver']);

        $legacyPermissions = PermissionCatalog::matrixFromSelections([
            'epps' => ['ver', 'actualizar'],
        ]);

        $this->assertTrue(PermissionMatrix::allows($legacyPermissions, 'logistica', 'ver'));

        $this
            ->withSession([
                'user' => [
                    'permissions' => $legacyPermissions,
                ],
            ])
            ->getJson('/__test/web-permission-logistica-direct')
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para acceder a este módulo.');

        $directPermissions = PermissionCatalog::matrixFromSelections([
            'logistica' => ['ver'],
        ]);

        $this
            ->withSession([
                'user' => [
                    'permissions' => $directPermissions,
                ],
            ])
            ->getJson('/__test/web-permission-logistica-direct')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_main_module_entry_routes_are_protected_by_view_permission(): void
    {
        foreach ([
            'inicio' => 'web.permission:inicio,ver',
            'personal.index' => 'web.permission:personal,ver',
            'personal.contratos.expiring' => 'web.permission:vencimientos,ver',
            'personal.contratos.expiring.export' => 'web.permission:vencimientos,exportar',
            'personal.habilitacion-minera.index' => 'web.permission:habilitacion_minera,ver',
            'bienestar.index' => 'web.permission:bienestar,ver',
            'rq-mina.index' => 'web.permission:rq_mina,ver',
            'rq-proserge.index' => 'web.permission:rq_proserge,ver',
            'man-power.index' => 'web.permission:man_power,ver',
            'logistica.index' => 'web.permission:logistica,ver',
            'mi-asistencia.index' => 'web.permission:mi_asistencia,ver',
            'evaluaciones.index' => 'web.permission:evaluaciones,ver',
            'asistencia.index' => 'web.permission:asistencias,ver',
            'faltas.index' => 'web.permission:faltas,ver',
            'catalogos.index' => 'web.permission:catalogos,ver',
            'usuarios.index' => 'web.permission:usuarios,ver',
            'seguridad.roles.index' => 'web.permission:roles,ver',
        ] as $routeName => $expectedMiddleware) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] should exist.");
            $this->assertContains($expectedMiddleware, $route->gatherMiddleware(), "Route [{$routeName}] must use [{$expectedMiddleware}].");
        }
    }

    public function test_personal_requested_actions_use_specific_permissions(): void
    {
        foreach ([
            'personal.export.form' => 'web.permission:personal,exportar_excel|exportar',
            'personal.export.download' => 'web.permission:personal,exportar_excel|exportar',
            'personal.documentos.store' => 'web.permission:personal,subir_documentos',
            'personal.documentos.contrato-firmado' => 'web.permission:personal,descargar_documentos',
            'personal.fichas.archivos.download' => 'web.permission:personal,descargar_documentos',
            'personal.contratos.renew' => 'web.permission:personal,renovar|actualizar',
            'personal.contratos.reentry' => 'web.permission:personal,reingresar|actualizar',
            'personal.update' => 'web.permission:personal,editar|actualizar|editar_ficha',
            'personal.importar' => 'web.permission:personal,importar|importar_master_general',
            'personal.importar.post' => 'web.permission:personal,importar|importar_master_general',
        ] as $routeName => $expectedMiddleware) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] should exist.");
            $this->assertContains($expectedMiddleware, $route->gatherMiddleware(), "Route [{$routeName}] must use [{$expectedMiddleware}].");
        }
    }

    public function test_personal_detail_can_be_viewed_without_edit_permission(): void
    {
        $roleId = $this->createPermissionRole('PERSONAL_DETAIL_VIEWER_TEST', [
            'personal' => ['ver', 'ver_detalle'],
        ]);
        $userId = $this->createPermissionUser($roleId, 'personal-detail-viewer');
        $personalId = $this->createPermissionPersonal('detalle');

        $response = $this
            ->withSession($this->sessionForPermissionUser($userId))
            ->get(route('personal.show', $personalId));

        $response
            ->assertOk()
            ->assertSee('Trabajador detalle', false)
            ->assertDontSee('href="' . route('personal.edit', $personalId) . '"', false);
    }

    public function test_personal_export_query_requires_export_permission(): void
    {
        $roleId = $this->createPermissionRole('PERSONAL_VIEW_NO_EXPORT_TEST', [
            'personal' => ['ver'],
        ]);
        $userId = $this->createPermissionUser($roleId, 'personal-no-export');

        $this
            ->withSession($this->sessionForPermissionUser($userId))
            ->get(route('personal.index', ['export' => 'excel']))
            ->assertForbidden();
    }

    public function test_personal_document_upload_and_contract_movements_require_specific_permissions(): void
    {
        $roleId = $this->createPermissionRole('PERSONAL_LIMITED_ACTIONS_TEST', [
            'personal' => ['ver', 'ver_documentos', 'ver_contratos'],
        ]);
        $userId = $this->createPermissionUser($roleId, 'personal-limited-actions');
        $personalId = (string) Str::uuid();

        $session = $this->sessionForPermissionUser($userId);

        $this
            ->withSession($session)
            ->post(route('personal.documentos.store', $personalId), [])
            ->assertForbidden();

        $this
            ->withSession($session)
            ->post(route('personal.contratos.renew', $personalId), [])
            ->assertForbidden();

        $this
            ->withSession($session)
            ->post(route('personal.contratos.reentry', $personalId), [])
            ->assertForbidden();
    }

    public function test_sidebar_uses_direct_view_permissions_without_duplicating_modules(): void
    {
        $legacyRoleId = (string) Str::uuid();
        $legacyUserId = (string) Str::uuid();
        $fullRoleId = (string) Str::uuid();
        $fullUserId = (string) Str::uuid();

        $legacyPermissions = PermissionCatalog::matrixFromSelections([
            'inicio' => ['ver'],
            'rq_mina' => ['ver'],
            'herramientas' => ['ver'],
        ]);

        $fullPermissions = PermissionCatalog::matrixFromSelections([
            'inicio' => ['ver'],
            'logistica' => ['ver'],
            'usuarios' => ['ver'],
            'roles' => ['ver'],
        ]);

        DB::table('roles')->insert([
            [
                'id' => $legacyRoleId,
                'nombre' => 'SIDEBAR_LEGACY_TEST',
                'permisos' => json_encode($legacyPermissions),
                'estado' => 'ACTIVO',
            ],
            [
                'id' => $fullRoleId,
                'nombre' => 'SIDEBAR_FULL_TEST',
                'permisos' => json_encode($fullPermissions),
                'estado' => 'ACTIVO',
            ],
        ]);

        DB::table('usuarios')->insert([
            [
                'id' => $legacyUserId,
                'email' => Str::lower(Str::random(8)) . '@test.local',
                'password' => bcrypt('secret123'),
                'rol_id' => $legacyRoleId,
                'personal_id' => null,
            ],
            [
                'id' => $fullUserId,
                'email' => Str::lower(Str::random(8)) . '@test.local',
                'password' => bcrypt('secret123'),
                'rol_id' => $fullRoleId,
                'personal_id' => null,
            ],
        ]);

        $legacyResponse = $this
            ->withSession([
                'auth_token' => 'test-token',
                'user_id' => $legacyUserId,
                'user' => [
                    'id' => $legacyUserId,
                    'email' => 'sidebar-legacy@test.local',
                    'permissions' => $legacyPermissions,
                ],
            ])
            ->get(route('inicio'));

        $legacyResponse
            ->assertOk()
            ->assertSee('<span class="nav-label">Inicio</span>', false)
            ->assertSee('<span class="nav-label">RQ Mina</span>', false)
            ->assertDontSee('<span class="nav-label">Logistica</span>', false)
            ->assertDontSee('<span class="nav-label">Herramientas</span>', false)
            ->assertDontSee('<span class="nav-label">Usuarios</span>', false)
            ->assertDontSee('<span class="nav-label">Roles</span>', false);

        $fullResponse = $this
            ->withSession([
                'auth_token' => 'test-token',
                'user_id' => $fullUserId,
                'user' => [
                    'id' => $fullUserId,
                    'email' => 'sidebar-full@test.local',
                    'permissions' => $fullPermissions,
                ],
            ])
            ->get(route('inicio'));

        $content = $fullResponse->getContent();

        $fullResponse
            ->assertOk()
            ->assertSee('<span class="nav-label">Logistica</span>', false)
            ->assertSee('<span class="nav-label">Usuarios</span>', false)
            ->assertSee('<span class="nav-label">Roles</span>', false);

        $this->assertSame(1, substr_count($content, '<span class="nav-label">Logistica</span>'));
        $this->assertSame(1, substr_count($content, '<span class="nav-label">Usuarios</span>'));
        $this->assertSame(1, substr_count($content, '<span class="nav-label">Roles</span>'));
    }

    public function test_role_edit_screen_renders_grouped_permission_controls(): void
    {
        $editorRoleId = (string) Str::uuid();
        $targetRoleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            [
                'id' => $editorRoleId,
                'nombre' => 'SECURITY_EDITOR_TEST',
                'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                    'roles' => ['ver', 'editar', 'actualizar'],
                ])),
                'estado' => 'ACTIVO',
            ],
            [
                'id' => $targetRoleId,
                'nombre' => 'ROL_PERMISOS_TEST',
                'permisos' => json_encode(PermissionCatalog::emptyMatrix()),
                'estado' => 'ACTIVO',
            ],
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $editorRoleId,
            'personal_id' => null,
        ]);

        $response = $this
            ->withSession([
                'auth_token' => 'test-token',
                'user_id' => $userId,
                'user' => [
                    'id' => $userId,
                    'email' => 'security-editor@test.local',
                    'permissions' => PermissionCatalog::emptyMatrix(),
                ],
            ])
            ->get(route('seguridad.roles.edit', $targetRoleId));

        $response
            ->assertOk()
            ->assertSee('data-permission-manager', false)
            ->assertSee('Seleccionar todo')
            ->assertSee('Quitar todo')
            ->assertSee('Ver modulo')
            ->assertSee('data-view-toggle', false)
            ->assertSee('data-action-toggle', false)
            ->assertSee('name="permisos[inicio][ver]"', false)
            ->assertSee('Guardar permisos');
    }

    public function test_role_permission_update_replaces_checked_and_unchecked_permissions(): void
    {
        $editorRoleId = (string) Str::uuid();
        $targetRoleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            [
                'id' => $editorRoleId,
                'nombre' => 'SECURITY_UPDATER_TEST',
                'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                    'roles' => ['ver', 'actualizar'],
                ])),
                'estado' => 'ACTIVO',
            ],
            [
                'id' => $targetRoleId,
                'nombre' => 'ROL_SAVE_PERMISSIONS_TEST',
                'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                    'personal' => ['ver', 'crear'],
                    'logistica' => ['ver'],
                ])),
                'estado' => 'ACTIVO',
            ],
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $editorRoleId,
            'personal_id' => null,
        ]);

        $response = $this
            ->withSession([
                'auth_token' => 'test-token',
                'user_id' => $userId,
                'user' => [
                    'id' => $userId,
                    'email' => 'security-updater@test.local',
                    'permissions' => PermissionCatalog::emptyMatrix(),
                ],
            ])
            ->put(route('seguridad.roles.update', $targetRoleId), [
                'nombre' => 'ROL_SAVE_PERMISSIONS_TEST',
                'estado' => 'ACTIVO',
                'permisos_present' => '1',
                'permisos' => [
                    'personal' => [
                        'ver' => '1',
                        'editar' => '1',
                    ],
                    'logistica' => [
                        'registrar' => '1',
                    ],
                ],
            ]);

        $response
            ->assertRedirect(route('seguridad.roles.edit', $targetRoleId))
            ->assertSessionHas('success', 'Permisos guardados correctamente.');

        $stored = json_decode((string) DB::table('roles')->where('id', $targetRoleId)->value('permisos'), true);

        $this->assertTrue($stored['personal']['ver']);
        $this->assertTrue($stored['personal']['editar']);
        $this->assertFalse($stored['personal']['crear']);
        $this->assertTrue($stored['logistica']['registrar']);
        $this->assertTrue($stored['logistica']['ver']);
    }

    public function test_role_update_without_permission_payload_preserves_existing_permissions(): void
    {
        $editorRoleId = (string) Str::uuid();
        $targetRoleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            [
                'id' => $editorRoleId,
                'nombre' => 'SECURITY_KEEPER_TEST',
                'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                    'roles' => ['ver', 'actualizar'],
                ])),
                'estado' => 'ACTIVO',
            ],
            [
                'id' => $targetRoleId,
                'nombre' => 'ROL_KEEP_PERMISSIONS_TEST',
                'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                    'personal' => ['ver', 'crear'],
                ])),
                'estado' => 'ACTIVO',
            ],
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $editorRoleId,
            'personal_id' => null,
        ]);

        $response = $this
            ->withSession([
                'auth_token' => 'test-token',
                'user_id' => $userId,
                'user' => [
                    'id' => $userId,
                    'email' => 'security-keeper@test.local',
                    'permissions' => PermissionCatalog::emptyMatrix(),
                ],
            ])
            ->put(route('seguridad.roles.update', $targetRoleId), [
                'nombre' => 'ROL_KEEP_PERMISSIONS_TEST',
                'estado' => 'ACTIVO',
            ]);

        $response->assertRedirect(route('seguridad.roles.edit', $targetRoleId));

        $stored = json_decode((string) DB::table('roles')->where('id', $targetRoleId)->value('permisos'), true);

        $this->assertTrue($stored['personal']['ver']);
        $this->assertTrue($stored['personal']['crear']);
    }

    public function test_admin_role_cannot_drop_users_and_roles_permissions(): void
    {
        $adminRoleId = (string) Str::uuid();
        $adminUserId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $adminRoleId,
            'nombre' => 'ADMIN',
            'permisos' => json_encode(PermissionCatalog::fullAccessMatrix()),
            'estado' => 'ACTIVO',
        ]);

        DB::table('usuarios')->insert([
            'id' => $adminUserId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $adminRoleId,
            'personal_id' => null,
            'estado' => 'ACTIVO',
        ]);

        $response = $this
            ->from(route('seguridad.roles.edit', $adminRoleId))
            ->withSession([
                'auth_token' => 'test-token',
                'user_id' => $adminUserId,
                'user' => [
                    'id' => $adminUserId,
                    'email' => 'admin@test.local',
                    'permissions' => PermissionCatalog::emptyMatrix(),
                ],
            ])
            ->put(route('seguridad.roles.update', $adminRoleId), [
                'nombre' => 'ADMIN',
                'estado' => 'ACTIVO',
                'permisos_present' => '1',
                'permisos' => [
                    'usuarios' => [
                        'ver' => '1',
                    ],
                    'roles' => [
                        'ver' => '1',
                    ],
                ],
            ]);

        $response
            ->assertRedirect(route('seguridad.roles.edit', $adminRoleId))
            ->assertSessionHasErrors('admin');

        $stored = json_decode((string) DB::table('roles')->where('id', $adminRoleId)->value('permisos'), true);

        $this->assertTrue($stored['usuarios']['administrar']);
        $this->assertTrue($stored['roles']['administrar']);
    }

    public function test_admin_role_cannot_be_deactivated(): void
    {
        $adminRoleId = (string) Str::uuid();
        $adminUserId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $adminRoleId,
            'nombre' => 'ADMIN',
            'permisos' => json_encode(PermissionCatalog::fullAccessMatrix()),
            'estado' => 'ACTIVO',
        ]);

        DB::table('usuarios')->insert([
            'id' => $adminUserId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $adminRoleId,
            'personal_id' => null,
            'estado' => 'ACTIVO',
        ]);

        $response = $this
            ->withSession([
                'auth_token' => 'test-token',
                'user_id' => $adminUserId,
                'user' => [
                    'id' => $adminUserId,
                    'email' => 'admin@test.local',
                    'permissions' => PermissionCatalog::emptyMatrix(),
                ],
            ])
            ->post(route('seguridad.roles.toggle', $adminRoleId));

        $response
            ->assertRedirect(route('seguridad.roles.index'))
            ->assertSessionHas('error', AdminProtectionService::MESSAGE_ADMIN_ROLE_ACTIVE);

        $this->assertSame('ACTIVO', DB::table('roles')->where('id', $adminRoleId)->value('estado'));
    }

    public function test_last_active_admin_user_cannot_be_deactivated(): void
    {
        $adminRoleId = (string) Str::uuid();
        $adminUserId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $adminRoleId,
            'nombre' => 'ADMIN',
            'permisos' => json_encode(PermissionCatalog::fullAccessMatrix()),
            'estado' => 'ACTIVO',
        ]);

        DB::table('usuarios')->insert([
            'id' => $adminUserId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $adminRoleId,
            'personal_id' => null,
            'estado' => 'ACTIVO',
        ]);

        $response = $this
            ->withSession([
                'auth_token' => 'test-token',
                'user_id' => $adminUserId,
                'user' => [
                    'id' => $adminUserId,
                    'email' => 'admin@test.local',
                    'permissions' => PermissionCatalog::emptyMatrix(),
                ],
            ])
            ->post(route('usuarios.toggle-estado', $adminUserId));

        $response
            ->assertRedirect(route('usuarios.index'))
            ->assertSessionHas('error', AdminProtectionService::MESSAGE_LAST_ADMIN_USER);

        $this->assertSame('ACTIVO', DB::table('usuarios')->where('id', $adminUserId)->value('estado'));
    }

    public function test_last_active_admin_user_cannot_be_moved_to_non_admin_role(): void
    {
        $adminRoleId = (string) Str::uuid();
        $regularRoleId = (string) Str::uuid();
        $adminUserId = (string) Str::uuid();

        DB::table('roles')->insert([
            [
                'id' => $adminRoleId,
                'nombre' => 'ADMIN',
                'permisos' => json_encode(PermissionCatalog::fullAccessMatrix()),
                'estado' => 'ACTIVO',
            ],
            [
                'id' => $regularRoleId,
                'nombre' => 'OPERADOR_TEST',
                'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                    'inicio' => ['ver'],
                ])),
                'estado' => 'ACTIVO',
            ],
        ]);

        DB::table('usuarios')->insert([
            'id' => $adminUserId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $adminRoleId,
            'personal_id' => null,
            'estado' => 'ACTIVO',
        ]);

        $response = $this
            ->from(route('usuarios.show', $adminUserId))
            ->withSession([
                'auth_token' => 'test-token',
                'user_id' => $adminUserId,
                'user' => [
                    'id' => $adminUserId,
                    'email' => 'admin@test.local',
                    'permissions' => PermissionCatalog::emptyMatrix(),
                ],
            ])
            ->put(route('usuarios.update', $adminUserId), [
                'email' => 'admin@test.local',
                'rol_id' => $regularRoleId,
                'estado' => 'ACTIVO',
            ]);

        $response
            ->assertRedirect(route('usuarios.show', $adminUserId))
            ->assertSessionHasErrors('admin');

        $this->assertSame($adminRoleId, DB::table('usuarios')->where('id', $adminUserId)->value('rol_id'));
        $this->assertSame('ACTIVO', DB::table('usuarios')->where('id', $adminUserId)->value('estado'));
    }

    public function test_usuario_scope_uses_scope_permission_instead_of_configurar(): void
    {
        $configRoleId = $this->createPermissionRole('USUARIO_CONFIGURAR_TEST', [
            'usuarios' => ['ver', 'configurar'],
        ]);
        $scopeRoleId = $this->createPermissionRole('USUARIO_SCOPE_TEST', [
            'usuarios' => ['ver', 'scope'],
        ]);
        $targetRoleId = $this->createPermissionRole('USUARIO_TARGET_TEST', [
            'inicio' => ['ver'],
        ]);

        $configUserId = $this->createPermissionUser($configRoleId, 'configurar-scope');
        $scopeUserId = $this->createPermissionUser($scopeRoleId, 'scope');
        $targetUserId = $this->createPermissionUser($targetRoleId, 'target');

        $this
            ->withSession($this->sessionForPermissionUser($configUserId))
            ->get(route('usuarios.index'))
            ->assertOk()
            ->assertDontSee('Scope Mina');

        $this
            ->withSession($this->sessionForPermissionUser($configUserId))
            ->get(route('usuarios.scope', $targetUserId))
            ->assertForbidden();

        $this
            ->withSession($this->sessionForPermissionUser($scopeUserId))
            ->get(route('usuarios.index'))
            ->assertOk()
            ->assertSee('Scope Mina');

        $this
            ->withSession($this->sessionForPermissionUser($scopeUserId))
            ->get(route('usuarios.scope', $targetUserId))
            ->assertOk()
            ->assertSee('Minas disponibles');
    }

    public function test_usuario_role_change_requires_asignar_permission(): void
    {
        $editorRoleId = $this->createPermissionRole('USUARIO_EDITOR_TEST', [
            'usuarios' => ['ver', 'editar'],
        ]);
        $currentRoleId = $this->createPermissionRole('USUARIO_ROL_ACTUAL_TEST', [
            'inicio' => ['ver'],
        ]);
        $nextRoleId = $this->createPermissionRole('USUARIO_ROL_NUEVO_TEST', [
            'inicio' => ['ver'],
        ]);

        $actorId = $this->createPermissionUser($editorRoleId, 'editor-usuarios');
        $targetUserId = $this->createPermissionUser($currentRoleId, 'usuario-cambio-rol');
        $email = (string) DB::table('usuarios')->where('id', $targetUserId)->value('email');

        $this
            ->withSession($this->sessionForPermissionUser($actorId))
            ->put(route('usuarios.update', $targetUserId), [
                'email' => $email,
                'rol_id' => $nextRoleId,
                'estado' => 'ACTIVO',
            ])
            ->assertForbidden();

        $this->assertSame($currentRoleId, DB::table('usuarios')->where('id', $targetUserId)->value('rol_id'));
    }

    public function test_usuario_email_update_allows_edit_permission_without_asignar(): void
    {
        $editorRoleId = $this->createPermissionRole('USUARIO_EMAIL_EDITOR_TEST', [
            'usuarios' => ['ver', 'editar'],
        ]);
        $targetRoleId = $this->createPermissionRole('USUARIO_EMAIL_TARGET_TEST', [
            'inicio' => ['ver'],
        ]);

        $actorId = $this->createPermissionUser($editorRoleId, 'editor-email');
        $targetUserId = $this->createPermissionUser($targetRoleId, 'usuario-email');
        $newEmail = 'actualizado-' . Str::lower(Str::random(8)) . '@test.local';

        $this
            ->withSession($this->sessionForPermissionUser($actorId))
            ->put(route('usuarios.update', $targetUserId), [
                'email' => $newEmail,
                'rol_id' => $targetRoleId,
                'estado' => 'ACTIVO',
            ])
            ->assertRedirect(route('usuarios.show', $targetUserId));

        $this->assertSame($newEmail, DB::table('usuarios')->where('id', $targetUserId)->value('email'));
    }

    public function test_crear_usuario_requires_asignar_permission_when_submitting(): void
    {
        $creatorRoleId = $this->createPermissionRole('USUARIO_CREAR_SIN_ASIGNAR_TEST', [
            'usuarios' => ['ver', 'crear'],
        ]);
        $newUserRoleId = $this->createPermissionRole('USUARIO_CREADO_ROL_TEST', [
            'inicio' => ['ver'],
        ]);

        $actorId = $this->createPermissionUser($creatorRoleId, 'creador-sin-asignar');
        $personalId = $this->createPermissionPersonal('sin-asignar');

        $this
            ->withSession($this->sessionForPermissionUser($actorId))
            ->post(route('usuarios.store'), [
                'personal_id' => $personalId,
                'email' => 'nuevo-' . Str::lower(Str::random(8)) . '@test.local',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'rol_id' => $newUserRoleId,
                'estado' => 'ACTIVO',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('usuarios', [
            'personal_id' => $personalId,
        ]);
    }

    public function test_usuario_api_routes_validate_usuario_permissions(): void
    {
        $deniedRoleId = $this->createPermissionRole('USUARIO_API_DENIED_TEST', [
            'inicio' => ['ver'],
        ]);
        $viewerRoleId = $this->createPermissionRole('USUARIO_API_VIEWER_TEST', [
            'usuarios' => ['ver'],
        ]);
        $scopeRoleId = $this->createPermissionRole('USUARIO_API_SCOPE_TEST', [
            'usuarios' => ['scope'],
        ]);

        $targetUserId = $this->createPermissionUser($viewerRoleId, 'api-target');
        $deniedToken = $this->createApiTokenForUser($this->createPermissionUser($deniedRoleId, 'api-denied'));
        $viewerToken = $this->createApiTokenForUser($this->createPermissionUser($viewerRoleId, 'api-viewer'));
        $scopeToken = $this->createApiTokenForUser($this->createPermissionUser($scopeRoleId, 'api-scope'));

        $this
            ->withToken($deniedToken)
            ->getJson('/api/v1/seguridad/usuarios')
            ->assertForbidden()
            ->assertJsonPath('code', 'USUARIOS_FORBIDDEN');

        $this
            ->withToken($viewerToken)
            ->getJson('/api/v1/seguridad/usuarios')
            ->assertOk()
            ->assertJsonPath('code', 'USUARIOS_LIST_OK');

        $this
            ->withToken($deniedToken)
            ->getJson('/api/v1/seguridad/usuarios/' . $targetUserId . '/mina-scope')
            ->assertForbidden()
            ->assertJsonPath('code', 'USUARIO_MINA_SCOPE_FORBIDDEN');

        $this
            ->withToken($scopeToken)
            ->getJson('/api/v1/seguridad/usuarios/' . $targetUserId . '/mina-scope')
            ->assertOk()
            ->assertJsonPath('code', 'USUARIO_MINA_SCOPE_OK');
    }

    public function test_roles_edit_button_and_route_require_edit_or_update_permission(): void
    {
        $viewerRoleId = $this->createPermissionRole('ROL_VIEWER_ONLY_TEST', [
            'roles' => ['ver'],
        ]);
        $updaterRoleId = $this->createPermissionRole('ROL_UPDATER_TEST', [
            'roles' => ['ver', 'actualizar'],
        ]);
        $targetRoleId = $this->createPermissionRole('ROL_TARGET_EDIT_TEST', [
            'inicio' => ['ver'],
        ]);

        $viewerUserId = $this->createPermissionUser($viewerRoleId, 'roles-viewer');
        $updaterUserId = $this->createPermissionUser($updaterRoleId, 'roles-updater');

        $this
            ->withSession($this->sessionForPermissionUser($viewerUserId))
            ->get(route('seguridad.roles.index'))
            ->assertOk()
            ->assertDontSee('Editar permisos');

        $this
            ->withSession($this->sessionForPermissionUser($viewerUserId))
            ->get(route('seguridad.roles.edit', $targetRoleId))
            ->assertForbidden();

        $this
            ->withSession($this->sessionForPermissionUser($updaterUserId))
            ->get(route('seguridad.roles.index'))
            ->assertOk()
            ->assertSee('Editar permisos');

        $this
            ->withSession($this->sessionForPermissionUser($updaterUserId))
            ->get(route('seguridad.roles.edit', $targetRoleId))
            ->assertOk()
            ->assertSee('Guardar permisos');
    }

    public function test_roles_update_allows_edit_permission_and_blocks_view_only_user(): void
    {
        $viewerRoleId = $this->createPermissionRole('ROL_UPDATE_VIEWER_TEST', [
            'roles' => ['ver'],
        ]);
        $editorRoleId = $this->createPermissionRole('ROL_UPDATE_EDITOR_TEST', [
            'roles' => ['ver', 'editar'],
        ]);
        $targetRoleId = $this->createPermissionRole('ROL_UPDATE_TARGET_TEST', [
            'inicio' => ['ver'],
        ]);

        $viewerUserId = $this->createPermissionUser($viewerRoleId, 'roles-update-viewer');
        $editorUserId = $this->createPermissionUser($editorRoleId, 'roles-update-editor');

        $this
            ->withSession($this->sessionForPermissionUser($viewerUserId))
            ->put(route('seguridad.roles.update', $targetRoleId), [
                'nombre' => DB::table('roles')->where('id', $targetRoleId)->value('nombre'),
                'estado' => 'ACTIVO',
                'permisos_present' => '1',
                'permisos' => [
                    'personal' => ['ver' => '1'],
                ],
            ])
            ->assertForbidden();

        $this
            ->withSession($this->sessionForPermissionUser($editorUserId))
            ->put(route('seguridad.roles.update', $targetRoleId), [
                'nombre' => DB::table('roles')->where('id', $targetRoleId)->value('nombre'),
                'estado' => 'ACTIVO',
                'permisos_present' => '1',
                'permisos' => [
                    'personal' => ['ver' => '1', 'editar' => '1'],
                ],
            ])
            ->assertRedirect(route('seguridad.roles.edit', $targetRoleId))
            ->assertSessionHas('success', 'Permisos guardados correctamente.');

        $stored = json_decode((string) DB::table('roles')->where('id', $targetRoleId)->value('permisos'), true);

        $this->assertTrue($stored['personal']['ver']);
        $this->assertTrue($stored['personal']['editar']);
    }

    public function test_roles_duplicate_and_toggle_require_specific_permissions(): void
    {
        $editorRoleId = $this->createPermissionRole('ROL_ACTION_EDITOR_TEST', [
            'roles' => ['ver', 'editar'],
        ]);
        $duplicatorRoleId = $this->createPermissionRole('ROL_ACTION_DUPLICATOR_TEST', [
            'roles' => ['ver', 'duplicar'],
        ]);
        $deactivatorRoleId = $this->createPermissionRole('ROL_ACTION_DEACTIVATOR_TEST', [
            'roles' => ['ver', 'desactivar'],
        ]);
        $targetRoleId = $this->createPermissionRole('ROL_ACTION_TARGET_TEST', [
            'inicio' => ['ver'],
        ]);

        $editorUserId = $this->createPermissionUser($editorRoleId, 'roles-action-editor');
        $duplicatorUserId = $this->createPermissionUser($duplicatorRoleId, 'roles-action-duplicator');
        $deactivatorUserId = $this->createPermissionUser($deactivatorRoleId, 'roles-action-deactivator');

        $this
            ->withSession($this->sessionForPermissionUser($editorUserId))
            ->post(route('seguridad.roles.duplicate', $targetRoleId))
            ->assertForbidden();

        $this
            ->withSession($this->sessionForPermissionUser($duplicatorUserId))
            ->post(route('seguridad.roles.duplicate', $targetRoleId))
            ->assertRedirect();

        $this
            ->withSession($this->sessionForPermissionUser($editorUserId))
            ->post(route('seguridad.roles.toggle', $targetRoleId))
            ->assertForbidden();

        $this
            ->withSession($this->sessionForPermissionUser($deactivatorUserId))
            ->post(route('seguridad.roles.toggle', $targetRoleId))
            ->assertRedirect(route('seguridad.roles.index'))
            ->assertSessionHas('success', 'Estado del rol actualizado correctamente.');

        $this->assertSame('INACTIVO', DB::table('roles')->where('id', $targetRoleId)->value('estado'));
    }

    public function test_roles_api_requires_ver_permission(): void
    {
        $deniedRoleId = $this->createPermissionRole('ROL_API_DENIED_TEST', [
            'inicio' => ['ver'],
        ]);
        $viewerRoleId = $this->createPermissionRole('ROL_API_VIEWER_TEST', [
            'roles' => ['ver'],
        ]);

        $deniedToken = $this->createApiTokenForUser($this->createPermissionUser($deniedRoleId, 'roles-api-denied'));
        $viewerToken = $this->createApiTokenForUser($this->createPermissionUser($viewerRoleId, 'roles-api-viewer'));

        $this
            ->withToken($deniedToken)
            ->getJson('/api/v1/seguridad/roles')
            ->assertForbidden()
            ->assertJsonPath('code', 'ROLES_FORBIDDEN');

        $this
            ->withToken($viewerToken)
            ->getJson('/api/v1/seguridad/roles')
            ->assertOk()
            ->assertJsonPath('code', 'ROLES_LIST_OK');
    }

    public function test_bienestar_bloqueo_actions_respect_specific_permissions(): void
    {
        $viewerRoleId = $this->createPermissionRole('BIENESTAR_VIEWER_TEST', [
            'bienestar' => ['ver'],
        ]);
        $creatorRoleId = $this->createPermissionRole('BIENESTAR_CREATOR_TEST', [
            'bienestar' => ['ver', 'crear'],
        ]);
        $editorRoleId = $this->createPermissionRole('BIENESTAR_EDITOR_TEST', [
            'bienestar' => ['ver', 'editar'],
        ]);
        $deleterRoleId = $this->createPermissionRole('BIENESTAR_DELETER_TEST', [
            'bienestar' => ['ver', 'eliminar'],
        ]);

        $viewerId = $this->createPermissionUser($viewerRoleId, 'bienestar-viewer');
        $creatorId = $this->createPermissionUser($creatorRoleId, 'bienestar-creator');
        $editorId = $this->createPermissionUser($editorRoleId, 'bienestar-editor');
        $deleterId = $this->createPermissionUser($deleterRoleId, 'bienestar-deleter');
        $personalId = $this->createPermissionPersonal('bienestar');
        $bloqueoId = $this->createPermissionBloqueo($personalId, $creatorId);

        $this
            ->withSession($this->sessionForPermissionUser($viewerId))
            ->get(route('bienestar.index'))
            ->assertOk()
            ->assertDontSee('Nuevo bloqueo');

        $this
            ->withSession($this->sessionForPermissionUser($creatorId))
            ->get(route('bienestar.index'))
            ->assertOk()
            ->assertSee('Nuevo bloqueo');

        $this
            ->withSession($this->sessionForPermissionUser($viewerId))
            ->get(route('bienestar.bloqueos.create'))
            ->assertForbidden();

        $this
            ->withSession($this->sessionForPermissionUser($viewerId))
            ->get(route('bienestar.show', $personalId))
            ->assertOk()
            ->assertDontSee(route('bienestar.bloqueos.edit', $bloqueoId), false)
            ->assertDontSee(route('bienestar.bloqueos.anular', $bloqueoId), false);

        $this
            ->withSession($this->sessionForPermissionUser($editorId))
            ->get(route('bienestar.show', $personalId))
            ->assertOk()
            ->assertSee(route('bienestar.bloqueos.edit', $bloqueoId), false)
            ->assertDontSee(route('bienestar.bloqueos.anular', $bloqueoId), false);

        $this
            ->withSession($this->sessionForPermissionUser($editorId))
            ->put(route('bienestar.bloqueos.update', $bloqueoId), [
                'tipo' => 'vacaciones',
                'motivo' => 'Permiso actualizado',
                'detalle' => 'Actualizado desde prueba',
                'fecha_inicio' => '2026-07-11',
                'fecha_fin' => '2026-07-12',
            ])
            ->assertRedirect(route('bienestar.index'));

        $this->assertDatabaseHas('personal_bloqueo', [
            'id' => $bloqueoId,
            'motivo' => 'Permiso actualizado',
            'estado' => 'ACTIVO',
        ]);

        $this
            ->withSession($this->sessionForPermissionUser($viewerId))
            ->post(route('bienestar.bloqueos.anular', $bloqueoId))
            ->assertForbidden();

        $this
            ->withSession($this->sessionForPermissionUser($deleterId))
            ->post(route('bienestar.bloqueos.anular', $bloqueoId))
            ->assertRedirect();

        $this->assertDatabaseHas('personal_bloqueo', [
            'id' => $bloqueoId,
            'estado' => 'ANULADO',
        ]);
    }

    private function createPermissionRole(string $name, array $permissions): string
    {
        $id = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $id,
            'nombre' => $name . '_' . Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createPermissionUser(string $roleId, string $prefix): string
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

    private function createPermissionPersonal(string $prefix): string
    {
        $id = (string) Str::uuid();
        $token = Str::upper(Str::random(8));

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'Trabajador ' . $prefix . ' ' . $token,
            'puesto' => 'Operario',
            'qr_code' => 'QR-' . $token,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createPermissionBloqueo(string $personalId, string $usuarioId): string
    {
        $id = (string) Str::uuid();

        DB::table('personal_bloqueo')->insert([
            'id' => $id,
            'personal_id' => $personalId,
            'tipo' => 'vacaciones',
            'fecha_inicio' => '2026-07-10',
            'fecha_fin' => '2026-07-12',
            'motivo' => 'Permiso inicial',
            'detalle' => 'Detalle inicial',
            'bloqueado_por_id' => $usuarioId,
            'estado' => 'ACTIVO',
            'visible_para_planner' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function sessionForPermissionUser(string $userId): array
    {
        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'permisos@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }

    private function createApiTokenForUser(string $userId): string
    {
        $plain = 'token-' . Str::random(40);

        DB::table('auth_tokens')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $userId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plain;
    }
}
