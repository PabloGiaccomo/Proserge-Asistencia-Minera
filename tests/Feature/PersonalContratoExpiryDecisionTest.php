<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppService;
use App\Modules\Personal\Resources\PersonalIndexResource;
use App\Modules\Personal\Resources\PersonalResource;
use App\Modules\Personal\Services\PersonalContratoService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PersonalContratoExpiryDecisionTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_vista_muestra_contratos_activos_del_mes_filtrado(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $userId = $this->createUser(['vencimientos' => ['ver']]);
        $mayWorker = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Control Vencimiento Mayo']);
        $juneWorker = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Control Vencimiento Junio']);
        $julyWorker = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Control Vencimiento Julio']);
        $this->insertContract($mayWorker, '2026-01-01', '2026-05-20', true, ['area' => 'Mantenimiento', 'puesto' => 'Soldador']);
        $this->insertContract($juneWorker, '2026-01-01', '2026-06-20', true, ['area' => 'Mantenimiento', 'puesto' => 'Soldador']);
        $this->insertContract($julyWorker, '2026-01-01', '2026-07-20', true, ['area' => 'Operaciones', 'puesto' => 'Operario']);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.contratos.expiring', ['mes' => 6, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('Control Vencimiento Junio')
            ->assertDontSee('Control Vencimiento Mayo')
            ->assertDontSee('Control Vencimiento Julio');

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.contratos.expiring', ['mes' => 5, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('Control Vencimiento Mayo')
            ->assertDontSee('Control Vencimiento Junio');
    }

    public function test_vista_resalta_trabajador_en_lista_negra_y_muestra_motivo(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $permissions = ['vencimientos' => ['ver'], 'personal' => ['ver_motivo']];
        $userId = $this->createUser($permissions);
        $worker = $this->createPersonal('ACTIVO', [
            'nombre_completo' => 'Control Lista Negra Vencimiento',
            'numero_documento' => '77112233',
            'dni' => '77112233',
        ]);
        DB::table('personal')
            ->where('id', $worker->id)
            ->update([
                'en_lista_negra' => true,
                'lista_negra_motivo' => 'No considerar en renovaciones sin revision de RRHH.',
                'lista_negra_at' => '2026-06-05 10:30:00',
                'lista_negra_by_usuario_id' => $userId,
                'updated_at' => now(),
            ]);
        $this->insertContract($worker, '2026-01-01', '2026-06-20', true);

        $this->withSession($this->sessionFor($userId, $permissions))
            ->get(route('personal.contratos.expiring', ['mes' => 6, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('Control Lista Negra Vencimiento')
            ->assertSee('blacklisted-worker', false)
            ->assertSee('Lista negra')
            ->assertSee('Ver motivo de lista negra')
            ->assertSee('No considerar en renovaciones sin revision de RRHH.', false);
    }

    public function test_filtros_por_mes_cargo_estado_laboral_y_tipo_contrato(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $target = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Filtro Contrato']);
        $other = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Otro Contrato']);
        $targetContract = $this->insertContract($target, '2026-01-01', '2026-06-20', true, ['area' => 'Mantenimiento', 'puesto' => 'Soldador', 'tipo_contrato' => 'INTERMITENTE']);
        $this->insertContract($other, '2026-01-01', '2026-06-22', true, ['area' => 'Operaciones', 'puesto' => 'Operario', 'tipo_contrato' => 'FIJO']);

        $result = app(PersonalContratoService::class)->listExpiringContracts([
            'mes' => 6,
            'anio' => 2026,
            'cargo' => 'Sold',
            'estado_laboral' => 'ACTIVO',
            'tipo_contrato' => 'INTERMITENTE',
        ]);

        $this->assertCount(1, $result);
        $this->assertSame($targetContract->id, $result->first()->id);
    }

    public function test_filtro_tipo_contrato_reconoce_tipo_guardado_en_trabajador(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $userId = $this->createUser(['vencimientos' => ['ver']]);
        $target = $this->createPersonal('ACTIVO', [
            'nombre_completo' => 'Tipo Desde Trabajador',
            'contrato' => 'Personal fijo / servicio especifico',
        ]);
        $other = $this->createPersonal('ACTIVO', [
            'nombre_completo' => 'Tipo Intermitente Trabajador',
            'contrato' => 'Intermitente',
        ]);

        $targetContract = $this->insertContract($target, '2026-01-01', '2026-06-20', true, ['tipo_contrato' => null]);
        $this->insertContract($other, '2026-01-01', '2026-06-22', true, ['tipo_contrato' => null]);

        $service = app(PersonalContratoService::class);
        $this->assertArrayHasKey('FIJO', $service->contractTypeOptions());
        $this->assertArrayHasKey('INTER', $service->contractTypeOptions());

        $result = $service->listExpiringContracts([
            'mes' => 6,
            'anio' => 2026,
            'tipo_contrato' => 'FIJO',
        ]);

        $this->assertSame([$targetContract->id], $result->pluck('id')->values()->all());

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.contratos.expiring', ['mes' => 6, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('value="FIJO"', false)
            ->assertSee('Personal fijo / servicio especifico')
            ->assertSee('Intermitente');
    }

    public function test_filtro_por_trabajador_ignora_mes_anio_y_muestra_todo_su_historial(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $target = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Busqueda Contratos Trabajador']);
        $other = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Otro Historial Trabajador']);
        $first = $this->insertContract($target, '2026-01-01', '2026-03-31', true, ['estado' => PersonalContrato::ESTADO_CERRADO, 'puesto' => 'Ayudante']);
        $second = $this->insertContract($target, '2026-04-01', '2026-06-30', true, ['puesto' => 'Operador']);
        $third = $this->insertContract($target, '2026-07-01', '2026-12-31', true, ['puesto' => 'Supervisor']);
        $this->insertContract($other, '2026-01-01', '2026-06-30', true, ['puesto' => 'Operario']);

        $result = app(PersonalContratoService::class)->listExpiringContracts([
            'mes' => 6,
            'anio' => 2026,
            'trabajador' => 'Trabajador Busqueda',
            'cargo' => 'No coincide',
            'estado_laboral' => 'CESADO',
            'tipo_contrato' => 'OTRO',
        ]);

        $this->assertSame([$first->id, $second->id, $third->id], $result->pluck('id')->values()->all());
    }

    public function test_vista_de_vencimientos_usa_filtros_automaticos_y_solo_los_operativos(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $userId = $this->createUser(['vencimientos' => ['ver']]);
        $personal = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Vista Filtros']);
        $this->insertContract($personal, '2026-01-01', '2026-06-20', true, ['tipo_contrato' => 'FIJO']);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.contratos.expiring'))
            ->assertOk()
            ->assertSee('name="mes"', false)
            ->assertSee('name="anio"', false)
            ->assertSee('name="trabajador"', false)
            ->assertSee('name="cargo"', false)
            ->assertSee('name="estado_laboral"', false)
            ->assertSee('name="tipo_contrato"', false)
            ->assertSee('data-auto-filter', false)
            ->assertDontSee('name="area"', false)
            ->assertDontSee('name="estado_decision"', false)
            ->assertDontSee('name="estado_contractual"', false)
            ->assertDontSee('>Filtrar<', false);
    }

    public function test_vista_bloquea_mes_y_anio_cuando_se_busca_trabajador(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $userId = $this->createUser(['vencimientos' => ['ver']]);
        $personal = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Filtro Trabajador Vista']);
        $this->insertContract($personal, '2026-01-01', '2026-03-31', true, ['estado' => PersonalContrato::ESTADO_CERRADO]);
        $this->insertContract($personal, '2026-04-01', '2026-06-30', true);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.contratos.expiring', ['mes' => 6, 'anio' => 2026, 'trabajador' => 'Filtro Trabajador']))
            ->assertOk()
            ->assertSee('Historial de contratos del trabajador buscado.')
            ->assertSee('name="mes" data-auto-filter data-date-filter disabled', false)
            ->assertSee('name="anio" min="2000" max="2100" value="2026" data-auto-filter data-date-filter disabled', false)
            ->assertSee('name="trabajador"', false)
            ->assertSee('Filtro Trabajador Vista')
            ->assertSee('Contrato 01/01/2026 al 31/03/2026')
            ->assertSee('Contrato 01/04/2026 al 30/06/2026');
    }

    public function test_vista_de_vencimientos_permite_seleccionar_y_descargar_formato_de_renovacion(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $permissions = [
            'vencimientos' => ['ver', 'registrar', 'renovar'],
            'personal' => ['descargar_formato_contrato'],
        ];
        $userId = $this->createUser($permissions);
        $personal = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Formato Renovacion']);
        $this->insertContract($personal, '2026-01-01', '2026-06-20', true, ['tipo_contrato' => 'FIJO']);

        $this->withSession($this->sessionFor($userId, $permissions))
            ->get(route('personal.contratos.expiring', ['mes' => 6, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('id="expirySelectAllWorkers"', false)
            ->assertSee('js-expiry-contract-worker-check', false)
            ->assertSee('Renovacion de contrato')
            ->assertSee('id="expiryDecisionModal"', false)
            ->assertSee('js-expiry-open-decision', false)
            ->assertSee('Tipo de contrato')
            ->assertDontSee('<summary>Registrar decision</summary>', false)
            ->assertSee('id="contractFormatModal"', false)
            ->assertSee(route('personal.contrato-formatos.download'), false)
            ->assertSee('Formato Renovacion');
    }

    public function test_vencimientos_muestra_historial_anterior_al_pasar_por_trabajador(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $permissions = ['vencimientos' => ['ver', 'registrar']];
        $userId = $this->createUser($permissions);
        $personal = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Historial Hover']);
        $this->insertContract($personal, '2026-01-01', '2026-03-31', true, [
            'estado' => PersonalContrato::ESTADO_CERRADO,
            'puesto' => 'Ayudante Mina',
            'remuneracion' => '1899.75',
            'costo_hora' => '17.25',
        ]);
        $current = $this->insertContract($personal, '2026-04-01', '2026-06-30', true, [
            'puesto' => 'Operador Mina',
            'remuneracion' => '2200',
            'costo_hora' => '20',
        ]);

        $result = app(PersonalContratoService::class)->listExpiringContracts([
            'mes' => 6,
            'anio' => 2026,
        ]);
        $summary = $result->firstWhere('id', $current->id)->getAttribute('previous_contracts_summary');

        $this->assertCount(1, $summary);
        $this->assertSame('Ayudante Mina', $summary->first()['puesto']);
        $this->assertSame('1899.75', $summary->first()['remuneracion']);
        $this->assertSame('17.25', $summary->first()['costo_hora']);

        $this->withSession($this->sessionFor($userId, $permissions))
            ->get(route('personal.contratos.expiring', ['mes' => 6, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('Historial Hover')
            ->assertSee('js-expiry-worker-history', false)
            ->assertSee('Ayudante Mina')
            ->assertSee('1899.75')
            ->assertSee('17.25');
    }

    public function test_no_renovar_requiere_motivo_y_otro_requiere_observacion(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true);
        $service = app(PersonalContratoService::class);

        try {
            $service->registerRenewalDecision($contract, [
                'estado_decision_renovacion' => PersonalContrato::DECISION_NO_RENOVAR,
            ], $actor);
            $this->fail('No debio guardar no renovacion sin motivo.');
        } catch (ValidationException $exception) {
            $this->assertSame('El motivo de no renovacion es obligatorio.', collect($exception->errors())->flatten()->first());
        }

        try {
            $service->registerRenewalDecision($contract, [
                'estado_decision_renovacion' => PersonalContrato::DECISION_NO_RENOVAR,
                'motivo_no_renovacion' => PersonalContrato::MOTIVO_OTRO,
            ], $actor);
            $this->fail('No debio guardar motivo otro sin observacion.');
        } catch (ValidationException $exception) {
            $this->assertSame('La observacion es obligatoria cuando el motivo es otro.', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_guarda_decisiones_renovar_y_no_renovar_sin_cesar_automaticamente(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $renewPersonal = $this->createPersonal('ACTIVO');
        $noRenewPersonal = $this->createPersonal('ACTIVO');
        $renewContract = $this->insertContract($renewPersonal, '2026-01-01', '2026-06-30', true);
        $noRenewContract = $this->insertContract($noRenewPersonal, '2026-01-01', '2026-06-30', true);

        app(PersonalContratoService::class)->registerRenewalDecision($renewContract, [
            'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
        ], $actor);
        app(PersonalContratoService::class)->registerRenewalDecision($noRenewContract, [
            'estado_decision_renovacion' => PersonalContrato::DECISION_NO_RENOVAR,
            'motivo_no_renovacion' => PersonalContrato::MOTIVO_DECISION_AREA,
            'observacion_decision' => 'Decision registrada para control simple.',
        ], $actor);

        $this->assertDatabaseHas('personal_contratos', [
            'id' => $renewContract->id,
            'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
            'decision_final' => PersonalContrato::DECISION_RENOVAR,
        ]);
        $this->assertDatabaseHas('personal_contratos', [
            'id' => $noRenewContract->id,
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'estado_decision_renovacion' => PersonalContrato::DECISION_NO_RENOVAR,
            'decision_final' => PersonalContrato::DECISION_NO_RENOVAR,
            'motivo_no_renovacion' => PersonalContrato::MOTIVO_DECISION_AREA,
        ]);
        $this->assertSame('ACTIVO', $noRenewPersonal->fresh()->estado);
    }

    public function test_prepara_renovacion_desde_decision_sin_desactivar_y_evita_duplicado(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');
        Storage::fake('local');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true, ['puesto' => 'Mecanico']);

        app(PersonalContratoService::class)->registerRenewalDecision($contract, [
            'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
        ], $actor);

        $renewal = app(PersonalContratoService::class)->prepareRenewalFromDecision($contract, [
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-12-31',
            'observacion_renovacion' => 'Preparada desde control de vencimientos.',
        ], $actor);

        $this->assertSame(PersonalContrato::ESTADO_PREPARACION, $renewal->estado);
        $this->assertSame(PersonalContrato::MOVIMIENTO_RENOVACION, $renewal->tipo_movimiento);
        $this->assertSame('ACTIVO', $personal->fresh()->estado);
        $this->assertDatabaseHas('personal_contratos', [
            'id' => $contract->id,
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVACION_PREPARADA,
        ]);

        try {
            app(PersonalContratoService::class)->prepareRenewalFromDecision($contract->fresh(), [
                'fecha_inicio' => '2027-01-01',
            ], $actor);
            $this->fail('No debio crear doble contrato en preparacion.');
        } catch (ValidationException $exception) {
            $this->assertSame('Ya existe un contrato en preparacion para este trabajador. Revisalo antes de crear otro.', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_vencimientos_muestra_renovacion_preparada_sin_archivo_firmado_y_deja_origen_resuelto_al_final(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');
        Storage::fake('local');

        $actor = Usuario::query()->findOrFail($this->createUser(['vencimientos' => ['ver', 'registrar', 'renovar']]));
        $personal = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Renovacion Visible Sin Firma']);
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true, ['puesto' => 'Mecanico']);

        app(PersonalContratoService::class)->registerRenewalDecision($contract, [
            'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
        ], $actor);

        $renewal = app(PersonalContratoService::class)->prepareRenewalFromDecision($contract->fresh(), [
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-07-31',
            'observacion_renovacion' => 'Preparada sin PDF firmado todavia.',
        ], $actor);

        $this->assertSame(PersonalContrato::ESTADO_PREPARACION, $renewal->estado);
        $this->assertFalse($renewal->hasSignedFile());
        $this->assertSame('ACTIVO', $personal->fresh()->estado);

        $result = app(PersonalContratoService::class)->listExpiringContracts(['mes' => 6, 'anio' => 2026]);

        $this->assertTrue($result->contains('id', $contract->id));
        $this->assertTrue($result->contains('id', $renewal->id));
        $this->assertSame(
            [$renewal->id, $contract->id],
            $result->whereIn('id', [$contract->id, $renewal->id])->pluck('id')->values()->all(),
        );

        $this->withSession($this->sessionFor($actor->id, ['vencimientos' => ['ver', 'registrar', 'renovar']]))
            ->get(route('personal.contratos.expiring', ['mes' => 6, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('Renovacion Visible Sin Firma')
            ->assertSee('Contrato 01/07/2026 al 31/07/2026')
            ->assertSee('Preparacion');
    }

    public function test_vencimiento_renovado_muestra_resuelto_y_se_ordena_despues_de_pendientes(): void
    {
        Carbon::setTestNow('2026-07-17 08:00:00');
        Storage::fake('local');

        $userId = $this->createUser(['vencimientos' => ['ver']]);
        $renovado = $this->createPersonal('CESADO', ['nombre_completo' => 'Orden Renovado Resuelto']);
        $origen = $this->insertContract($renovado, '2026-06-14', '2026-07-15', true, [
            'estado' => PersonalContrato::ESTADO_CESADO,
        ]);
        $renovacion = $this->insertContract($renovado, '2026-07-16', '2026-07-31', false, [
            'estado' => PersonalContrato::ESTADO_PREPARACION,
            'origen_contrato_id' => $origen->id,
        ]);

        $pendiente = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Orden Pendiente Contrato']);
        $contratoPendiente = $this->insertContract($pendiente, '2026-01-01', '2026-07-21', true);

        $result = app(PersonalContratoService::class)->listExpiringContracts(['mes' => 7, 'anio' => 2026]);

        $this->assertSame(
            [$renovacion->id, $contratoPendiente->id, $origen->id],
            $result->whereIn('id', [$origen->id, $renovacion->id, $contratoPendiente->id])->pluck('id')->values()->all(),
        );
        $this->assertSame('FALTA_CONTRATO', $result->firstWhere('id', $origen->id)?->getAttribute('estado_laboral_visual'));
        $this->assertSame('Falta contrato', $result->firstWhere('id', $origen->id)?->getAttribute('estado_laboral_visual_label'));
        $this->assertSame('FALTA_CONTRATO', $result->firstWhere('id', $renovacion->id)?->getAttribute('estado_laboral_visual'));

        $resourceWorker = $renovado->fresh()->load('contratosLaborales');
        $detailRow = (new PersonalResource($resourceWorker))->resolve();
        $indexRow = (new PersonalIndexResource($resourceWorker))->resolve();

        $this->assertSame('FALTA_CONTRATO', $detailRow['estado']);
        $this->assertSame('Falta contrato', $detailRow['estado_label']);
        $this->assertSame('FALTA_CONTRATO', $indexRow['estado']);
        $this->assertSame('Falta contrato', $indexRow['estado_label']);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.contratos.expiring', ['mes' => 7, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('Orden Renovado Resuelto')
            ->assertSee('Renovado')
            ->assertSee('Resuelto hace 2 dias')
            ->assertSee('Laboral: Falta contrato')
            ->assertDontSee('Laboral: Cesado');
    }

    public function test_contrato_activo_vigente_se_muestra_activo_aunque_el_estado_interno_quede_pendiente(): void
    {
        Carbon::setTestNow('2026-07-17 08:00:00');
        Storage::fake('local');

        $personal = $this->createPersonal('FALTA_CONTRATO', [
            'nombre_completo' => 'Contrato Activo Visual',
            'pendiente_contrato_firmado' => true,
        ]);
        $origen = $this->insertContract($personal, '2025-11-19', '2026-05-31', true, [
            'estado' => PersonalContrato::ESTADO_CERRADO,
        ]);
        $activo = $this->insertContract($personal, '2026-06-01', '2026-11-30', false, [
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'origen_contrato_id' => $origen->id,
        ]);

        $result = app(PersonalContratoService::class)->listExpiringContracts([
            'trabajador' => 'Contrato Activo Visual',
        ]);

        $this->assertSame('ACTIVO', $result->firstWhere('id', $activo->id)?->getAttribute('estado_laboral_visual'));
        $this->assertSame('Activo', $result->firstWhere('id', $activo->id)?->getAttribute('estado_laboral_visual_label'));

        $resourceWorker = $personal->fresh()->load('contratosLaborales');
        $detailRow = (new PersonalResource($resourceWorker))->resolve();
        $indexRow = (new PersonalIndexResource($resourceWorker))->resolve();

        $this->assertSame('ACTIVO', $detailRow['estado']);
        $this->assertSame('Activo', $detailRow['estado_label']);
        $this->assertSame('habilitado', $detailRow['situacion']);
        $this->assertTrue($detailRow['pendiente_contrato_firmado']);
        $this->assertFalse($detailRow['contrato_firmado']);
        $this->assertSame('ACTIVO', $indexRow['estado']);
        $this->assertSame('Activo', $indexRow['estado_label']);
        $this->assertSame('habilitado', $indexRow['situacion']);
        $this->assertTrue($indexRow['pendiente_contrato_firmado']);
        $this->assertFalse($indexRow['contrato_firmado']);

        $searchResult = collect(app(EppService::class)->searchPersonal('Contrato Activo Visual'));
        $searchRow = $searchResult->firstWhere('id', $personal->id);

        $this->assertNotNull($searchRow);
        $this->assertSame('Activo', $searchRow['estado']);
        $this->assertTrue($searchRow['pendiente_contrato_firmado']);
        $this->assertFalse($searchRow['contrato_firmado']);
    }

    public function test_contrato_historico_aparece_en_su_mes_pero_no_acepta_decision_activa(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('CESADO');
        $closed = $this->insertContract($personal, '2026-01-01', '2026-06-30', true, ['estado' => PersonalContrato::ESTADO_CERRADO]);

        $result = app(PersonalContratoService::class)->listExpiringContracts(['mes' => 6, 'anio' => 2026]);
        $this->assertTrue($result->contains('id', $closed->id));
        $this->assertFalse((bool) $result->firstWhere('id', $closed->id)->getAttribute('can_register_decision'));

        try {
            app(PersonalContratoService::class)->registerRenewalDecision($closed, [
                'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
            ], $actor);
            $this->fail('No debio registrar decision activa en contrato historico.');
        } catch (ValidationException $exception) {
            $this->assertSame('Solo se puede registrar decision sobre contratos activos o vencidos dentro de los ultimos 7 dias.', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_decision_se_infiere_como_renovar_si_existe_contrato_posterior(): void
    {
        $userId = $this->createUser(['vencimientos' => ['ver']]);
        $personal = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Inferencia Renovacion']);
        $base = $this->insertContract($personal, '2026-01-01', '2026-06-30', true, ['estado' => PersonalContrato::ESTADO_CERRADO]);
        $this->insertContract($personal, '2026-07-01', '2026-12-31', true, [
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'origen_contrato_id' => $base->id,
        ]);

        $result = app(PersonalContratoService::class)->listExpiringContracts(['mes' => 6, 'anio' => 2026]);
        $contract = $result->firstWhere('id', $base->id);

        $this->assertNotNull($contract);
        $this->assertSame(PersonalContrato::DECISION_RENOVAR, $contract->getAttribute('decision_visual'));
        $this->assertTrue((bool) $contract->getAttribute('decision_visual_inferida'));
        $this->assertSame('RENOVADO', $contract->getAttribute('estado_visual'));

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.contratos.expiring', ['mes' => 6, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('Inferencia Renovacion')
            ->assertSee('Renovado')
            ->assertSee('Tiene contrato posterior.');
    }

    public function test_ruta_masiva_registra_decision_y_prepara_renovacion(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');
        Storage::fake('local');

        $permissions = ['vencimientos' => ['ver', 'registrar', 'renovar']];
        $userId = $this->createUser($permissions);
        $personal = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Masivo Renovacion']);
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true, ['tipo_contrato' => 'FIJO']);

        $response = $this->withSession($this->sessionFor($userId, $permissions))
            ->postJson(route('personal.contratos.bulk-decision'), [
                'contract_ids' => [$contract->id],
                'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
                'fecha_inicio' => '2026-07-01',
                'fecha_fin' => '2026-12-31',
                'observacion_renovacion' => 'Preparada desde seleccion masiva.',
            ]);

        $response->assertOk()
            ->assertJsonPath('summary.procesados', 1)
            ->assertJsonPath('summary.renovaciones', 1);

        $this->assertDatabaseHas('personal_contratos', [
            'id' => $contract->id,
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVACION_PREPARADA,
            'decision_final' => PersonalContrato::DECISION_RENOVAR,
        ]);
        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $personal->id,
            'origen_contrato_id' => $contract->id,
            'estado' => PersonalContrato::ESTADO_PREPARACION,
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-12-31',
        ]);
        $this->assertSame('ACTIVO', $personal->fresh()->estado);
    }

    public function test_exportar_vencimientos_requiere_permiso_exportar(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $viewer = $this->createUser(['vencimientos' => ['ver']]);
        $exporter = $this->createUser(['vencimientos' => ['ver', 'exportar']]);
        $personal = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Exportar Vencimientos']);
        $this->insertContract($personal, '2026-01-01', '2026-06-30', true);

        $this->withSession($this->sessionFor($viewer))
            ->get(route('personal.contratos.expiring.export', ['mes' => 6, 'anio' => 2026]))
            ->assertForbidden();

        $this->withSession($this->sessionFor($exporter))
            ->get(route('personal.contratos.expiring.export', ['mes' => 6, 'anio' => 2026]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_registrar_decision_no_permite_preparar_renovacion_sin_permiso_renovar(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $userId = $this->createUser(['vencimientos' => ['ver', 'registrar']]);
        $personal = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Decision Sin Renovar']);
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true);

        $this->withSession($this->sessionFor($userId))
            ->postJson(route('personal.contratos.bulk-decision'), [
                'contract_ids' => [$contract->id],
                'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
                'fecha_inicio' => '2026-07-01',
            ])
            ->assertForbidden();

        $this->withSession($this->sessionFor($userId))
            ->postJson(route('personal.contratos.bulk-decision'), [
                'contract_ids' => [$contract->id],
                'estado_decision_renovacion' => PersonalContrato::DECISION_EN_EVALUACION,
                'observacion_decision' => 'Pendiente de confirmacion.',
            ])
            ->assertOk()
            ->assertJsonPath('summary.procesados', 1)
            ->assertJsonPath('summary.renovaciones', 0);
    }

    public function test_enlace_a_detalle_de_trabajador_solo_aparece_con_permiso_ver_detalle(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $viewer = $this->createUser(['vencimientos' => ['ver']]);
        $detailViewer = $this->createUser([
            'vencimientos' => ['ver'],
            'personal' => ['ver_detalle'],
        ]);
        $personal = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Detalle Protegido']);
        $this->insertContract($personal, '2026-01-01', '2026-06-30', true);
        $detailUrl = route('personal.show', $personal->id);

        $this->withSession($this->sessionFor($viewer))
            ->get(route('personal.contratos.expiring', ['mes' => 6, 'anio' => 2026]))
            ->assertOk()
            ->assertDontSee($detailUrl, false);

        $this->withSession($this->sessionFor($detailViewer))
            ->get(route('personal.contratos.expiring', ['mes' => 6, 'anio' => 2026]))
            ->assertOk()
            ->assertSee($detailUrl, false)
            ->assertSee('Ver detalle de Detalle Protegido');
    }

    public function test_rutas_respetan_permiso_actualizar(): void
    {
        $denied = $this->createUser(['vencimientos' => ['ver']]);
        $allowedRegister = $this->createUser(['vencimientos' => ['ver', 'registrar']]);
        $allowedRenew = $this->createUser(['vencimientos' => ['ver', 'registrar', 'renovar']]);
        $personal = $this->createPersonal('ACTIVO');
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true);

        $this->withSession($this->sessionFor($denied))
            ->get(route('personal.contratos.expiring', ['mes' => 6, 'anio' => 2026]))
            ->assertOk();

        $this->withSession($this->sessionFor($denied))
            ->post(route('personal.contratos.decision', $contract->id), [
                'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
            ])
            ->assertForbidden();

        $this->withSession($this->sessionFor($allowedRegister))
            ->post(route('personal.contratos.decision', $contract->id), [
                'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
            ])
            ->assertRedirect();

        $this->withSession($this->sessionFor($allowedRenew))
            ->post(route('personal.contratos.prepare-from-decision', $contract->id), [
                'fecha_inicio' => '2026-07-01',
            ])
            ->assertRedirect(route('personal.contrato-datos.edit', $personal->id));
    }

    public function test_no_se_agregan_nombres_propios_en_archivos_de_la_etapa(): void
    {
        $files = [
            app_path('Models/PersonalContrato.php'),
            app_path('Modules/Personal/Services/PersonalContratoService.php'),
            app_path('Modules/Personal/Controllers/PersonalContratoController.php'),
            resource_path('views/personal/contratos/vencimientos.blade.php'),
            resource_path('views/personal/partials/contract-format-modal.blade.php'),
            database_path('migrations/2026_06_05_000700_add_renewal_decision_fields_to_personal_contratos.php'),
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/elida|diego/i', $content, $file);
        }
    }

    private function createPersonal(string $estado, array $overrides = []): Personal
    {
        $id = (string) Str::uuid();
        $document = (string) random_int(77000000, 77999999);

        DB::table('personal')->insert(array_merge([
            'id' => $id,
            'dni' => $document,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_completo' => 'Control Contrato',
            'puesto' => 'Operario',
            'ocupacion' => 'Tecnico',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-01-01',
            'estado' => $estado,
            'telefono' => '999999999',
            'telefono_1' => '999999999',
            'correo' => 'control@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return Personal::query()->findOrFail($id);
    }

    private function insertContract(Personal $personal, string $inicio, string $fin, bool $signed, array $overrides = []): PersonalContrato
    {
        $path = null;
        if ($signed) {
            $path = 'personal_contratos/' . $personal->id . '/contrato-vigente.pdf';
            Storage::disk('local')->put($path, 'contrato');
        }

        $id = (string) Str::uuid();
        $estado = $overrides['estado'] ?? PersonalContrato::ESTADO_ACTIVO;
        unset($overrides['estado']);

        DB::table('personal_contratos')->insert(array_merge([
            'id' => $id,
            'personal_id' => $personal->id,
            'contrato_numero' => ((int) PersonalContrato::query()->where('personal_id', $personal->id)->max('contrato_numero')) + 1,
            'estado' => $estado,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'puesto' => $personal->puesto,
            'area' => null,
            'tipo_contrato' => 'FIJO',
            'activado_at' => $estado === PersonalContrato::ESTADO_ACTIVO ? $inicio . ' 08:00:00' : null,
            'cerrado_at' => $estado === PersonalContrato::ESTADO_CERRADO ? $fin . ' 18:00:00' : null,
            'signed_at' => $signed ? $inicio . ' 08:00:00' : null,
            'signed_contract_path' => $path,
            'signed_contract_original_name' => $signed ? 'contrato-vigente.pdf' : null,
            'signed_contract_mime' => $signed ? 'application/pdf' : null,
            'signed_contract_size' => $signed ? 8 : null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return PersonalContrato::query()->findOrFail($id);
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_CONTROL_' . Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }

    private function sessionFor(string $userId, ?array $permissions = null): array
    {
        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'control@test.local',
                'permissions' => $permissions ? PermissionCatalog::matrixFromSelections($permissions) : PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
