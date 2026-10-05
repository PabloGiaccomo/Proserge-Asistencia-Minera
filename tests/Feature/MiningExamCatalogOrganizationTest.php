<?php

namespace Tests\Feature;

use App\Models\ExamenMineroAsociacion;
use App\Models\ExamenMineroCatalogoCambio;
use App\Modules\Personal\Services\MiningExamCatalogOrganizationService;
use App\Modules\Personal\Services\MiningExamIdentityResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\Support\MiningCatalogFixtures;
use Tests\TestCase;

class MiningExamCatalogOrganizationTest extends TestCase
{
    use DatabaseTransactions, MiningCatalogFixtures;

    protected function payload($first, array $extra = []): array
    {
        return $extra + ['accion' => 'asociar', 'principal_id' => $first->id, 'variante_ids' => [],
            'aliases' => [], 'motivo' => 'Nombres alternativos revisados'];
    }

    public function test_preview_and_confirmation_preserve_exam_ids_requirements_and_rules(): void
    {
        $service = app(MiningExamCatalogOrganizationService::class);
        $actor = $this->actor();
        $first = $this->exam('Control uno');
        $variant = $this->exam('Control unos', ['nota_minima' => 14, 'requiere_nota' => true]);
        $requirement = $this->requirement($this->mine(), $variant);
        $before = $requirement->fresh()->getAttributes();
        $preview = $service->preview($this->payload($first, ['variante_ids' => [$variant->id]]), $actor);
        $this->assertDatabaseMissing('examen_minero_asociaciones', ['variante_id' => $variant->id]);
        $service->confirm($preview, $actor);
        $this->assertSame($before, $requirement->fresh()->getAttributes());
        $this->assertSame('14.00', $variant->fresh()->nota_minima);
        $this->assertSame($first->id, app(MiningExamIdentityResolver::class)->canonicalId($variant->id));
        $this->assertDatabaseHas('examen_minero_catalogo_cambios', ['usuario_id' => $actor->id, 'accion' => 'asociar']);
    }

    public function test_rename_preserves_old_name_as_alias_and_snapshots_audit(): void
    {
        $service = app(MiningExamCatalogOrganizationService::class);
        $actor = $this->actor();
        $first = $this->exam('Nombre incorrecto');
        $service->confirm($service->preview($this->payload($first, ['accion' => 'renombrar', 'nombre' => 'Nombre correcto']), $actor), $actor);
        $this->assertSame('Nombre correcto', $first->fresh()->nombre);
        $this->assertSame($first->id, app(MiningExamIdentityResolver::class)->resolve('Nombre incorrecto')['examen_id']);
        $audit = ExamenMineroCatalogoCambio::query()->latest()->first();
        $this->assertNotEmpty($audit->antes_json);
        $this->assertNotEmpty($audit->despues_json);
    }

    public function test_alias_collision_with_foreign_group_is_rejected(): void
    {
        $service = app(MiningExamCatalogOrganizationService::class);
        $first = $this->exam('Grupo uno');
        $this->exam('Grupo dos');
        $this->expectException(ValidationException::class);
        $service->preview($this->payload($first, ['aliases' => [['nombre' => 'Grupo dos', 'mina_id' => null]]]), $this->actor());
    }

    public function test_cycles_and_reparenting_principals_with_dependents_are_rejected(): void
    {
        $service = app(MiningExamCatalogOrganizationService::class);
        $first = $this->exam('Principal');
        $variant = $this->exam('Variante');
        $other = $this->exam('Otro');
        $this->associate($variant, $first);
        $this->expectException(ValidationException::class);
        $service->preview($this->payload($other, ['variante_ids' => [$first->id]]), $this->actor());
    }

    public function test_variant_cannot_be_selected_as_principal(): void
    {
        $service = app(MiningExamCatalogOrganizationService::class);
        $first = $this->exam('Principal');
        $variant = $this->exam('Variante');
        $this->associate($variant, $first);
        $this->expectException(ValidationException::class);
        $service->preview($this->payload($variant), $this->actor());
    }

    public function test_empty_reason_is_rejected(): void
    {
        $service = app(MiningExamCatalogOrganizationService::class);
        $this->expectException(ValidationException::class);
        $service->preview($this->payload($this->exam('Curso'), ['motivo' => '']), $this->actor());
    }

    public function test_changed_catalogue_rejects_confirmation_without_partial_writes(): void
    {
        $service = app(MiningExamCatalogOrganizationService::class);
        $actor = $this->actor();
        $first = $this->exam('Curso');
        $variant = $this->exam('Cursos');
        $preview = $service->preview($this->payload($first, ['variante_ids' => [$variant->id]]), $actor);
        $first->update(['nota_minima' => 15]);
        try {
            $service->confirm($preview, $actor);
            $this->fail('Debe rechazar revision desactualizada');
        } catch (ValidationException $e) {
            $this->assertDatabaseMissing('examen_minero_asociaciones', ['variante_id' => $variant->id]);
        }
    }

    public function test_preview_belongs_to_actor_and_pending_does_not_associate(): void
    {
        $service = app(MiningExamCatalogOrganizationService::class);
        $actor = $this->actor();
        $first = $this->exam('Curso');
        $preview = $service->preview($this->payload($first, ['accion' => 'pendiente']), $actor);
        $service->confirm($preview, $actor);
        $this->assertSame(0, ExamenMineroAsociacion::query()->count());
        $this->assertDatabaseHas('examen_minero_catalogo_cambios', ['accion' => 'pendiente']);
        $this->expectException(ValidationException::class);
        $service->confirm($service->preview($this->payload($first), $actor), $this->actor());
    }
}
