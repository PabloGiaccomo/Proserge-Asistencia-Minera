<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateToken;
use App\Models\Rol;
use App\Models\RQMina;
use App\Models\Usuario;
use App\Modules\RQMina\Services\RQMinaService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Tests\TestCase;

class RQMinaApiListContractTest extends TestCase
{
    private const MINA_ID = '00000000-0000-0000-0000-000000000001';

    public function test_lista_serializa_solo_modelos_y_conserva_data_como_array(): void
    {
        $actor = $this->authenticate(['ver']);
        $rq = new RQMina([
            'id' => '00000000-0000-0000-0000-000000000002',
            'mina_id' => self::MINA_ID,
            'destino_nombre' => 'Mina de prueba',
            'area' => 'Parada de prueba',
            'estado' => 'BORRADOR',
        ]);

        $this->mock(RQMinaService::class)->shouldReceive('listForUser')->once()
            ->withArgs(fn ($usuario, $filters): bool => $usuario === $actor && $filters === [])
            ->andReturn([
                'items' => new Collection([$rq]),
                'total' => 1,
                'per_page' => 10,
                'current_page' => 1,
                'total_pages' => 1,
            ]);

        $this->withoutExceptionHandling()->getJson('/api/v1/rq-mina')
            ->assertOk()
            ->assertJsonPath('code', 'RQ_MINA_LIST_OK')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rq->id)
            ->assertJsonPath('data.0.area', 'Parada de prueba')
            ->assertJsonPath('detail.pagination', [
                'current_page' => 1,
                'per_page' => 10,
                'total' => 1,
                'total_pages' => 1,
            ]);
    }

    public function test_lista_aplica_filtros_y_paginacion_y_admite_paginas_vacias(): void
    {
        $actor = $this->authenticate(['ver']);
        $filters = [
            'mina_id' => self::MINA_ID,
            'estado' => 'BORRADOR',
            'fecha_inicio_desde' => '2026-11-04',
        ];
        $service = $this->mock(RQMinaService::class);
        $service->shouldReceive('canAccessMina')->once()->with($actor, self::MINA_ID)->andReturnTrue();
        $service->shouldReceive('listForUser')->once()->with($actor, $filters, 5, 3)->andReturn([
            'items' => new Collection,
            'total' => 9,
            'per_page' => 5,
            'current_page' => 3,
            'total_pages' => 2,
        ]);

        $this->getJson('/api/v1/rq-mina?'.http_build_query($filters + ['per_page' => 5, 'page' => 3]))
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('detail.pagination', [
                'current_page' => 3,
                'per_page' => 5,
                'total' => 9,
                'total_pages' => 2,
            ]);
    }

    public function test_usuario_sin_ver_no_consulta_ni_alcance_ni_listado(): void
    {
        $this->authenticate(['crear']);
        $service = $this->mock(RQMinaService::class);
        $service->shouldNotReceive('canAccessMina');
        $service->shouldNotReceive('listForUser');

        $this->getJson('/api/v1/rq-mina?mina_id='.self::MINA_ID)
            ->assertForbidden()
            ->assertJsonPath('code', 'RQ_MINA_FORBIDDEN_VIEW');
    }

    public function test_permiso_ver_no_habilita_una_mina_fuera_de_alcance(): void
    {
        $actor = $this->authenticate(['ver']);
        $service = $this->mock(RQMinaService::class);
        $service->shouldReceive('canAccessMina')->once()->with($actor, self::MINA_ID)->andReturnFalse();
        $service->shouldNotReceive('listForUser');

        $this->getJson('/api/v1/rq-mina?mina_id='.self::MINA_ID)
            ->assertForbidden()
            ->assertJsonPath('code', 'MINA_SCOPE_FORBIDDEN');
    }

    public function test_lista_rechaza_paginacion_invalida_antes_de_consultar(): void
    {
        $this->authenticate(['ver']);
        $this->mock(RQMinaService::class)->shouldNotReceive('listForUser');

        $this->getJson('/api/v1/rq-mina?page=0&per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['detail' => ['page', 'per_page']]);
    }

    private function authenticate(array $actions): Usuario
    {
        $usuario = new Usuario(['id' => '00000000-0000-0000-0000-000000000003', 'estado' => 'ACTIVO']);
        $usuario->setRelation('rol', new Rol([
            'nombre' => 'RQ_API_LIST_CONTRACT',
            'estado' => 'ACTIVO',
            'permisos' => PermissionCatalog::matrixFromSelections(['rq_mina' => $actions]),
        ]));
        $usuario->setRelation('rolesAdicionales', new Collection);
        $this->mock(AuthenticateToken::class)->shouldReceive('handle')
            ->andReturnUsing(function (Request $request, callable $next) use ($usuario) {
                $request->setUserResolver(fn (): Usuario => $usuario);

                return $next($request);
            });

        return $usuario;
    }
}
