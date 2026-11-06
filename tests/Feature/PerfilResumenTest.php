<?php

namespace Tests\Feature;

use App\Models\EvaluacionDesempeno;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioMinaScope;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerfilResumenTest extends TestCase
{
    use DatabaseTransactions;

    public function test_profile_separates_account_scope_from_worker_qualification(): void
    {
        $person = $this->person();
        $actor = $this->login($person, []);
        $scope = $this->mine('Acceso de prueba');
        UsuarioMinaScope::query()->create(['id' => (string) Str::uuid(), 'mina_id' => $scope->id, 'usuario_id' => $actor->id]);
        foreach (['En trámite' => 'EN_PROCESO', 'Observación final' => 'NO_HABILITADO', 'Vigente' => 'HABILITADO', 'Asignación cerrada' => 'HABILITADO'] as $name => $status) {
            $mine = $this->mine($name);
            PersonalMina::query()->create([
                'id' => (string) Str::uuid(), 'personal_id' => $person->id, 'mina_id' => $mine->id,
                'estado' => $status, 'estado_habilitacion' => $status, 'activo' => $name !== 'Asignación cerrada',
            ]);
        }

        $this->get('/perfil')->assertOk()->assertSeeInOrder(['Alcance por mina', 'Acceso de prueba', 'Habilitación del trabajador'])
            ->assertSee('En proceso')->assertSee('No habilitado')->assertSee('Habilitado')->assertSee('Asignación inactiva')
            ->assertSee('Evaluaciones no disponibles')->assertDontSee('Minas Habilitadas');
    }

    public function test_profile_aggregates_only_authorized_daily_evaluations_for_linked_worker(): void
    {
        $person = $this->person();
        $actor = $this->login($person, ['ver_desempeno', 'ver_todas_evaluaciones']);
        $visible = $this->mine('Permitida');
        $hidden = $this->mine('Fuera de alcance');
        UsuarioMinaScope::query()->create(['id' => (string) Str::uuid(), 'mina_id' => $visible->id, 'usuario_id' => $actor->id]);
        $this->get('/perfil')->assertOk()->assertSee('Sin evaluaciones disponibles para tu trabajador.');
        foreach ([[$person, $visible, 17], [$person, $hidden, 5], [$this->person(), $visible, 3]] as [$target, $mina, $score]) {
            EvaluacionDesempeno::query()->create([
                'id' => (string) Str::uuid(), 'fecha' => '2026-06-08', 'hora' => '10:00', 'mina_id' => $mina->id,
                'destino_tipo' => 'MINA', 'destino_id' => $mina->id, 'supervisor_id' => $person->id, 'trabajador_id' => $target->id,
                'desempeno_trabajo' => 3, 'orden_limpieza' => 3, 'compromiso' => 3,
                'respuesta_emocional' => 3, 'seguridad_trabajo' => 3, 'total' => $score,
            ]);
        }
        $this->get('/perfil')->assertOk()->assertViewHas('evaluacionesResumen', [
            'total' => 1, 'promedio' => '17.00', 'ultima' => '08/06/2026',
        ])->assertDontSee('Fuera de alcance');
    }

    private function login(Personal $person, array $evaluations): Usuario
    {
        $role = Rol::query()->create([
            'id' => (string) Str::uuid(), 'nombre' => 'PERFIL_PRUEBA_'.Str::random(8), 'estado' => 'ACTIVO',
            'permisos' => PermissionCatalog::matrixFromSelections(['perfil' => ['ver'], 'evaluaciones' => $evaluations]),
        ]);
        $actor = Usuario::query()->create([
            'id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'synthetic-password',
            'estado' => 'ACTIVO', 'rol_id' => $role->id, 'personal_id' => $person->id,
        ]);
        $this->withSession(['auth_token' => 'synthetic', 'user_id' => $actor->id, 'user' => ['id' => $actor->id]]);

        return $actor;
    }

    private function person(): Personal
    {
        return Personal::query()->create([
            'id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999), 'estado' => 'CESADO',
            'nombre_completo' => 'Trabajador de prueba', 'puesto' => 'Operario', 'qr_code' => (string) Str::uuid(),
        ]);
    }

    private function mine(string $name): Mina
    {
        return Mina::query()->create([
            'id' => (string) Str::uuid(), 'nombre' => $name, 'unidad_minera' => 'Unidad de prueba', 'estado' => 'ACTIVO',
        ]);
    }
}
