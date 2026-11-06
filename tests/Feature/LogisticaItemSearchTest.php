<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Logistica\Services\LogisticaDashboardService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LogisticaItemSearchTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
    }

    public static function categories(): array
    {
        return [['EPP'], ['HERRAMIENTA'], ['CONSUMIBLE']];
    }

    #[DataProvider('categories')]
    public function test_catalog_search_matches_partial_reordered_name_and_code_within_category(string $category): void
    {
        $tag = Str::upper(Str::random(12));
        $item = $this->item($category, 'Guante anticórte '.$tag, 'SEG_'.$tag.'_01');
        $other = $this->item($category, 'Casco seguridad '.$tag, 'OTRO_'.$tag);
        $this->item($category === 'EPP' ? 'HERRAMIENTA' : 'EPP', 'Guante anticórte '.$tag, 'SEG_OTRO_'.$tag);

        foreach (['anticorte GUAN '.$tag, '  '.$tag.'   Anticórte ', $tag.' seg_ 01', 'seg_'.$tag.'_01'] as $search) {
            $data = app(LogisticaDashboardService::class)->pageData([
                'tab' => 'identificacion', 'ident_categoria' => $category, 'ident_q' => $search,
            ]);
            $this->assertSame([$item], $data['identityRows']->pluck('id')->all(), $search);
            $this->assertSame(trim($search), $data['filters']['ident_q']);
            $this->assertContains($other, $data['identityCatalogRows']->pluck('id')->all(), 'The full category must remain available for live widening');
        }
        foreach (['INEXISTENTE '.$tag, '%'.$tag, '_'.$tag.'%'] as $search) {
            $this->assertEmpty(app(LogisticaDashboardService::class)->pageData([
                'tab' => 'identificacion', 'ident_categoria' => $category, 'ident_q' => $search,
            ])['identityRows']);
        }
        $unfiltered = app(LogisticaDashboardService::class)->pageData([
            'tab' => 'identificacion', 'ident_categoria' => $category, 'q' => 'FILTRO DE TRABAJADORES',
        ]);
        $this->assertContains($item, $unfiltered['identityRows']->pluck('id')->all());
    }

    public function test_search_includes_learned_tools_without_reintroducing_duplicate_names(): void
    {
        $tag = Str::upper(Str::random(12));
        $registered = $this->item('HERRAMIENTA', 'Dado impácto '.$tag, 'PROPIO_'.$tag);
        $this->learnedTool('Dado impacto '.$tag);
        $learned = $this->learnedTool('Adaptador extensión '.$tag);

        $search = fn (string $term) => app(LogisticaDashboardService::class)->pageData([
            'tab' => 'identificacion', 'ident_categoria' => 'HERRAMIENTA', 'ident_q' => $term,
        ])['identityRows'];
        $this->assertSame([$registered], $search('propio_'.$tag)->pluck('id')->all());
        $this->assertSame(['herramienta-catalogo-'.$learned], $search('extension '.$tag)->pluck('id')->all());
        $this->assertSame(['herramienta-catalogo-'.$learned], $search('ADAPTADOR_EXTENSION_'.$tag)->pluck('id')->all());
        $this->assertSame([$registered], $search('impacto '.$tag)->pluck('id')->all());
        $this->assertEmpty($search('DADO_IMPACTO_'.$tag));
    }

    public function test_search_form_keeps_category_and_term_for_read_only_logistics_role(): void
    {
        $tag = Str::upper(Str::random(12));
        $item = $this->item('CONSUMIBLE', 'Disco de corte '.$tag, 'DISCO_'.$tag);
        $role = Rol::create(['id' => (string) Str::uuid(), 'nombre' => 'BUSQUEDA-'.$tag, 'estado' => 'ACTIVO',
            'permisos' => PermissionCatalog::matrixFromSelections(['logistica' => ['ver', 'ver_logistica_identificacion']])]);
        $user = Usuario::create(['id' => (string) Str::uuid(), 'email' => $tag.'@test.local', 'password' => 'test-only-password', 'estado' => 'ACTIVO', 'rol_id' => $role->id]);
        $query = ['tab' => 'identificacion', 'ident_categoria' => 'CONSUMIBLE', 'ident_q' => 'corte '.$tag];
        $response = $this->withSession(['auth_token' => 'test-only-token', 'user_id' => $user->id,
            'user' => ['id' => $user->id, 'permissions' => PermissionCatalog::emptyMatrix()]])
            ->get(route('logistica.index', $query))->assertOk()
            ->assertViewHas('identityRows', fn ($rows) => $rows->pluck('id')->all() === [$item])
            ->assertSee('Buscar ítem')->assertSee('Nombre o código del ítem')
            ->assertSee('1 ítem encontrado')->assertSee('value="corte '.$tag.'"', false)
            ->assertDontSee('data-epp-open-modal="identCatalogModal"', false);
        $response->assertSee('ident_q='.urlencode($query['ident_q']), false);
        $response->assertSee(e(route('logistica.index', ['tab' => 'identificacion', 'ident_categoria' => 'CONSUMIBLE'])), false);
        $this->get(route('logistica.index', [...$query, 'ident_q' => 'NO EXISTE '.$tag]))->assertOk()
            ->assertSee('0 ítems encontrados')->assertSee('No hay ítems que coincidan con')->assertSee('Revisa otra categoría o limpia la búsqueda.');
    }

    private function item(string $category, string $name, string $code): string
    {
        $id = (string) Str::uuid();
        DB::table('epp_registro')->insert(['id' => $id, 'nombre' => $name, 'codigo' => $code, 'categoria' => $category,
            'stock' => 0, 'vida_util_dias' => 30, 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function learnedTool(string $description): string
    {
        $id = (string) Str::uuid();
        DB::table('parada_herramienta_catalogos')->insert(['id' => $id, 'categoria' => 'HERRAMIENTA',
            'descripcion' => $description, 'descripcion_normalizada' => Str::upper(Str::ascii($description)),
            'unidad' => 'UND', 'unidad_normalizada' => 'UND', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
