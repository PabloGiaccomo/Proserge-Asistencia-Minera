<?php

namespace Tests\Feature;

use App\Models\RQMina;
use App\Models\Usuario;
use App\Modules\ParadaHerramientas\Services\ParadaHerramientaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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

    public function test_importa_formato_excel_de_herramientas_y_consumibles_por_grupo(): void
    {
        $rolId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $rolId,
            'nombre' => 'PLANEAMIENTO',
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
            'nombre' => 'Mina Test Formato',
            'unidad_minera' => 'UM TEST',
            'estado' => 'ACTIVO',
        ]);

        $usuarioId = (string) Str::uuid();
        DB::table('usuarios')->insert([
            'id' => $usuarioId,
            'email' => 'formato-herramientas+'.Str::lower(Str::random(6)).'@test.local',
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
            'destino_nombre' => 'Mina Test Formato',
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
        $lista = $service->ensureLista($rq, $usuario);
        $grupoId = (string) $lista->grupos->first()->id;
        $path = $this->writeHerramientasFormato([
            ['descripcion' => 'Llave mixta 19', 'cantidad' => 2, 'observacion' => 'Base'],
            ['descripcion' => 'Taladro percutor', 'cantidad' => 0, 'observacion' => 'Sin cantidad definida'],
        ], [
            ['descripcion' => 'Disco de corte 4', 'cantidad' => 5, 'unidad' => 'UND', 'observacion' => 'Consumible base'],
        ]);

        try {
            $upload = new UploadedFile(
                $path,
                'FORMATO DE RQ HERRAMIENTAS Y CONSUMIBLES.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            );

            $result = $service->importarFormatoGrupo($usuario, $rq, $grupoId, $upload);
        } finally {
            @unlink($path);
        }

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('parada_herramienta_items', [
            'grupo_id' => $grupoId,
            'tipo' => 'BASE',
            'categoria' => 'HERRAMIENTA',
            'descripcion' => 'Llave mixta 19',
            'cantidad_solicitada' => 2,
        ]);
        $this->assertDatabaseHas('parada_herramienta_items', [
            'grupo_id' => $grupoId,
            'tipo' => 'BASE',
            'categoria' => 'HERRAMIENTA',
            'descripcion' => 'Taladro percutor',
            'cantidad_solicitada' => 0,
        ]);
        $this->assertDatabaseHas('parada_herramienta_items', [
            'grupo_id' => $grupoId,
            'tipo' => 'BASE',
            'categoria' => 'CONSUMIBLE',
            'descripcion' => 'Disco de corte 4',
            'cantidad_solicitada' => 5,
            'unidad' => 'UND',
        ]);

        $secondPath = $this->writeHerramientasFormato([
            ['descripcion' => 'Llave mixta 19', 'cantidad' => 3, 'observacion' => 'Actualizada'],
        ], [
            ['descripcion' => 'Disco de corte 4', 'cantidad' => 7, 'unidad' => 'UND', 'observacion' => 'Actualizado'],
        ]);

        try {
            $secondUpload = new UploadedFile(
                $secondPath,
                'FORMATO ACTUALIZADO.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            );

            $secondResult = $service->importarFormatoGrupo($usuario, $rq, $grupoId, $secondUpload);
        } finally {
            @unlink($secondPath);
        }

        $this->assertTrue($secondResult['ok']);
        $this->assertDatabaseHas('parada_herramienta_items', [
            'grupo_id' => $grupoId,
            'tipo' => 'BASE',
            'categoria' => 'HERRAMIENTA',
            'descripcion' => 'Llave mixta 19',
            'cantidad_solicitada' => 3,
        ]);
        $this->assertDatabaseMissing('parada_herramienta_items', [
            'grupo_id' => $grupoId,
            'tipo' => 'BASE',
            'categoria' => 'HERRAMIENTA',
            'descripcion' => 'Taladro percutor',
        ]);
        $this->assertDatabaseHas('parada_herramienta_items', [
            'grupo_id' => $grupoId,
            'tipo' => 'BASE',
            'categoria' => 'CONSUMIBLE',
            'descripcion' => 'Disco de corte 4',
            'cantidad_solicitada' => 7,
            'unidad' => 'UND',
        ]);
    }

    public function test_importa_catalogo_global_y_recomienda_observaciones_por_descripcion(): void
    {
        [$usuario, $rq] = $this->createToolsContext('CATALOGO HERRAMIENTAS', [
            'herramientas' => [
                'ver' => true,
                'actualizar' => true,
                'administrar' => false,
            ],
        ]);

        $service = app(ParadaHerramientaService::class);
        $path = $this->writeHerramientasFormato([
            ['descripcion' => 'Eslinga de carga', 'cantidad' => 1, 'observacion' => 'Certificado vigente'],
        ], [
            ['descripcion' => 'Disco de corte 4', 'cantidad' => 20, 'unidad' => 'UND', 'observacion' => 'Consumible base'],
        ]);

        try {
            $upload = new UploadedFile(
                $path,
                'FORMATO DE RQ HERRAMIENTAS Y CONSUMIBLES.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            );

            $result = $service->importarCatalogo($usuario, $upload);
        } finally {
            @unlink($path);
        }

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('parada_herramienta_catalogos', [
            'categoria' => 'HERRAMIENTA',
            'descripcion' => 'Eslinga de carga',
        ]);
        $this->assertDatabaseHas('parada_herramienta_catalogos', [
            'categoria' => 'CONSUMIBLE',
            'descripcion' => 'Disco de corte 4',
            'unidad' => 'UND',
        ]);

        $descriptionSuggestions = collect($service->sugerirCatalogo('eslinga', 'HERRAMIENTA'));
        $this->assertTrue($descriptionSuggestions->contains(fn (array $item): bool => $item['descripcion'] === 'Eslinga de carga'));

        $observations = collect($service->sugerirObservaciones('Eslinga de carga', 'HERRAMIENTA'));
        $this->assertTrue($observations->contains(fn (array $item): bool => $item['observacion'] === 'Certificado vigente'));

        $saveResult = $service->saveLista($usuario, $rq, [
            'grupos' => [
                [
                    'nombre' => 'Grupo mecanico',
                    'base' => [
                        [
                            'descripcion' => 'Eslinga de carga',
                            'cantidad_solicitada' => 2,
                            'observaciones' => 'Revisar grilletes',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($saveResult['ok']);

        $learnedObservations = collect($service->sugerirObservaciones('Eslinga de carga', 'HERRAMIENTA'));
        $this->assertTrue($learnedObservations->contains(fn (array $item): bool => $item['observacion'] === 'Revisar grilletes'));
    }

    public function test_importa_catalogo_global_por_posicion_de_hojas_del_formato(): void
    {
        [$usuario] = $this->createToolsContext('CATALOGO POR HOJAS', [
            'herramientas' => [
                'ver' => true,
                'actualizar' => true,
                'administrar' => false,
            ],
        ]);

        $service = app(ParadaHerramientaService::class);
        DB::table('parada_herramienta_catalogos')->insert([
            'id' => (string) Str::uuid(),
            'categoria' => 'CONSUMIBLE',
            'descripcion' => 'Consumible existente que no viene',
            'descripcion_normalizada' => 'CONSUMIBLE EXISTENTE QUE NO VIENE',
            'unidad' => 'UND',
            'unidad_normalizada' => 'UND',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $path = $this->writeHerramientasFormato([
            ['descripcion' => 'Llave stilson', 'cantidad' => 1],
            ['descripcion' => 'Tecle de cadena', 'cantidad' => 1],
        ], [
            ['descripcion' => 'Trapo industrial', 'cantidad' => 10, 'unidad' => 'KG'],
        ], [
            ['descripcion' => 'Consumible hoja auxiliar', 'unidad' => 'UND'],
        ]);

        try {
            $upload = new UploadedFile(
                $path,
                'FORMATO DE RQ HERRAMIENTAS Y CONSUMIBLES.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            );

            $result = $service->importarCatalogo($usuario, $upload);
        } finally {
            @unlink($path);
        }

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['summary']['herramientas']);
        $this->assertSame(2, $result['summary']['consumibles']);
        $this->assertDatabaseHas('parada_herramienta_catalogos', [
            'categoria' => 'HERRAMIENTA',
            'descripcion' => 'Llave stilson',
        ]);
        $this->assertDatabaseHas('parada_herramienta_catalogos', [
            'categoria' => 'HERRAMIENTA',
            'descripcion' => 'Tecle de cadena',
        ]);
        $this->assertDatabaseHas('parada_herramienta_catalogos', [
            'categoria' => 'CONSUMIBLE',
            'descripcion' => 'Trapo industrial',
            'unidad' => 'KG',
        ]);
        $this->assertDatabaseHas('parada_herramienta_catalogos', [
            'categoria' => 'CONSUMIBLE',
            'descripcion' => 'Consumible hoja auxiliar',
            'unidad' => 'UND',
        ]);
        $this->assertDatabaseHas('parada_herramienta_catalogos', [
            'categoria' => 'CONSUMIBLE',
            'descripcion' => 'Consumible existente que no viene',
            'activo' => true,
        ]);
        $this->assertDatabaseMissing('parada_herramienta_catalogos', [
            'descripcion' => 'Cant. Recibida',
        ]);
    }

    public function test_actualiza_cantidades_entregadas_y_recibidas_del_pedido(): void
    {
        [$usuario, $rq] = $this->createToolsContext('LOGISTICA', [
            'herramientas' => [
                'ver' => true,
                'actualizar' => true,
                'administrar' => false,
            ],
        ]);

        $service = app(ParadaHerramientaService::class);
        $saveResult = $service->saveLista($usuario, $rq, [
            'grupos' => [
                [
                    'nombre' => 'Grupo mecanico',
                    'base' => [
                        ['descripcion' => 'Llave mixta 19', 'cantidad_solicitada' => 5],
                    ],
                    'consumibles_base' => [
                        ['descripcion' => 'Disco de corte', 'cantidad_solicitada' => 8, 'unidad' => 'UND'],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($saveResult['ok']);

        $items = DB::table('parada_herramienta_items')
            ->whereIn('descripcion', ['Llave mixta 19', 'Disco de corte'])
            ->pluck('id', 'descripcion');

        $result = $service->updatePedido($usuario, $rq->fresh(['mina:id,nombre', 'gruposTrabajo', 'listaHerramientas.grupos.items']), [
            'grupos' => [
                [
                    'base' => [
                        [
                            'id' => $items['Llave mixta 19'],
                            'cantidad_entregada' => 3,
                            'cantidad_recibida' => 2,
                        ],
                    ],
                    'consumibles_base' => [
                        [
                            'id' => $items['Disco de corte'],
                            'cantidad_entregada' => 8,
                            'cantidad_recibida' => 7,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('parada_herramienta_items', [
            'id' => $items['Llave mixta 19'],
            'cantidad_entregada' => 3,
            'cantidad_recibida' => 2,
        ]);
        $this->assertDatabaseHas('parada_herramienta_items', [
            'id' => $items['Disco de corte'],
            'cantidad_entregada' => 8,
            'cantidad_recibida' => 7,
        ]);

        $view = $service->toDetailView($rq->fresh(['mina:id,nombre', 'gruposTrabajo', 'listaHerramientas.grupos.items']), $usuario);
        $firstItem = $view['grupos'][0]['base'][0];

        $this->assertSame(5, $firstItem['cantidad_solicitada']);
        $this->assertSame(3, $firstItem['cantidad_entregada']);
        $this->assertSame(2, $firstItem['cantidad_faltante']);
    }

    public function test_modo_entrega_y_recepcion_no_se_pisan_entre_si(): void
    {
        [$usuario, $rq] = $this->createToolsContext('LOGISTICA MODOS', [
            'herramientas' => [
                'ver' => true,
                'actualizar' => true,
                'administrar' => false,
            ],
        ]);

        $service = app(ParadaHerramientaService::class);
        $saveResult = $service->saveLista($usuario, $rq, [
            'grupos' => [
                [
                    'nombre' => 'Grupo electrico',
                    'base' => [
                        ['descripcion' => 'Detector de tension', 'cantidad_solicitada' => 6],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($saveResult['ok']);

        $itemId = DB::table('parada_herramienta_items')
            ->where('descripcion', 'Detector de tension')
            ->value('id');

        DB::table('rq_mina')->where('id', $rq->id)->update([
            'fecha_inicio' => now()->subDays(3)->toDateString(),
            'fecha_fin' => now()->subDay()->toDateString(),
        ]);

        $deliveryResult = $service->updatePedido($usuario, $rq->fresh(['mina:id,nombre', 'gruposTrabajo', 'listaHerramientas.grupos.items']), [
            'modo' => 'entrega',
            'grupos' => [
                [
                    'base' => [
                        [
                            'id' => $itemId,
                            'cantidad_entregada' => 4,
                            'cantidad_recibida' => 99,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($deliveryResult['ok']);
        $this->assertDatabaseHas('parada_herramienta_items', [
            'id' => $itemId,
            'cantidad_entregada' => 4,
            'cantidad_recibida' => 0,
        ]);

        $receptionResult = $service->updatePedido($usuario, $rq->fresh(['mina:id,nombre', 'gruposTrabajo', 'listaHerramientas.grupos.items']), [
            'modo' => 'recepcion',
            'fecha_recepcion' => now()->toDateString(),
            'grupos' => [
                [
                    'base' => [
                        [
                            'id' => $itemId,
                            'cantidad_entregada' => 1,
                            'cantidad_recibida' => 3,
                            'recepcion_estado' => 'INCOMPLETO',
                            'recepcion_observacion' => 'Retorno parcial',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($receptionResult['ok']);
        $this->assertDatabaseHas('parada_herramienta_items', [
            'id' => $itemId,
            'cantidad_entregada' => 4,
            'cantidad_recibida' => 3,
            'recepcion_estado' => 'INCOMPLETO',
            'recepcion_observacion' => 'Retorno parcial',
        ]);
    }

    public function test_entrega_solo_se_registra_cuando_la_parada_inicio_y_guarda_incidencia(): void
    {
        [$usuario, $rq] = $this->createToolsContext('LOGISTICA ENTREGA', [
            'herramientas' => [
                'ver' => true,
                'actualizar' => true,
                'administrar' => false,
            ],
        ]);

        $service = app(ParadaHerramientaService::class);
        $saveResult = $service->saveLista($usuario, $rq, [
            'grupos' => [
                [
                    'nombre' => 'Grupo mecanico',
                    'base' => [
                        ['descripcion' => 'Eslinga', 'cantidad_solicitada' => 5],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($saveResult['ok']);

        $itemId = DB::table('parada_herramienta_items')
            ->where('descripcion', 'Eslinga')
            ->value('id');

        $blockedResult = $service->updatePedido($usuario, $rq->fresh(['mina:id,nombre', 'gruposTrabajo', 'listaHerramientas.grupos.items']), [
            'modo' => 'entrega',
            'grupos' => [
                [
                    'base' => [
                        [
                            'id' => $itemId,
                            'cantidad_entregada' => 2,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($blockedResult['ok']);

        DB::table('rq_mina')->where('id', $rq->id)->update([
            'fecha_inicio' => now()->subDay()->toDateString(),
            'fecha_fin' => now()->addDays(3)->toDateString(),
        ]);

        $deliveryResult = $service->updatePedido($usuario, $rq->fresh(['mina:id,nombre', 'gruposTrabajo', 'listaHerramientas.grupos.items']), [
            'modo' => 'entrega',
            'grupos' => [
                [
                    'base' => [
                        [
                            'id' => $itemId,
                            'cantidad_entregada' => 4,
                            'incidencia_durante_parada' => 'Una eslinga se devolvio por desgaste.',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($deliveryResult['ok']);
        $this->assertDatabaseHas('parada_herramienta_items', [
            'id' => $itemId,
            'cantidad_entregada' => 4,
            'incidencia_durante_parada' => 'Una eslinga se devolvio por desgaste.',
        ]);
    }

    public function test_recepcion_final_guarda_fecha_estado_y_observacion(): void
    {
        [$usuario, $rq] = $this->createToolsContext('LOGISTICA RECEPCION', [
            'herramientas' => [
                'ver' => true,
                'actualizar' => true,
                'administrar' => false,
            ],
        ]);

        $service = app(ParadaHerramientaService::class);
        $saveResult = $service->saveLista($usuario, $rq, [
            'grupos' => [
                [
                    'nombre' => 'Grupo mecanico',
                    'base' => [
                        ['descripcion' => 'Torquimetro', 'cantidad_solicitada' => 2],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($saveResult['ok']);

        $itemId = DB::table('parada_herramienta_items')
            ->where('descripcion', 'Torquimetro')
            ->value('id');

        DB::table('parada_herramienta_items')->where('id', $itemId)->update([
            'cantidad_entregada' => 2,
        ]);
        DB::table('rq_mina')->where('id', $rq->id)->update([
            'fecha_inicio' => now()->subDays(4)->toDateString(),
            'fecha_fin' => now()->subDay()->toDateString(),
        ]);

        $result = $service->updatePedido($usuario, $rq->fresh(['mina:id,nombre', 'gruposTrabajo', 'listaHerramientas.grupos.items']), [
            'modo' => 'recepcion',
            'fecha_recepcion' => now()->subDay()->toDateString(),
            'grupos' => [
                [
                    'base' => [
                        [
                            'id' => $itemId,
                            'cantidad_recibida' => 1,
                            'recepcion_estado' => 'INCOMPLETO',
                            'recepcion_observacion' => 'Regreso con accesorio faltante.',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('parada_herramienta_items', [
            'id' => $itemId,
            'cantidad_entregada' => 2,
            'cantidad_recibida' => 1,
            'recepcion_estado' => 'INCOMPLETO',
            'recepcion_fecha' => now()->subDay()->toDateString(),
            'recepcion_observacion' => 'Regreso con accesorio faltante.',
            'recepcion_registrada_por_usuario_id' => $usuario->id,
        ]);
    }

    public function test_limite_vencido_bloquea_completar_requerimiento(): void
    {
        [$usuario, $rq] = $this->createToolsContext('LOGISTICA LIMITE VENCIDO', [
            'herramientas' => [
                'ver' => true,
                'actualizar' => true,
                'administrar' => false,
            ],
        ]);

        DB::table('rq_mina')->where('id', $rq->id)->update([
            'fecha_inicio' => now()->addDays(3)->toDateString(),
            'fecha_fin' => now()->addDays(9)->toDateString(),
        ]);
        $rq = $rq->fresh(['mina:id,nombre', 'gruposTrabajo']);

        $service = app(ParadaHerramientaService::class);

        $blockedResult = $service->saveLista($usuario, $rq, [
            'grupos' => [
                [
                    'nombre' => 'Grupo mecanico',
                    'base' => [
                        ['descripcion' => 'Llave corona', 'cantidad_solicitada' => 1],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($blockedResult['ok']);
        $this->assertStringContainsString('vencio', $blockedResult['message']);

        $resultWithComment = $service->saveLista($usuario, $rq, [
            'comentario_cambio_previo' => 'Se agrego por solicitud del supervisor antes del arranque.',
            'grupos' => [
                [
                    'nombre' => 'Grupo mecanico',
                    'base' => [
                        ['descripcion' => 'Llave corona', 'cantidad_solicitada' => 1],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($resultWithComment['ok']);
        $this->assertDatabaseMissing('parada_herramienta_items', [
            'descripcion' => 'Llave corona',
        ]);

        $view = $service->toDetailView($rq->fresh(['mina:id,nombre', 'gruposTrabajo']), $usuario);

        $this->assertFalse($view['puede_editar']);
        $this->assertFalse($view['puede_completar_requerimiento']);
        $this->assertTrue($view['limite_envio_vencido']);
    }

    public function test_supervisor_asignado_limita_la_visibilidad_de_herramientas(): void
    {
        $rolId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $rolId,
            'nombre' => 'SUPERVISOR HERRAMIENTAS',
            'permisos' => json_encode([
                'herramientas' => [
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
            'nombre' => 'Mina Scope Supervisor',
            'unidad_minera' => 'UM TEST',
            'estado' => 'ACTIVO',
        ]);

        $supervisorA = $this->insertPersonal('70111111', 'SUPERVISOR A');
        $supervisorB = $this->insertPersonal('70222222', 'SUPERVISOR B');
        $usuarioA = $this->insertUser($rolId, $supervisorA, 'supervisor-a');
        $usuarioB = $this->insertUser($rolId, $supervisorB, 'supervisor-b');

        foreach ([$usuarioA->id, $usuarioB->id] as $usuarioId) {
            DB::table('usuario_mina_scope')->insert([
                'id' => (string) Str::uuid(),
                'usuario_id' => $usuarioId,
                'mina_id' => $minaId,
            ]);
        }

        $assignedRqId = $this->insertRqMina($minaId, $usuarioA->id, 'Parada asignada', $supervisorA);
        $unassignedRqId = $this->insertRqMina($minaId, $usuarioA->id, 'Parada libre', null);

        $service = app(ParadaHerramientaService::class);

        $visibleForA = $service->listParadas($usuarioA)->pluck('rq_mina_id')->all();
        $visibleForB = $service->listParadas($usuarioB)->pluck('rq_mina_id')->all();

        $this->assertContains($assignedRqId, $visibleForA);
        $this->assertContains($unassignedRqId, $visibleForA);
        $this->assertNotContains($assignedRqId, $visibleForB);
        $this->assertContains($unassignedRqId, $visibleForB);
    }

    private function writeHerramientasFormato(array $herramientas, array $consumibles, array $extraConsumibles = []): string
    {
        $spreadsheet = new Spreadsheet();
        $herramientasSheet = $spreadsheet->getActiveSheet();
        $herramientasSheet->setTitle('RQ HRRTS');
        $this->writeFormatoSheet($herramientasSheet, 'Descripcion de Equipos / Herramientas / Utilaje', $herramientas, false);

        $consumiblesSheet = $spreadsheet->createSheet();
        $consumiblesSheet->setTitle('RQ CONSM');
        $this->writeFormatoSheet($consumiblesSheet, 'Descripcion de Consumible', $consumibles, true);

        if ($extraConsumibles !== []) {
            $extraSheet = $spreadsheet->createSheet();
            $extraSheet->setTitle('Hoja1');
            $extraSheet->setCellValue('B3', 'ITEM');
            $extraSheet->setCellValue('C3', 'DESCRIPCION');
            $extraSheet->setCellValue('D3', 'UND');

            foreach (array_values($extraConsumibles) as $index => $row) {
                $excelRow = 4 + $index;
                $extraSheet->setCellValue('B' . $excelRow, $index + 1);
                $extraSheet->setCellValue('C' . $excelRow, $row['descripcion']);
                $extraSheet->setCellValue('D' . $excelRow, $row['unidad'] ?? '');
            }
        }

        $directory = storage_path('app/testing');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory . DIRECTORY_SEPARATOR . 'formato-herramientas-' . Str::uuid() . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function writeFormatoSheet(Worksheet $sheet, string $descriptionHeader, array $rows, bool $includeUnit): void
    {
        $sheet->setCellValue('O12', 'Descripcion');
        $sheet->setCellValue('O13', 'Cant. Recibida');
        $sheet->setCellValue('B15', 'Item');
        $sheet->setCellValue('C15', $descriptionHeader);
        $sheet->setCellValue('G15', 'Cant. Solicitada');
        $sheet->setCellValue('K15', $includeUnit ? 'Unidad' : 'Cant. Entregada');
        $sheet->setCellValue('S15', 'Observacion');

        foreach (array_values($rows) as $index => $row) {
            $excelRow = 16 + $index;
            $sheet->setCellValue('B' . $excelRow, $index + 1);
            $sheet->setCellValue('C' . $excelRow, $row['descripcion']);
            $sheet->setCellValue('G' . $excelRow, $row['cantidad'] ?? 0);
            $sheet->setCellValue('K' . $excelRow, $includeUnit ? ($row['unidad'] ?? '') : '');
            $sheet->setCellValue('S' . $excelRow, $row['observacion'] ?? '');
        }
    }

    private function createToolsContext(string $roleName, array $permissions): array
    {
        $rolId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $rolId,
            'nombre' => $roleName,
            'permisos' => json_encode($permissions),
            'estado' => 'ACTIVO',
        ]);

        $minaId = (string) Str::uuid();
        DB::table('minas')->insert([
            'id' => $minaId,
            'nombre' => 'Mina Test Pedido',
            'unidad_minera' => 'UM TEST',
            'estado' => 'ACTIVO',
        ]);

        $usuarioId = (string) Str::uuid();
        DB::table('usuarios')->insert([
            'id' => $usuarioId,
            'email' => 'pedido-herramientas+'.Str::lower(Str::random(6)).'@test.local',
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

        $rqId = $this->insertRqMina($minaId, $usuarioId, 'Parada pedido', null);

        return [
            Usuario::query()->with('rol')->findOrFail($usuarioId),
            RQMina::query()->with(['mina:id,nombre', 'gruposTrabajo'])->findOrFail($rqId),
        ];
    }

    private function insertPersonal(string $dni, string $nombre): string
    {
        $id = (string) Str::uuid();
        DB::table('personal')->insert([
            'id' => $id,
            'dni' => $dni,
            'nombre_completo' => $nombre,
            'puesto' => 'SUPERVISOR',
            'ocupacion' => 'SUPERVISOR',
            'contrato' => 'FIJO',
            'es_supervisor' => 1,
            'qr_code' => 'QR-' . $dni . '-' . Str::lower(Str::random(4)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertUser(string $rolId, string $personalId, string $prefix): Usuario
    {
        $id = (string) Str::uuid();
        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => $prefix.'+'.Str::lower(Str::random(6)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $rolId,
            'personal_id' => $personalId,
            'estado' => 'ACTIVO',
        ]);

        return Usuario::query()->with('rol')->findOrFail($id);
    }

    private function insertRqMina(string $minaId, string $usuarioId, string $area, ?string $supervisorId): string
    {
        $rqId = (string) Str::uuid();
        DB::table('rq_mina')->insert([
            'id' => $rqId,
            'mina_id' => $minaId,
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'destino_nombre' => 'Mina Test Pedido',
            'supervisor_id' => $supervisorId,
            'area' => $area,
            'fecha_inicio' => now()->addDays(12)->toDateString(),
            'fecha_fin' => now()->addDays(14)->toDateString(),
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $usuarioId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $rqId;
    }
}
