<?php

namespace App\Modules\Notificaciones\Services;

use App\Models\Personal;
use App\Models\PersonalBloqueo;
use App\Models\PersonalContrato;
use App\Models\PersonalFicha;
use App\Models\PersonalMina;
use App\Models\PersonalMinaExamen;
use App\Models\RQMina;
use App\Models\RQProserge;
use App\Models\Usuario;
use Illuminate\Support\Facades\Log;
use Throwable;

class OperationalNotificationService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function fichaAprobadaFaltaContrato(PersonalFicha $ficha, Usuario $actor): void
    {
        $ficha->loadMissing('personal');
        $personal = $ficha->personal;

        if (!$personal) {
            return;
        }

        $this->emit('personal_ficha_aprobada_falta_contrato', [
            'actor_user_id' => (string) $actor->id,
            'entity_type' => Personal::class,
            'entity_id' => (string) $personal->id,
            'priority' => 'high',
            'message' => sprintf(
                '%s tiene ficha aprobada y falta cargar el contrato firmado.',
                $personal->nombre_completo ?: 'Trabajador sin nombre'
            ),
            'payload' => [
                'personal_id' => (string) $personal->id,
                'ficha_id' => (string) $ficha->id,
                'estado' => (string) $personal->estado,
            ],
            'dedupe_key' => 'personal_ficha_aprobada_falta_contrato:' . $personal->id . ':' . now()->format('YmdHi'),
        ]);
    }

    public function contratoFirmado(Personal $personal, PersonalContrato $contract, Usuario $actor): void
    {
        $inicio = optional($contract->fecha_inicio)->format('d/m/Y') ?: 'sin inicio';
        $fin = optional($contract->fecha_fin)->format('d/m/Y') ?: 'vigente';

        $this->emit('personal_contrato_firmado', [
            'actor_user_id' => (string) $actor->id,
            'entity_type' => PersonalContrato::class,
            'entity_id' => (string) $contract->id,
            'priority' => 'medium',
            'message' => sprintf(
                'Se cargo el contrato firmado de %s. Periodo: %s al %s.',
                $personal->nombre_completo ?: 'trabajador',
                $inicio,
                $fin
            ),
            'payload' => [
                'personal_id' => (string) $personal->id,
                'contract_id' => (string) $contract->id,
                'personal_route_id' => (string) $personal->id,
            ],
            'dedupe_key' => 'personal_contrato_firmado:' . $contract->id . ':' . optional($contract->signed_at)->format('YmdHis'),
        ]);
    }

    public function contratoDecision(PersonalContrato $contract, Usuario $actor): void
    {
        $contract->loadMissing('personal');
        $decision = strtoupper((string) ($contract->decision_final ?: $contract->estado_decision_renovacion ?: 'PENDIENTE'));

        $this->emit('contrato_decision_renovacion', [
            'actor_user_id' => (string) $actor->id,
            'entity_type' => PersonalContrato::class,
            'entity_id' => (string) $contract->id,
            'priority' => in_array($decision, [PersonalContrato::DECISION_NO_RENOVAR, PersonalContrato::DECISION_RENOVAR], true) ? 'high' : 'medium',
            'message' => sprintf(
                '%s tiene decision de contrato: %s.',
                $contract->personal?->nombre_completo ?: 'Trabajador',
                str_replace('_', ' ', $decision)
            ),
            'payload' => [
                'personal_id' => (string) $contract->personal_id,
                'contract_id' => (string) $contract->id,
                'decision' => $decision,
            ],
            'dedupe_key' => 'contrato_decision_renovacion:' . $contract->id . ':' . $decision . ':' . optional($contract->fecha_decision)->format('YmdHis'),
        ]);
    }

    public function contratoNoRenovadoCerrado(PersonalContrato $contract, Usuario $actor): void
    {
        $contract->loadMissing('personal');

        $this->emit('contrato_no_renovado_cerrado', [
            'actor_user_id' => (string) $actor->id,
            'entity_type' => PersonalContrato::class,
            'entity_id' => (string) $contract->id,
            'priority' => 'high',
            'message' => sprintf(
                '%s fue cerrado como no renovado. Estado laboral: %s.',
                $contract->personal?->nombre_completo ?: 'Trabajador',
                $contract->personal?->estado ?: '-'
            ),
            'payload' => [
                'personal_id' => (string) $contract->personal_id,
                'contract_id' => (string) $contract->id,
                'estado_personal' => $contract->personal?->estado,
            ],
            'dedupe_key' => 'contrato_no_renovado_cerrado:' . $contract->id . ':' . optional($contract->fecha_cierre_no_renovacion)->format('YmdHis'),
        ]);
    }

    public function habilitacionAsignacion(PersonalMina $assignment, Usuario $actor, ?string $previousState = null): void
    {
        $assignment->loadMissing(['personal', 'mina']);
        $newState = $assignment->estadoHabilitacionActual();
        $previousState = $previousState ? strtoupper($previousState) : null;

        if ($previousState !== null && $previousState === $newState) {
            return;
        }

        $isCritical = in_array($newState, [
            PersonalMina::ESTADO_NO_HABILITADO,
            PersonalMina::ESTADO_OBSERVADO,
            PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION,
        ], true);

        $type = $newState === PersonalMina::ESTADO_HABILITADO
            ? 'habilitacion_mina_habilitado'
            : ($isCritical ? 'habilitacion_mina_estado_critico' : 'habilitacion_mina_actualizada');

        $this->emit($type, [
            'actor_user_id' => (string) $actor->id,
            'mine_id' => (string) $assignment->mina_id,
            'entity_type' => PersonalMina::class,
            'entity_id' => (string) $assignment->id,
            'priority' => $isCritical ? 'high' : 'medium',
            'message' => sprintf(
                '%s en %s cambio de %s a %s.',
                $assignment->personal?->nombre_completo ?: 'Trabajador',
                $assignment->mina?->nombre ?: 'mina',
                $previousState ?: 'sin estado',
                $newState
            ),
            'payload' => [
                'personal_id' => (string) $assignment->personal_id,
                'mina_id' => (string) $assignment->mina_id,
                'estado_anterior' => $previousState,
                'estado_nuevo' => $newState,
            ],
            'dedupe_key' => 'habilitacion_mina_estado:' . $assignment->id . ':' . $newState . ':' . now()->format('YmdHi'),
        ]);
    }

    public function examenProgramado(PersonalMinaExamen $workerExam, Usuario $actor): void
    {
        $workerExam->loadMissing(['asignacion.personal', 'asignacion.mina']);

        if (!$workerExam->fecha_programacion) {
            return;
        }

        $this->emit('habilitacion_examen_programado', [
            'actor_user_id' => (string) $actor->id,
            'mine_id' => (string) $workerExam->asignacion?->mina_id,
            'entity_type' => PersonalMinaExamen::class,
            'entity_id' => (string) $workerExam->id,
            'priority' => 'medium',
            'message' => sprintf(
                '%s tiene %s programado para %s en %s.',
                $workerExam->asignacion?->personal?->nombre_completo ?: 'Trabajador',
                $workerExam->nombre_snapshot ?: 'examen',
                $workerExam->fecha_programacion->format('d/m/Y'),
                $workerExam->asignacion?->mina?->nombre ?: 'mina'
            ),
            'payload' => [
                'personal_id' => (string) $workerExam->asignacion?->personal_id,
                'mina_id' => (string) $workerExam->asignacion?->mina_id,
                'personal_mina_id' => (string) $workerExam->personal_mina_id,
                'examen_id' => (string) $workerExam->id,
                'fecha_programacion' => $workerExam->fecha_programacion->toDateString(),
            ],
            'dedupe_key' => 'habilitacion_examen_programado:' . $workerExam->id . ':' . $workerExam->fecha_programacion->format('Ymd'),
        ]);
    }

    public function bienestarBloqueoRegistrado(PersonalBloqueo $bloqueo, Usuario $actor): void
    {
        $bloqueo->loadMissing('personal');

        $this->emit('bienestar_bloqueo_registrado', [
            'actor_user_id' => (string) $actor->id,
            'entity_type' => PersonalBloqueo::class,
            'entity_id' => (string) $bloqueo->id,
            'priority' => 'high',
            'message' => sprintf(
                '%s no estara disponible del %s al %s por %s.',
                $bloqueo->personal?->nombre_completo ?: 'Trabajador',
                optional($bloqueo->fecha_inicio)->format('d/m/Y') ?: '-',
                optional($bloqueo->fecha_fin)->format('d/m/Y') ?: '-',
                $bloqueo->tipoLabel()
            ),
            'payload' => [
                'personal_id' => (string) $bloqueo->personal_id,
                'bloqueo_id' => (string) $bloqueo->id,
                'fecha_inicio' => optional($bloqueo->fecha_inicio)->toDateString(),
                'fecha_fin' => optional($bloqueo->fecha_fin)->toDateString(),
            ],
            'dedupe_key' => 'bienestar_bloqueo_registrado:' . $bloqueo->id,
        ]);
    }

    public function bienestarBloqueoAnulado(PersonalBloqueo $bloqueo, Usuario $actor): void
    {
        $bloqueo->loadMissing('personal');

        $this->emit('bienestar_bloqueo_anulado', [
            'actor_user_id' => (string) $actor->id,
            'entity_type' => PersonalBloqueo::class,
            'entity_id' => (string) $bloqueo->id,
            'priority' => 'medium',
            'message' => sprintf(
                'Se anulo el bloqueo de %s del %s al %s.',
                $bloqueo->personal?->nombre_completo ?: 'trabajador',
                optional($bloqueo->fecha_inicio)->format('d/m/Y') ?: '-',
                optional($bloqueo->fecha_fin)->format('d/m/Y') ?: '-'
            ),
            'payload' => [
                'personal_id' => (string) $bloqueo->personal_id,
                'bloqueo_id' => (string) $bloqueo->id,
            ],
            'dedupe_key' => 'bienestar_bloqueo_anulado:' . $bloqueo->id,
        ]);
    }

    public function rqMinaPedidoModificado(RQMina $rqMina, ?RQProserge $rqProserge, Usuario $actor, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $rqMina->loadMissing('mina');
        $lastChangeId = collect($changes)->pluck('id')->filter()->last();

        $this->emit('rq_mina_pedido_modificado', [
            'actor_user_id' => (string) $actor->id,
            'mine_id' => (string) $rqMina->mina_id,
            'entity_type' => RQProserge::class,
            'entity_id' => (string) ($rqProserge?->id ?: $rqMina->id),
            'priority' => 'high',
            'message' => sprintf(
                'El pedido de %s tuvo %d cambio(s). RRHH debe revisar cobertura y asignaciones.',
                $rqMina->destino_nombre ?: $rqMina->mina?->nombre ?: 'RQ Mina',
                count($changes)
            ),
            'payload' => [
                'rq_mina_id' => (string) $rqMina->id,
                'rq_proserge_id' => $rqProserge?->id,
                'cambios' => collect($changes)->map(fn ($change): array => [
                    'id' => (string) ($change['id'] ?? ''),
                    'mensaje' => (string) ($change['mensaje'] ?? ''),
                    'tipo' => (string) ($change['tipo'] ?? ''),
                    'puesto' => (string) ($change['puesto'] ?? ''),
                ])->values()->all(),
            ],
            'dedupe_key' => 'rq_mina_pedido_modificado:' . $rqMina->id . ':' . ($lastChangeId ?: now()->format('YmdHis')),
        ]);
    }

    public function rqProsergeCompletado(RQProserge $rqProserge, ?Usuario $actor = null): void
    {
        $rqProserge->loadMissing(['rqMina.creador', 'rqMina.mina']);
        $creatorId = $rqProserge->rqMina?->created_by_usuario_id;

        $this->emit('rq_proserge_completado', [
            'actor_user_id' => $actor ? (string) $actor->id : null,
            'mine_id' => (string) $rqProserge->mina_id,
            'target_user_ids' => $creatorId ? [(string) $creatorId] : [],
            'require_permission' => false,
            'entity_type' => RQProserge::class,
            'entity_id' => (string) $rqProserge->id,
            'priority' => 'medium',
            'message' => sprintf(
                'El RQ Proserge de %s ya fue completado.',
                $rqProserge->rqMina?->destino_nombre ?: $rqProserge->rqMina?->mina?->nombre ?: 'la parada'
            ),
            'payload' => [
                'rq_mina_id' => (string) $rqProserge->rq_mina_id,
                'rq_proserge_id' => (string) $rqProserge->id,
            ],
            'dedupe_key' => 'rq_proserge_completado:' . $rqProserge->id,
        ]);
    }

    private function emit(string $typeCode, array $context): void
    {
        try {
            $this->notifications->emit($typeCode, $context);
        } catch (Throwable $exception) {
            Log::error('notificaciones.operational_emit_failed', [
                'type_code' => $typeCode,
                'entity_type' => $context['entity_type'] ?? null,
                'entity_id' => $context['entity_id'] ?? null,
                'error_message' => $exception->getMessage(),
            ]);
        }
    }
}
