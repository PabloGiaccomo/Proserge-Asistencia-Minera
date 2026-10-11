<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalFicha;
use App\Models\PersonalFichaArchivo;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalRegularizationDashboardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dashboard_lists_missing_data_and_documents_without_exposing_file_paths(): void
    {
        $this->authenticateWithPermissions(PermissionCatalog::fullAccessMatrix());
        $personal = $this->createPersonal('ACTIVO', 'TRABAJADOR REGULARIZACION');
        $ficha = PersonalFicha::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'tipo_documento' => 'DNI',
            'numero_documento' => $personal->dni,
            'datos_json' => [
                'nombres' => 'TRABAJADOR',
                'apellido_paterno' => 'REGULARIZACION',
                'tipo_documento' => 'DNI',
                'numero_documento' => $personal->dni,
            ],
        ]);
        PersonalFichaArchivo::query()->create([
            'id' => (string) Str::uuid(),
            'personal_ficha_id' => $ficha->id,
            'tipo' => 'cv_documentado',
            'nombre_original' => 'documento-privado.pdf',
            'path' => 'privado/trabajador/documento-privado.pdf',
            'mime' => 'application/pdf',
            'size' => 1200,
            'uploaded_by_public' => false,
        ]);

        $response = $this->get(route('personal.regularizacion.index'));

        $response->assertOk()
            ->assertSee('TRABAJADOR REGULARIZACION')
            ->assertSee('Completar ficha')
            ->assertSee('Revisar documentos')
            ->assertSee('Por revisar')
            ->assertDontSee('documento-privado.pdf')
            ->assertDontSee('privado/trabajador');
    }

    public function test_ceased_section_links_to_reentry_without_creating_or_changing_a_contract(): void
    {
        $this->authenticateWithPermissions(PermissionCatalog::fullAccessMatrix());
        $personal = $this->createPersonal('CESADO', 'TRABAJADOR CESADO');
        PersonalContrato::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'contrato_numero' => 1,
            'estado' => PersonalContrato::ESTADO_CESADO,
            'fecha_inicio' => '2025-01-01',
            'fecha_fin' => '2025-06-30',
            'tipo_contrato' => 'FIJO',
        ]);
        $before = PersonalContrato::query()->where('personal_id', $personal->id)->count();

        $response = $this->get(route('personal.regularizacion.index', [
            'section' => 'ceased',
            'q' => $personal->dni,
        ]));

        $response->assertOk()
            ->assertSee('TRABAJADOR CESADO')
            ->assertSee('Crear nuevo contrato')
            ->assertSee(route('personal.contratos.index', ['id' => $personal->id, 'accion' => 'reingreso']) . '#movimiento-contractual', false);
        $this->assertSame($before, PersonalContrato::query()->where('personal_id', $personal->id)->count());
        $this->assertSame('CESADO', $personal->fresh()->estado);
    }

    public function test_fragment_refresh_returns_rendered_results_and_does_not_cache(): void
    {
        $this->authenticateWithPermissions(PermissionCatalog::fullAccessMatrix());
        $this->createPersonal('ACTIVO', 'TRABAJADOR EN VIVO');

        $response = $this->getJson(route('personal.regularizacion.index', ['fragment' => 1]));

        $response->assertOk()
            ->assertJsonStructure(['html', 'updated_at'])
            ->assertJsonPath('updated_at', now()->format('H:i:s'));
        $this->assertStringContainsString('TRABAJADOR EN VIVO', $response->json('html'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_dashboard_requires_a_regularization_permission(): void
    {
        $permissions = PermissionCatalog::emptyMatrix();
        $permissions['personal']['ver'] = true;
        $this->authenticateWithPermissions($permissions);

        $this->get(route('personal.regularizacion.index'))->assertForbidden();
    }

    private function createPersonal(string $state, string $name): Personal
    {
        $dni = (string) random_int(10000000, 99999999);

        return Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => $dni,
            'tipo_documento' => 'DNI',
            'numero_documento' => $dni,
            'nombre_completo' => $name,
            'puesto' => 'OPERARIO DE PRUEBA',
            'qr_code' => 'TEST-' . Str::uuid(),
            'estado' => $state,
            'fecha_cese' => $state === 'CESADO' ? '2025-06-30' : null,
            'pendiente_regularizacion' => $state !== 'CESADO',
            'pendiente_contrato_firmado' => $state !== 'CESADO',
        ]);
    }

    private function authenticateWithPermissions(array $permissions): void
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'ROL REGULARIZACION ' . Str::random(8),
            'descripcion' => 'Prueba automatizada',
            'permisos' => json_encode($permissions, JSON_THROW_ON_ERROR),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(10)) . '@proserge.test',
            'password' => bcrypt('secret-test'),
            'rol_id' => $roleId,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession([
            'auth_token' => 'regularization-test-token',
            'user_id' => $userId,
            'user' => ['id' => $userId, 'permissions' => $permissions],
        ]);
    }
}
