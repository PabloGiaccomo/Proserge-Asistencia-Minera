<?php

namespace Tests\Feature;

use App\Models\RQMina;
use App\Models\Usuario;
use App\Modules\ParadaHerramientas\Services\ParadaHerramientaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParadaHerramientaServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guarda_y_envia_lista_de_herramientas_por_grupo(): void
    {
        $rolId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $rolId,
            'nombre' => 'OPERACIONES',
            'permisos' => json_encode([
                'man_power' => [
                    'ver' => true,
                    'actualizar' => true,
                    'administrar' => false,
                ],
            ]),
            'estado' => 'ACTIVO',
        ]);

        $minaId = (string) Str::uuid();
        DB::table('minas')->insert([
            'id' => $minaId,
            'nombre' => 'Mina Test Herramientas',
            'unidad_minera' => 'UM TEST',
            'estado' => 'ACTIVO',
        ]);

        $usuarioId = (string) Str::uuid();
        DB::table('usuarios')->insert([
            'id' => $usuarioId,
            'email' => 'herramientas+'.Str::lower(Str::random(6)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $rolId,
            'personal_id' => null,
            'estado' => 'ACTIVO',
        ]);

        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuarioId,
            'mina_id' => $minaId,
        ]);

        $rqId = (string) Str::uuid();
        DB::table('rq_mina')->insert([
            'id' => $rqId,
            'mina_id' => $minaId,
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'destino_nombre' => 'Mina Test Herramientas',
            'area' => 'Parada semanal',
            'fecha_inicio' => now()->addDays(12)->toDateString(),
            'fecha_fin' => now()->addDays(14)->toDateString(),
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $usuarioId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $usuario = Usuario::query()->with('rol')->findOrFail($usuarioId);
        $rq = RQMina::query()->with(['mina:id,nombre', 'gruposTrabajo'])->findOrFail($rqId);
        $service = app(ParadaHerramientaService::class);

        $result = $service->saveLista($usuario, $rq, [
            'observaciones' => 'Lista semanal',
            'grupos' => [
                [
                    'nombre' => 'Grupo mecanico',
                    'base' => [
                        ['descripcion' => 'Llave mixta 19', 'cantidad_solicitada' => 2],
                    ],
                    'adicional' => [
                        ['descripcion' => 'Taladro percutor', 'cantidad_solicitada' => 1, 'observaciones' => 'Bateria cargada'],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('parada_herramienta_listas', [
            'rq_mina_id' => $rqId,
            'estado' => 'BORRADOR',
        ]);
        $this->assertDatabaseHas('parada_herramienta_items', [
            'tipo' => 'BASE',
            'descripcion' => 'Llave mixta 19',
            'cantidad_solicitada' => 2,
        ]);
        $this->assertDatabaseHas('parada_herramienta_items', [
            'tipo' => 'ADICIONAL',
            'descripcion' => 'Taladro percutor',
            'cantidad_solicitada' => 1,
        ]);

        $sendResult = $service->enviarLista($usuario, $rq->fresh(['mina:id,nombre', 'gruposTrabajo', 'listaHerramientas.grupos.items']));

        $this->assertTrue($sendResult['ok']);
        $this->assertDatabaseHas('parada_herramienta_listas', [
            'rq_mina_id' => $rqId,
            'estado' => 'ENVIADO',
        ]);
    }
}
