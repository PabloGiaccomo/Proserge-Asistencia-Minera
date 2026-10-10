<?php

namespace Tests\Feature;

use App\Models\EppAlmacen;
use App\Models\EppEntrega;
use App\Models\EppExistencia;
use App\Models\EppOperacion;
use App\Models\EppOperacionDetalle;
use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalMina;
use App\Models\RQMinaActividadTransporte;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppService;
use App\Modules\Logistica\Services\LogisticaDashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class LogisticaDashboardServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_logistics_search_matches_reordered_partial_names_and_document(): void
    {
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $worker = $this->createPersonal('ACTIVO', 'PAREDES ZEGARRA PÁBLO');
        $epp = $this->createEpp('CASCO BUSQUEDA '.Str::uuid());
        $this->createDelivery($worker, $epp, today()->toDateString(), today()->addDays(10)->toDateString());
        foreach (['pablo paredes', 'PAREDES PABLO', '  pab   pared ', $worker->dni] as $term) {
            $rows = app(EppService::class)->pageData(['q' => $term])['entregas'];
            $this->assertSame(1, $rows->total(), $term);
            $data = app(LogisticaDashboardService::class)->pageData(['q' => $term]);
            $this->assertSame(1, $data['metrics']['workers'], $term);
            $this->assertCount(1, $data['filteredExpiringDeliveries'], $term);
            $this->assertContains($worker->id, array_column(app(EppService::class)->searchPersonal($term), 'id'));
        }
        $this->assertSame(0, app(EppService::class)->pageData(['q' => 'PABLO INEXISTENTE'])['entregas']->total());
        $worker->update(['estado' => 'CESADO']);
        foreach (['pablo paredes', 'PAREDES PABLO', $worker->dni] as $term) {
            $ceased = app(LogisticaDashboardService::class)->pageData(['tab' => 'cesados', 'q' => $term])['ceasedRows'];
            $this->assertSame($worker->id, $ceased->first()['personal_id']);
        }
    }

    public function test_delivery_mine_filter_uses_overlapping_usage_not_active_habilitation(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00'));
        try {
            $worker = $this->createPersonal('ACTIVO', 'FILTRO USO MINA '.Str::uuid());
            $mine = $this->createMine('MINA USO '.Str::uuid());
            $other = $this->createMine('MINA OTRA '.Str::uuid());
            $epp = $this->createEpp('CASCO FILTRO '.Str::uuid());
            $this->createRqAssignment($worker, $mine, '2026-09-02', '2026-09-04');
            $this->createRqAssignment($worker, $other, '2026-09-06', '2026-09-08');
            $this->createDelivery($worker, $epp, '2026-09-01', '2027-09-01');
            $this->createDelivery($worker, $epp, '2026-09-05', '2027-09-05');
            $this->createDelivery($worker, $epp, '2026-08-01', '2026-08-05', EppEntrega::ESTADO_DEVUELTO);
            $this->createDelivery($worker, $epp, '2026-10-10', '2027-10-10');
            $page = app(EppService::class)->pageData(['q' => $worker->nombre_completo, 'mina_id' => $mine]);
            $this->assertSame(1, $page['entregas']->total());
            $row = $page['entregas']->first();
            $this->assertCount(1, $row['periodos_uso']);
            $this->assertSame('2026-09-02', $row['periodos_uso'][0]['desde']);
            $this->assertSame(3, $row['dias_uso_efectivo']);
            $all = app(EppService::class)->pageData(['q' => $worker->nombre_completo]);
            $this->assertSame(4, $all['entregas']->total());
        } finally {
            $this->travelBack();
        }
    }

    public function test_coverage_uses_catalog_requirement_flag_and_unconciliated_stock_is_unknown(): void
    {
        $worker = $this->createPersonal('ACTIVO', 'COBERTURA CONFIGURADA '.Str::random(6));
        $required = $this->createEpp('PROTECCION ESPECIAL '.Str::random(6));
        $optional = $this->createEpp('CASCO OPCIONAL '.Str::random(6));
        DB::table('epp_registro')->where('id', $required)->update(['es_requerido_cobertura' => true]);
        $data = app(LogisticaDashboardService::class)->pageData(['q' => $worker->nombre_completo, 'epps' => [$required, $optional]]);
        $this->assertSame(1, $data['metrics']['required_epp']);
        $this->assertCount(1, $data['requirements']);
        $this->assertSame($required, $data['requirements']->first()['id']);
        $this->assertNull($data['stockRows']->first()['stock']);
        $this->assertSame('STOCK_NO_CONCILIADO', $data['stockRows']->first()['estado']);
    }

    public function test_required_consumable_with_consumed_state_counts_for_coverage(): void
    {
        $worker = $this->createPersonal('ACTIVO', 'CONSUMIBLE COBERTURA '.Str::uuid());
        $epp = $this->createEpp('CONSUMIBLE REQUERIDO '.Str::uuid());
        DB::table('epp_registro')->where('id', $epp)->update(['es_requerido_cobertura' => true, 'politica_devolucion' => 'CONSUMIBLE']);
        $delivery = $this->createDelivery($worker, $epp, today()->toDateString(), today()->addMonth()->toDateString());
        EppEntrega::query()->where('personal_id', $worker->id)->update(['estado' => 'CONSUMIDO', 'politica_devolucion_snapshot' => 'CONSUMIBLE', 'cantidad_pendiente_devolucion' => 0]);
        $data = app(LogisticaDashboardService::class)->pageData(['q' => $worker->nombre_completo, 'epps' => [$epp]]);
        $this->assertSame(1, $data['metrics']['delivered_epp']);
        $this->assertSame(0, $data['metrics']['pending_epp']);
    }

    public function test_dashboard_uses_reconciled_stock_and_positive_return_balances(): void
    {
        $worker = $this->createPersonal('ACTIVO', 'SALDO CONFIGURADO '.Str::random(6));
        $epp = $this->createEpp('GUANTE CON SALDO '.Str::random(6));
        DB::table('epp_registro')->where('id', $epp)->update(['es_requerido_cobertura' => true]);
        $warehouse = EppAlmacen::query()->create(['nombre' => 'CONTEO '.Str::uuid()]);
        EppExistencia::query()->create(['almacen_id' => $warehouse->id, 'epp_id' => $epp, 'variante_clave' => 'SIN_VARIANTE', 'atributos_json' => [], 'cantidad_disponible' => 7, 'cantidad_reservada' => 2, 'cantidad_cuarentena' => 1, 'conciliado_at' => now()]);
        $this->createDelivery($worker, $epp, now()->toDateString(), now()->addMonth()->toDateString());
        EppEntrega::query()->where('personal_id', $worker->id)->update(['politica_devolucion_snapshot' => 'RETORNABLE', 'cantidad_pendiente_devolucion' => 0]);
        $data = app(LogisticaDashboardService::class)->pageData(['q' => $worker->nombre_completo, 'epps' => [$epp]]);
        $this->assertSame(7, $data['requirements']->first()['stock']);
        $this->assertSame(0, $data['metrics']['delivered_epp']);
    }

    public function test_dashboard_sums_partial_pending_and_separates_signed_legacy_counts(): void
    {
        $worker = $this->createPersonal('ACTIVO', 'PENDIENTE PARCIAL '.Str::random(6));
        $epp = $this->createEpp('ARTICULO PARCIAL '.Str::random(6));
        $warehouse = EppAlmacen::query()->create(['nombre' => 'PARCIAL '.Str::uuid()]);
        $operation = EppOperacion::query()->create(['personal_id' => $worker->id, 'almacen_id' => $warehouse->id, 'tipo' => 'ENTREGA', 'estado' => 'FIRMADO_PARCIAL', 'submission_uuid' => (string) Str::uuid(), 'version' => 1]);
        EppOperacion::query()->create(['personal_id' => $worker->id, 'almacen_id' => $warehouse->id, 'tipo' => 'CORRECCION', 'estado' => 'FIRMADO', 'operacion_origen_id' => $operation->id, 'submission_uuid' => (string) Str::uuid(), 'version' => 1]);
        EppOperacionDetalle::query()->create(['operacion_id' => $operation->id, 'epp_solicitado_id' => $epp, 'epp_id' => $epp, 'cantidad_solicitada' => 10, 'cantidad_procesada' => 3, 'cantidad_pendiente' => 7, 'estado' => 'ENTREGADO', 'politica_devolucion_snapshot' => 'RETORNABLE', 'control_inventario_snapshot' => true, 'snapshot_json' => []]);
        $this->createDelivery($worker, $epp, now()->toDateString(), now()->addMonth()->toDateString());
        $data = app(LogisticaDashboardService::class)->pageData(['q' => $worker->nombre_completo, 'epps' => [$epp]]);
        $this->assertSame(7, $data['metrics']['pending_operation_units']);
        $this->assertSame(1, $data['metrics']['signed_operations']);
        $this->assertSame(1, $data['metrics']['legacy_deliveries']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_scope_counts_only_active_or_enabled_workers_and_splits_expirations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 08:00:00'));

        $activeWorker = $this->createPersonal('ACTIVO', 'TRABAJADOR ACTIVO');
        $enabledWorker = $this->createPersonal('INACTIVO', 'TRABAJADOR HABILITADO');
        $outsideWorker = $this->createPersonal('INACTIVO', 'TRABAJADOR FUERA');
        $mineId = $this->createMine('BOROO');
        $eppId = $this->createEpp('CASCO DE SEGURIDAD');

        $this->attachMine($enabledWorker, $mineId, PersonalMina::ESTADO_HABILITADO);
        $this->attachMine($outsideWorker, $mineId, PersonalMina::ESTADO_NO_HABILITADO);
        $this->createDelivery($activeWorker, $eppId, '2026-06-01', '2026-07-01');
        $this->createDelivery($enabledWorker, $eppId, '2026-07-01', '2026-07-20');
        $this->createDelivery($outsideWorker, $eppId, '2026-06-01', '2026-07-02');

        $data = app(LogisticaDashboardService::class)->pageData(['tab' => 'dashboard']);

        $this->assertSame(2, $data['metrics']['workers']);
        $this->assertSame(1, $data['metrics']['expired_epp']);
        $this->assertSame(1, $data['metrics']['expiring_epp']);
        $this->assertArrayNotHasKey('costo_pendiente_estimado', $data['metrics']);
    }

    public function test_proximos_vencimientos_incluye_dias_efectivos_usados_por_paradas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 08:00:00'));

        $worker = $this->createPersonal('ACTIVO', 'TRABAJADOR CON USO EFECTIVO');
        $mineId = $this->createMine('CERRO VERDE');
        $eppId = $this->createEpp('CASCO CON USO');

        $this->attachMine($worker, $mineId, PersonalMina::ESTADO_HABILITADO);
        $this->createDelivery($worker, $eppId, '2026-07-01', '2026-07-20');
        $this->createRqAssignment($worker, $mineId, '2026-07-03', '2026-07-06');

        $data = app(LogisticaDashboardService::class)->pageData(['tab' => 'vencimientos']);
        $row = collect($data['expiringDeliveries'])
            ->firstWhere('trabajador', 'TRABAJADOR CON USO EFECTIVO');

        $this->assertNotNull($row);
        $this->assertSame(4, $row['dias_uso_efectivo']);
        $this->assertSame(30, $row['vida_dias']);
        $this->assertSame('4 / 30 dias', $row['uso_efectivo']);
    }

    public function test_cesados_por_entregar_muestra_solo_saldo_retornable_positivo(): void
    {
        $worker = $this->createPersonal('CESADO', 'TRABAJADOR CESADO LOGISTICA');
        $worker->forceFill([
            'fecha_cese' => '2026-07-15',
            'motivo_cese' => 'Fin de contrato',
        ])->save();

        DB::table('personal_contratos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $worker->id,
            'contrato_numero' => 1,
            'estado' => PersonalContrato::ESTADO_CESADO,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-07-15',
            'motivo_cese' => 'Fin de contrato',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pendingEpp = $this->createEpp('CASCO CESADO PENDIENTE');
        $resolvedEpp = $this->createEpp('CHALECO CESADO RESUELTO');

        $this->createDelivery($worker, $pendingEpp, '2026-06-01', '2026-07-01', EppEntrega::ESTADO_ENTREGADO);
        EppEntrega::query()->where('personal_id', $worker->id)->where('epp_id', $pendingEpp)->firstOrFail()->update(['politica_devolucion_snapshot' => 'RETORNABLE', 'cantidad_pendiente_devolucion' => 1]);
        $this->createDelivery($worker, $resolvedEpp, '2026-06-05', '2026-07-05', EppEntrega::ESTADO_DEVUELTO);

        $data = app(LogisticaDashboardService::class)->pageData(['tab' => 'cesados', 'q' => 'TRABAJADOR CESADO LOGISTICA']);
        $row = collect($data['ceasedRows'])->firstWhere('trabajador', 'TRABAJADOR CESADO LOGISTICA');

        $this->assertNotNull($row);
        $this->assertSame('PENDIENTE', $row['estado_logistico']);
        $this->assertSame(1, $row['pendientes']);
        $this->assertSame(0, $row['resueltos']);
        $this->assertSame(1, $data['ceasedSummary']['trabajadores']);
        $this->assertSame(1, $data['ceasedSummary']['pendientes']);
        $this->assertSame(0, $data['ceasedSummary']['resueltos']);

        $pendingItem = collect($row['items'])->firstWhere('epp', 'CASCO CESADO PENDIENTE');
        $resolvedItem = collect($row['items'])->firstWhere('epp', 'CHALECO CESADO RESUELTO');

        $this->assertFalse($pendingItem['resuelto']);
        $this->assertSame('Pendiente de entrega', $pendingItem['estado_resolucion']);
        $this->assertNull($resolvedItem);
        $this->assertSame(1, $pendingItem['cantidad']);
    }

    public function test_logistica_actualiza_requerimiento_de_transporte_de_rq_mina(): void
    {
        $mineId = $this->createMine('BOROO');
        $usuario = $this->createLogisticsUser();
        $transportId = $this->createRqTransportRequirement($mineId, $usuario->id);

        $updated = app(LogisticaDashboardService::class)->updateTransportRequirement($transportId, [
            'origen' => 'ALQUILADO',
            'placas_asignadas' => 'ABC-123; chofer Juan',
            'fecha_inicio' => '2026-07-10',
            'fecha_fin' => '2026-07-12',
            'estado_logistico' => RQMinaActividadTransporte::ESTADO_ASIGNADO,
            'comentario_cambio' => 'Asignado por proveedor local',
            'incidencia_operativa' => '',
            'recepcion_estado' => RQMinaActividadTransporte::RECEPCION_PENDIENTE,
            'recepcion_observacion' => 'Pendiente de confirmacion final',
        ], $usuario);

        $this->assertSame(RQMinaActividadTransporte::ESTADO_ASIGNADO, $updated->estado_logistico);
        $this->assertSame('ABC-123; chofer Juan', $updated->placas_asignadas);
        $this->assertSame(3, $updated->dias_uso);
        $this->assertDatabaseHas('rq_mina_actividad_transporte_eventos', [
            'transporte_id' => $transportId,
            'estado_anterior' => RQMinaActividadTransporte::ESTADO_REQUERIDO,
            'estado_nuevo' => RQMinaActividadTransporte::ESTADO_ASIGNADO,
            'usuario_id' => $usuario->id,
        ]);

        $rows = app(LogisticaDashboardService::class)->pageData(['tab' => 'servicios'])['serviceRows'];
        $row = collect($rows)->firstWhere('id', $transportId);

        $this->assertNotNull($row);
        $this->assertSame('Van 15, minibus 35', $row['solicitado']);
        $this->assertSame('ABC-123; chofer Juan', $row['placas_asignadas']);
        foreach (['Juan ABC-123', '  abc-123   JUAN '] as $term) {
            $found = app(LogisticaDashboardService::class)->pageData(['tab' => 'servicios', 'servicio_q' => $term])['serviceRows'];
            $this->assertSame($transportId, $found->first()['id']);
        }

        // La trazabilidad ya se registraba, pero tenia que poder verse en pantalla.
        $this->assertNotEmpty($row['eventos']);
        $this->assertSame('Requerido', $row['eventos'][0]['estado_anterior_label']);
        $this->assertSame('Asignado', $row['eventos'][0]['estado_nuevo_label']);
        $this->assertSame('Asignado por proveedor local', $row['eventos'][0]['descripcion']);
        $this->assertSame($usuario->email, $row['eventos'][0]['usuario']);
    }

    public function test_logistica_registra_recepcion_sin_borrar_la_atencion_de_uso(): void
    {
        $mineId = $this->createMine('BOROO');
        $usuario = $this->createLogisticsUser();
        $transportId = $this->createRqTransportRequirement($mineId, $usuario->id);
        $service = app(LogisticaDashboardService::class);

        $service->updateTransportRequirement($transportId, [
            'origen' => 'EMPRESA',
            'placas_asignadas' => 'XYZ-987',
            'fecha_inicio' => '2026-07-10',
            'fecha_fin' => '2026-07-12',
            'estado_logistico' => RQMinaActividadTransporte::ESTADO_EN_USO,
        ], $usuario);

        // El panel de recepcion no reenvia placas ni fechas de uso: no debe borrarlas.
        $updated = $service->updateTransportRequirement($transportId, [
            'recepcion_estado' => RQMinaActividadTransporte::RECEPCION_INCOMPLETO,
            'recepcion_fecha' => '2026-07-13',
            'recepcion_observacion' => 'Falto un extintor',
        ], $usuario);

        $this->assertSame(RQMinaActividadTransporte::RECEPCION_INCOMPLETO, $updated->recepcion_estado);
        $this->assertSame('2026-07-13', $updated->recepcion_fecha?->toDateString());
        $this->assertSame('Falto un extintor', $updated->recepcion_observacion);
        $this->assertSame('XYZ-987', $updated->placas_asignadas);
        $this->assertSame('EMPRESA', $updated->origen);
        $this->assertSame(RQMinaActividadTransporte::ESTADO_EN_USO, $updated->estado_logistico);
        $this->assertSame(3, $updated->dias_uso);
    }

    public function test_requerimientos_de_servicios_se_filtran_por_parada_y_estado(): void
    {
        $mineId = $this->createMine('BOROO');
        $usuario = $this->createLogisticsUser();
        $transportId = $this->createRqTransportRequirement($mineId, $usuario->id);
        $rqMinaId = (string) DB::table('rq_mina_actividad_transportes')
            ->join('rq_mina_actividad_grupos', 'rq_mina_actividad_grupos.id', '=', 'rq_mina_actividad_transportes.grupo_id')
            ->where('rq_mina_actividad_transportes.id', $transportId)
            ->value('rq_mina_actividad_grupos.rq_mina_id');
        $service = app(LogisticaDashboardService::class);

        $filtrado = $service->pageData(['tab' => 'servicios', 'servicio_parada_id' => $rqMinaId])['serviceRows'];
        $this->assertCount(1, $filtrado);
        $this->assertSame($transportId, $filtrado->first()['id']);
        $this->assertFalse($filtrado->first()['atendido']);

        $otraParada = $service->pageData(['tab' => 'servicios', 'servicio_parada_id' => (string) Str::uuid()])['serviceRows'];
        $this->assertCount(0, $otraParada);

        $porEstado = $service->pageData([
            'tab' => 'servicios',
            'servicio_parada_id' => $rqMinaId,
            'servicio_estado' => RQMinaActividadTransporte::ESTADO_ASIGNADO,
        ])['serviceRows'];
        $this->assertCount(0, $porEstado);
    }

    public function test_documentos_de_transporte_se_resuelven_por_slot(): void
    {
        if (! Schema::hasColumn('rq_mina_actividad_transportes', 'documentos')) {
            $this->markTestSkipped('La columna documentos no existe en esta base de pruebas.');
        }

        $mineId = $this->createMine('BOROO');
        $usuario = $this->createLogisticsUser();
        $transportId = $this->createRqTransportRequirement($mineId, $usuario->id);
        $transporte = RQMinaActividadTransporte::query()->findOrFail($transportId);
        $transporte->forceFill([
            'doc_vehiculo_path' => 'logistica/transportes/'.$transportId.'/vehiculo.pdf',
            'documentos' => [[
                'tipo' => 'adicional',
                'nombre' => 'SOAT 2026',
                'path' => 'logistica/transportes/'.$transportId.'/soat.pdf',
                'original_name' => 'soat.pdf',
                'uploaded_at' => now()->toDateTimeString(),
            ]],
        ])->save();

        $service = app(LogisticaDashboardService::class);
        $legacy = $service->transportDocumentSlot($transporte, 'legacy-vehiculo');
        $adicional = $service->transportDocumentSlot($transporte, 'adicional-0');

        $this->assertNotNull($legacy);
        $this->assertSame('logistica/transportes/'.$transportId.'/vehiculo.pdf', $legacy['path']);
        $this->assertSame('documentacion-vehiculo.pdf', $legacy['name']);
        $this->assertNotNull($adicional);
        $this->assertSame('logistica/transportes/'.$transportId.'/soat.pdf', $adicional['path']);
        $this->assertSame('soat-2026.pdf', $adicional['name']);
        $this->assertNull($service->transportDocumentSlot($transporte, 'adicional-5'));
        $this->assertNull($service->transportDocumentSlot($transporte, 'slot-invalido'));
    }

    public function test_filtros_de_proximos_vencimientos_afectan_solo_la_tabla(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 08:00:00'));

        $mineA = $this->createMine('MARCOBRE');
        $mineB = $this->createMine('BOROO');
        $workerA = $this->createPersonal('ACTIVO', 'TRABAJADOR FILTRADO');
        $workerB = $this->createPersonal('ACTIVO', 'TRABAJADOR VIGENTE');
        $eppA = $this->createEpp('CASCO FILTRO');
        $eppB = $this->createEpp('CASCO VIGENTE');

        $this->attachMine($workerA, $mineA, PersonalMina::ESTADO_HABILITADO);
        $this->attachMine($workerB, $mineB, PersonalMina::ESTADO_HABILITADO);
        $this->createDelivery($workerA, $eppA, '2026-07-01', '2026-07-20');
        $this->createDelivery($workerB, $eppB, '2026-07-01', '2026-09-01');

        $filtered = app(LogisticaDashboardService::class)->pageData([
            'tab' => 'vencimientos',
            'venc_q' => 'FILTRADO',
            'venc_mina_id' => $mineA,
            'venc_epp_id' => $eppA,
            'venc_talla' => 'No aplica',
            'venc_estado' => 'POR_VENCER',
            'venc_rango' => '15',
            'venc_fecha_desde' => '2026-07-19',
            'venc_fecha_hasta' => '2026-07-21',
        ]);

        $this->assertCount(1, $filtered['filteredExpiringDeliveries']);
        $this->assertSame('TRABAJADOR FILTRADO', $filtered['filteredExpiringDeliveries']->first()['trabajador']);
        $this->assertSame(1, $filtered['metrics']['expiring_epp']);

        $vigentes = app(LogisticaDashboardService::class)->pageData([
            'tab' => 'vencimientos',
            'venc_estado' => 'VIGENTE',
            'venc_rango' => '',
        ]);

        $this->assertSame('TRABAJADOR VIGENTE', $vigentes['filteredExpiringDeliveries']->first()['trabajador']);
        $this->assertSame(1, $vigentes['metrics']['expiring_epp']);
    }

    public function test_dashboard_excluye_talleres_oficinas_y_sin_mina_del_heatmap(): void
    {
        $suffix = Str::upper(Str::random(6));
        $realMineName = 'MINA LOGISTICA '.$suffix;
        $workshopName = 'TALLER LOGISTICA '.$suffix;
        $officeName = 'OFICINA LOGISTICA '.$suffix;

        $realMineId = $this->createMine($realMineName);
        $workshopMineId = $this->createMine($workshopName);
        $officeMineId = $this->createMine($officeName);
        $placeholderMineId = $this->createMine('Sin mina');

        DB::table('talleres')->insert([
            'id' => (string) Str::uuid(),
            'nombre' => $workshopName,
            'ubicacion' => 'Operacion',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('oficinas')->insert([
            'id' => (string) Str::uuid(),
            'nombre' => $officeName,
            'ubicacion' => 'Administracion',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->attachMine($this->createPersonal('ACTIVO', 'TRABAJADOR MINA REAL'), $realMineId, PersonalMina::ESTADO_HABILITADO);
        $this->attachMine($this->createPersonal('ACTIVO', 'TRABAJADOR TALLER'), $workshopMineId, PersonalMina::ESTADO_HABILITADO);
        $this->attachMine($this->createPersonal('ACTIVO', 'TRABAJADOR OFICINA'), $officeMineId, PersonalMina::ESTADO_HABILITADO);
        $this->attachMine($this->createPersonal('ACTIVO', 'TRABAJADOR SIN MINA'), $placeholderMineId, PersonalMina::ESTADO_HABILITADO);

        $data = app(LogisticaDashboardService::class)->pageData(['tab' => 'dashboard']);
        $optionNames = collect($data['options']['minas'])->pluck('nombre');
        $heatmapMines = collect($data['missingHeatmap'])->pluck('mina');

        $this->assertTrue($optionNames->contains($realMineName));
        $this->assertFalse($optionNames->contains($workshopName));
        $this->assertFalse($optionNames->contains($officeName));
        $this->assertFalse($optionNames->contains('Sin mina'));
        $this->assertTrue($heatmapMines->contains($realMineName));
        $this->assertFalse($heatmapMines->contains($workshopName));
        $this->assertFalse($heatmapMines->contains($officeName));
        $this->assertFalse($heatmapMines->contains('Sin mina'));
    }

    public function test_identificacion_de_herramientas_incluye_catalogo_aprendido_de_paradas(): void
    {
        $catalogId = (string) Str::uuid();

        DB::table('parada_herramienta_catalogos')->insert([
            'id' => $catalogId,
            'categoria' => 'HERRAMIENTA',
            'descripcion' => 'ADAPTADOR DE DADO DE IMPACTO',
            'descripcion_normalizada' => 'ADAPTADOR DE DADO DE IMPACTO',
            'unidad' => 'UND',
            'unidad_normalizada' => 'UND',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('epp_registro')->insert([
            'id' => (string) Str::uuid(),
            'codigo' => 'LLAVE_MIXTA',
            'nombre' => 'LLAVE MIXTA',
            'categoria' => 'HERRAMIENTA',
            'stock' => 0,
            'vida_util_dias' => 1,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = app(LogisticaDashboardService::class)->pageData([
            'tab' => 'identificacion',
            'ident_categoria' => 'HERRAMIENTA',
        ])['identityRows'];

        $learned = collect($rows)->firstWhere('nombre', 'ADAPTADOR DE DADO DE IMPACTO');
        $registered = collect($rows)->firstWhere('nombre', 'LLAVE MIXTA');

        $this->assertNotNull($learned);
        $this->assertTrue($learned['readonly']);
        $this->assertSame('CATALOGO_PARADA', $learned['fuente']);
        $this->assertSame([['nombre' => 'Unidad', 'valores' => ['UND']]], $learned['otros_atributos']);
        $this->assertNotNull($registered);
        $this->assertFalse($registered['readonly']);
    }

    private function createPersonal(string $state, string $name): Personal
    {
        return Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => (string) random_int(43000000, 43999999),
            'tipo_documento' => 'DNI',
            'numero_documento' => (string) random_int(43000000, 43999999),
            'nombre_completo' => $name,
            'puesto' => 'OPERARIO',
            'ocupacion' => '',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-'.Str::uuid(),
            'fecha_ingreso' => '2026-01-01',
            'estado' => $state,
            'telefono_1' => '999999999',
            'telefono_2' => '',
            'correo' => Str::slug($name).'@test.local',
        ]);
    }

    private function createMine(string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => $name,
            'unidad_minera' => $name,
            'ubicacion' => '',
            'link_ubicacion' => '',
            'color' => '#0d9488',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createEpp(string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('epp_registro')->insert([
            'id' => $id,
            'codigo' => 'EPP-'.Str::upper(Str::random(8)),
            'nombre' => $name,
            'categoria' => 'EPP',
            'precio_unitario' => 0,
            'stock' => 10,
            'vida_util_dias' => 30,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function attachMine(Personal $personal, string $mineId, string $state): void
    {
        DB::table('personal_mina')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'mina_id' => $mineId,
            'estado' => $state,
            'estado_habilitacion' => $state,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createDelivery(Personal $personal, string $eppId, string $deliveryDate, string $expirationDate, string $estado = EppEntrega::ESTADO_ENTREGADO): void
    {
        DB::table('epp_entregas')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'epp_id' => $eppId,
            'cantidad' => 1,
            'fecha_entrega' => $deliveryDate,
            'fecha_vencimiento_calendario' => $expirationDate,
            'vida_util_dias_snapshot' => 30,
            'cantidad_original' => 1,
            'cantidad_pendiente_devolucion' => $estado === EppEntrega::ESTADO_ENTREGADO ? 1 : 0,
            'politica_devolucion_snapshot' => 'RETORNABLE',
            'estado' => $estado,
            'motivo_cambio' => $estado === EppEntrega::ESTADO_DEVUELTO ? 'Devuelto por internamiento' : null,
            'devuelto_at' => $estado === EppEntrega::ESTADO_DEVUELTO ? $expirationDate : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createRqAssignment(Personal $personal, string $mineId, string $startDate, string $endDate): void
    {
        $userId = (string) Str::uuid();
        $roleId = (string) Str::uuid();
        $rqMinaId = (string) Str::uuid();
        $rqMinaDetalleId = (string) Str::uuid();
        $rqProsergeId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'LOGISTICA_DASHBOARD_TEST_'.Str::random(8),
            'permisos' => json_encode([]),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina')->insert([
            'id' => $rqMinaId,
            'mina_id' => $mineId,
            'area' => 'Operacion',
            'fecha_inicio' => $startDate,
            'fecha_fin' => $endDate,
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_detalle')->insert([
            'id' => $rqMinaDetalleId,
            'rq_mina_id' => $rqMinaId,
            'puesto' => 'OPERARIO',
            'cantidad' => 1,
            'cantidad_atendida' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_proserge')->insert([
            'id' => $rqProsergeId,
            'rq_mina_id' => $rqMinaId,
            'mina_id' => $mineId,
            'responsable_rrhh_id' => $userId,
            'estado' => 'COMPLETADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_proserge_detalle')->insert([
            'id' => (string) Str::uuid(),
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personal->id,
            'puesto_asignado' => 'OPERARIO',
            'fecha_inicio' => $startDate,
            'fecha_fin' => $endDate,
            'estado' => 'ASIGNADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLogisticsUser(): Usuario
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'LOGISTICA_TRANSPORTE_TEST',
            'permisos' => json_encode([]),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Usuario::query()->findOrFail($userId);
    }

    private function createRqTransportRequirement(string $mineId, string $userId): string
    {
        $rqMinaId = (string) Str::uuid();
        $groupId = (string) Str::uuid();
        $transportId = (string) Str::uuid();

        DB::table('rq_mina')->insert([
            'id' => $rqMinaId,
            'mina_id' => $mineId,
            'area' => 'Mina Local - seguimiento',
            'fecha_inicio' => '2026-07-10',
            'fecha_fin' => '2026-07-12',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_actividad_grupos')->insert([
            'id' => $groupId,
            'rq_mina_id' => $rqMinaId,
            'area_operativa' => 'Operacion',
            'modulo' => 'C2',
            'nombre' => 'Parada registrada',
            'observaciones' => null,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_actividad_transportes')->insert([
            'id' => $transportId,
            'grupo_id' => $groupId,
            'actividad_id' => null,
            'alcance' => 'Sector C2',
            'unidad_carga' => 'Personal',
            'origen' => null,
            'unidades_transporte' => 'Van 15, minibus 35',
            'placas_asignadas' => null,
            'fecha_inicio' => '2026-07-10',
            'fecha_fin' => '2026-07-12',
            'dias_uso' => 3,
            'estado_logistico' => RQMinaActividadTransporte::ESTADO_REQUERIDO,
            'indicaciones' => 'Salida 5am desde garita',
            'comentario_cambio' => null,
            'incidencia_operativa' => null,
            'recepcion_fecha' => null,
            'recepcion_estado' => RQMinaActividadTransporte::RECEPCION_PENDIENTE,
            'recepcion_observacion' => null,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $transportId;
    }
}
