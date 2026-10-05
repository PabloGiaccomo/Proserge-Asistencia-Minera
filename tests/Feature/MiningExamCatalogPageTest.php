<?php

namespace Tests\Feature;

use App\Modules\Personal\Services\MiningExamIdentityResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\MiningCatalogFixtures;
use Tests\TestCase;

class MiningExamCatalogPageTest extends TestCase
{
    use DatabaseTransactions, MiningCatalogFixtures;

    public function test_all_catalog_routes_require_configuration_permission(): void
    {
        $this->withSession($this->catalogSession($this->actor(false)))
            ->get(route('personal.habilitacion-minera.catalogo.index'))->assertForbidden();
        foreach (['preview', 'confirm'] as $route) {
            $this->post(route('personal.habilitacion-minera.catalogo.'.$route))->assertForbidden();
        }
    }

    public function test_page_and_preview_do_not_modify_catalog_and_confirmation_requires_acceptance(): void
    {
        $actor = $this->actor();
        $principal = $this->exam('Trabajo en altura');
        $variant = $this->exam('Trabajos en altura');
        $this->withSession($this->catalogSession($actor))->get(route('personal.habilitacion-minera.catalogo.index'))
            ->assertOk()->assertSee('Organizar exámenes');
        $this->post(route('personal.habilitacion-minera.catalogo.preview'), [
            'accion' => 'asociar', 'principal_id' => $principal->id, 'variante_ids' => [$variant->id],
            'motivo' => 'Variante revisada por el responsable',
        ])->assertRedirect(route('personal.habilitacion-minera.catalogo.index', ['revision' => 1]));
        $this->assertDatabaseMissing('examen_minero_asociaciones', ['variante_id' => $variant->id]);
        $this->get(route('personal.habilitacion-minera.catalogo.index'))->assertOk()->assertSee('Vista previa de la corrección');
        $token = session('mining_catalog_preview.token');
        $this->post(route('personal.habilitacion-minera.catalogo.confirm'), ['token' => $token])->assertSessionHasErrors('confirmar');
        $this->post(route('personal.habilitacion-minera.catalogo.confirm'), ['token' => $token, 'confirmar' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('examen_minero_asociaciones', ['variante_id' => $variant->id, 'principal_id' => $principal->id]);
        $this->assertNull(session('mining_catalog_preview'));
    }

    public function test_confirmation_rejects_expired_and_wrong_token(): void
    {
        $actor = $this->actor();
        $this->withSession($this->catalogSession($actor))->post(route('personal.habilitacion-minera.catalogo.confirm'),
            ['token' => 'inexistente', 'confirmar' => 1])->assertSessionHasErrors('catalogo');
        $this->post(route('personal.habilitacion-minera.catalogo.preview'), ['accion' => 'pendiente',
            'principal_id' => $this->exam('Curso pendiente')->id, 'motivo' => 'Revisar contenido tecnico'])->assertRedirect();
        $token = session('mining_catalog_preview.token');
        $this->travel(16)->minutes();
        $this->post(route('personal.habilitacion-minera.catalogo.confirm'), ['token' => $token, 'confirmar' => 1])->assertSessionHasErrors('catalogo');
    }

    public function test_missing_schema_shows_operational_warning_and_rejects_writes(): void
    {
        $this->partialMock(MiningExamIdentityResolver::class, fn ($mock) => $mock->shouldReceive('schemaReady')->andReturn(false));
        $this->withSession($this->catalogSession($this->actor()))
            ->get(route('personal.habilitacion-minera.catalogo.index'))->assertOk()->assertSee('Falta instalar');
        $this->post(route('personal.habilitacion-minera.catalogo.preview'), ['accion' => 'pendiente',
            'principal_id' => $this->exam('Curso')->id, 'motivo' => 'Revisar contenido tecnico'])->assertSessionHasErrors('catalogo');
    }
}
