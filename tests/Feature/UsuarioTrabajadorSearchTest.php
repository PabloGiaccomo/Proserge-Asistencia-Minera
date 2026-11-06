<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalFicha;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Seguridad\Services\UsuarioTrabajadorSearchService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class UsuarioTrabajadorSearchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_create_permission_returns_only_worker_identity_without_sensitive_data_or_email(): void
    {
        $operator = $this->operator(['crear']);
        $worker = $this->worker(['correo' => 'correo-privado@example.test']);
        $this->ficha($worker, [
            'nombres' => 'Nombreprivado',
            'apellido_paterno' => 'Apellidoprivado',
            'numero_cuenta' => 'CUENTA_PRIVADA_987654321',
            'cuspp' => 'CUSPP_PRIVADO_123456789',
        ]);
        $this->signIn($operator);

        $response = $this->getJson(route('usuarios.buscar-trabajadores', ['search' => $worker->dni]))
            ->assertOk()
            ->assertJsonCount(1, 'trabajadores')
            ->assertJsonPath('trabajadores.0.id', $worker->id)
            ->assertJsonPath('trabajadores.0.correo_sugerido', null);

        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame([
            'id', 'nombre', 'dni', 'puesto', 'correo_sugerido', 'tiene_usuario', 'seleccionar_url',
        ], array_keys($response->json('trabajadores.0')));
        foreach (['CUENTA_PRIVADA_987654321', 'CUSPP_PRIVADO_123456789', 'FIRMA_PRIVADA', 'HUELLA_PRIVADA', 'Nombreprivado', 'correo-privado@example.test'] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $response->getContent());
        }
    }

    public function test_search_requires_create_permission_even_if_assign_permission_exists(): void
    {
        $this->signIn($this->operator(['asignar']));

        $this->getJson(route('usuarios.buscar-trabajadores', ['search' => 'trabajador']))
            ->assertForbidden();
    }

    public function test_email_suggestion_uses_the_latest_ficha_without_exposing_its_other_fields(): void
    {
        $worker = $this->worker(['correo' => 'fallback@example.test']);
        $this->ficha($worker, ['nombres' => 'Anterior', 'apellido_paterno' => 'Archivado'], [
            'id' => '0'.substr((string) Str::uuid(), 1),
            'created_at' => now()->subDay(),
        ]);
        $this->ficha($worker, [
            'nombres' => 'María Elena',
            'apellido_paterno' => 'Del Río',
            'numero_cuenta' => 'CUENTA_QUE_NO_SE_DEBE_EXPORTAR',
        ], [
            'id' => 'f'.substr((string) Str::uuid(), 1),
        ]);
        $this->signIn($this->operator());

        $this->getJson(route('usuarios.buscar-trabajadores', ['search' => $worker->dni]))
            ->assertOk()
            ->assertJsonCount(1, 'trabajadores')
            ->assertJsonPath('trabajadores.0.correo_sugerido', 'maria.delrio@proserge.com')
            ->assertDontSee('CUENTA_QUE_NO_SE_DEBE_EXPORTAR')
            ->assertDontSee('FIRMA_PRIVADA')
            ->assertDontSee('HUELLA_PRIVADA');
    }

    public function test_search_matches_documents_and_words_from_name_and_job_in_any_order(): void
    {
        $marker = 'BUSQUEDA'.Str::upper(Str::random(12));
        $worker = $this->worker([
            'nombre_completo' => $marker.' ELENA TORRES',
            'puesto' => 'Soldadora de altura',
            'numero_documento' => 'DOC-'.Str::upper(Str::random(12)),
        ]);
        $this->worker(['nombre_completo' => $marker.' ELENA RAMOS', 'puesto' => 'Ayudante']);
        $this->signIn($this->operator());

        foreach ([
            $worker->dni,
            $worker->numero_documento,
            $marker.' solda',
            'altura '.$marker.' torres',
            'torres '.$marker.' elen',
        ] as $search) {
            $this->getJson(route('usuarios.buscar-trabajadores', ['search' => $search]))
                ->assertOk()
                ->assertJsonCount(1, 'trabajadores')
                ->assertJsonPath('trabajadores.0.id', $worker->id);
        }
    }

    public function test_search_limits_results_and_rejects_short_queries_without_listing_workers(): void
    {
        $marker = 'LIMITE'.Str::upper(Str::random(12));
        for ($i = 1; $i <= 23; $i++) {
            $this->worker(['nombre_completo' => $marker.' '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }
        $this->signIn($this->operator());

        $this->getJson(route('usuarios.buscar-trabajadores', ['search' => $marker]))
            ->assertOk()
            ->assertJsonCount(20, 'trabajadores');

        foreach (['', ' ', 'a'] as $search) {
            $this->getJson(route('usuarios.buscar-trabajadores', ['search' => $search]))
                ->assertOk()
                ->assertExactJson(['trabajadores' => []]);
        }
    }

    public function test_valid_fallback_email_and_existing_account_status_survive_recovery_by_ids(): void
    {
        $first = $this->worker(['correo' => '  FALLBACK@example.test  ']);
        $invalid = $this->worker(['correo' => 'correo invalido']);
        $linked = $this->worker(['correo' => 'asociado@example.test']);
        $operator = $this->operator();
        $operator->update(['personal_id' => $linked->id]);
        $this->signIn($operator);

        $rows = app(UsuarioTrabajadorSearchService::class)->rowsForIds([
            $linked->id, (string) Str::uuid(), $first->id, $invalid->id, $first->id,
        ]);

        self::assertSame([$linked->id, $first->id, $invalid->id], array_column($rows, 'id'));
        self::assertTrue($rows[0]['tiene_usuario']);
        self::assertNull($rows[0]['correo_sugerido']);
        self::assertSame('fallback@example.test', $rows[1]['correo_sugerido']);
        self::assertNull($rows[2]['correo_sugerido']);

        $this->getJson(route('usuarios.buscar-trabajadores', ['search' => $linked->dni]))
            ->assertOk()
            ->assertJsonPath('trabajadores.0.tiene_usuario', true)
            ->assertJsonPath('trabajadores.0.correo_sugerido', null);
    }

    public function test_individual_search_keeps_a_get_fallback_and_selects_the_worker_by_link(): void
    {
        $worker = $this->worker(['correo' => 'seleccion@example.test']);
        $this->signIn($this->operator());

        $response = $this->get(route('usuarios.create', ['search' => $worker->dni]))
            ->assertOk()
            ->assertSee('data-user-worker-search', false);
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        $form = '//form[@data-user-worker-search-form]';
        self::assertSame(1, $xpath->query($form.'[@method="GET"]/div/input[@name="search"]')->length);
        self::assertSame(0, $xpath->query($form.'//button[not(ancestor::noscript)]')->length);
        self::assertSame(1, $xpath->query($form.'//noscript/button[@type="submit"]')->length);
        $selection = $xpath->query('//*[@data-user-worker-search-results]//a')->item(0);
        self::assertNotNull($selection);
        self::assertSame(route('usuarios.create', ['trabajador_id' => $worker->id, 'search' => $worker->dni]), $selection->getAttribute('href'));

        $this->get($selection->getAttribute('href'))
            ->assertOk()
            ->assertSee('name="personal_id" value="'.$worker->id.'"', false)
            ->assertSee('value="seleccion@example.test"', false)
            ->assertSee('Crear varios');
    }

    private function worker(array $overrides = []): Personal
    {
        $document = (string) random_int(10000000, 99999999);

        return Personal::query()->create(array_replace([
            'id' => (string) Str::uuid(),
            'dni' => $document,
            'numero_documento' => $document,
            'nombre_completo' => 'TRABAJADOR SINTETICO '.Str::upper(Str::random(12)),
            'puesto' => 'Operario',
            'qr_code' => (string) Str::uuid(),
            'estado' => 'PENDIENTE',
        ], $overrides));
    }

    private function ficha(Personal $worker, array $data, array $overrides = []): void
    {
        PersonalFicha::query()->create(array_replace([
            'id' => (string) Str::uuid(),
            'personal_id' => $worker->id,
            'estado' => PersonalFicha::ESTADO_APROBADO,
            'tipo_documento' => 'DNI',
            'numero_documento' => $worker->dni,
            'datos_json' => $data,
            'firma_base64' => 'data:image/png;base64,FIRMA_PRIVADA',
            'huella_path' => 'private/HUELLA_PRIVADA.png',
        ], $overrides));
    }

    private function operator(array $actions = ['crear', 'asignar']): Usuario
    {
        $role = Rol::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => 'PRUEBA_BUSCADOR_'.Str::upper(Str::random(12)),
            'estado' => 'ACTIVO',
            'permisos' => PermissionCatalog::matrixFromSelections(['usuarios' => $actions]),
        ]);

        return Usuario::query()->create([
            'id' => (string) Str::uuid(),
            'rol_id' => $role->id,
            'email' => Str::uuid().'@test.local',
            'password' => 'synthetic-password',
            'estado' => 'ACTIVO',
        ]);
    }

    private function signIn(Usuario $operator): void
    {
        $this->withSession([
            'auth_token' => 'test-token',
            'user_id' => $operator->id,
            'user' => ['id' => $operator->id],
        ]);
    }
}
