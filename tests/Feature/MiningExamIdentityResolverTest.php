<?php

namespace Tests\Feature;

use App\Modules\Personal\Services\MiningExamIdentityResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\MiningCatalogFixtures;
use Tests\TestCase;

class MiningExamIdentityResolverTest extends TestCase
{
    use DatabaseTransactions, MiningCatalogFixtures;

    public function test_similar_names_are_not_equivalent_without_confirmation(): void
    {
        $resolver = app(MiningExamIdentityResolver::class);
        $first = $this->exam('ESPACIO CONFINADO');
        $this->exam('ESPACIOS CONFINADOS');
        $this->assertSame([$first->id], $resolver->groupIds($first->id));
        $this->assertSame('missing', $resolver->resolve('ESPACIO CONFINADOO')['status']);
    }

    public function test_confirmed_variant_resolves_the_mine_requirement_in_both_directions(): void
    {
        $resolver = app(MiningExamIdentityResolver::class);
        $first = $this->exam('ESPACIO CONFINADO');
        $second = $this->exam('ESPACIOS CONFINADOS');
        $this->associate($second, $first);
        $mine = $this->mine();
        $this->requirement($mine, $second);
        $this->assertSame($second->id, $resolver->resolve('ESPACIO CONFINADO', $mine->id)['examen_id']);
        $this->assertSame($first->id, $resolver->canonicalId($second->id));
        $other = $this->mine();
        $this->requirement($other, $first);
        $this->assertSame($first->id, $resolver->resolve('ESPACIOS CONFINADOS', $other->id)['examen_id']);
    }

    public function test_two_active_requirements_of_a_group_are_ambiguous(): void
    {
        $resolver = app(MiningExamIdentityResolver::class);
        $first = $this->exam('CURSO DE IZAJE');
        $second = $this->exam('CURSO DE IZAGE');
        $this->associate($second, $first);
        $mine = $this->mine();
        $this->requirement($mine, $first);
        $this->requirement($mine, $second);
        $this->assertSame('ambiguous', $resolver->resolve('CURSO DE IZAGE', $mine->id)['status']);
    }

    public function test_alias_scope_and_foreign_name_collision_are_respected(): void
    {
        $resolver = app(MiningExamIdentityResolver::class);
        $first = $this->exam('Curso seguro');
        $mine = $this->mine();
        DB::table('examen_minero_aliases')->insert(['id' => (string) Str::uuid(), 'examen_id' => $first->id,
            'nombre' => 'Curso alternativo', 'clave' => hash('sha256', 'CURSO ALTERNATIVO'),
            'mina_id' => $mine->id, 'scope_key' => $mine->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame($first->id, $resolver->resolve('Curso alternativo', $mine->id)['examen_id']);
        $this->assertSame('missing', $resolver->resolve('Curso alternativo', $this->mine()->id)['status']);
        $this->exam('Curso alternativo');
        $this->assertSame('ambiguous', $resolver->resolve('Curso alternativo', $mine->id)['status']);
    }

    public function test_missing_schema_falls_back_to_exact_without_500(): void
    {
        $resolver = app(MiningExamIdentityResolver::class);
        $first = $this->exam('Curso legado');
        Schema::shouldReceive('hasTable')->with('examen_minero_asociaciones')->andReturn(false);
        $this->assertFalse($resolver->schemaReady());
        $this->assertSame($first->id, $resolver->resolve('CURSO LEGADO')['examen_id']);
    }
}
