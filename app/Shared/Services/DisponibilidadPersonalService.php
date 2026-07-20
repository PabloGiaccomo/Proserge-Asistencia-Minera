<?php

namespace App\Shared\Services;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalMina;
use App\Models\RQProsergeDetalle;
use Illuminate\Support\Facades\DB;
use Throwable;

class DisponibilidadPersonalService
{
    public function evaluar(
        string $personalId,
        string $minaId,
        string $fechaInicio,
        string $fechaFin,
        ?string $excludeAsignacionId = null
    ): array {
        try {
            $personal = Personal::query()->find($personalId);

            if (!$personal) {
                return $this->businessUnavailable(
                    'PERSONAL_NOT_FOUND',
                    'Personal no existe',
                    ['No se encontro el trabajador seleccionado en la base de datos.']
                );
            }

            $blockers = [];
            $okLines = [];
            $mineStatus = $this->mineStatusPayload(null);

            if (strtoupper((string) $personal->estado) === 'CESADO') {
                $blockers[] = [
                    'code' => 'PERSONAL_CEASED',
                    'message' => 'Personal cesado',
                    'line' => 'Estado laboral: CESADO. No se puede asignar a una parada activa.',
                ];
            } else {
                $okLines[] = 'Estado laboral apto para evaluar asignacion.';
            }

            $contractCoverage = $this->contractCoverageForRange($personalId, $fechaInicio, $fechaFin);
            if (!$contractCoverage) {
                $latestContract = $this->latestRelevantContract($personalId);

                if ($latestContract && $this->isClosedContractState((string) ($latestContract->estado ?? ''))) {
                    $blockers[] = [
                        'code' => 'PERSONAL_CONTRACT_CLOSED',
                        'message' => 'Personal con contrato cerrado o cesado',
                        'line' => 'Ultimo contrato '.$this->contractPeriodLine($latestContract).' en estado '.$this->contractStateLabel((string) ($latestContract->estado ?? '')).'. No se puede asignar sin un contrato vigente o en preparacion para el rango.',
                    ];
                } else {
                    $blockers[] = [
                        'code' => 'PERSONAL_WITHOUT_CONTRACT_DATES',
                        'message' => 'Personal sin contrato con fechas para el rango',
                        'line' => 'No tiene contrato activo o en preparacion con fechas que cubran el rango seleccionado.',
                    ];
                }
            } else {
                $okLines[] = 'Contrato con fechas registradas del '.$this->formatDate($contractCoverage->fecha_inicio ?? null).' al '.$this->formatDate($contractCoverage->fecha_fin ?? null).'.';
            }

            $personalMina = PersonalMina::query()
                ->where('personal_id', $personalId)
                ->where('mina_id', $minaId)
                ->where(function ($query): void {
                    $query->where('activo', true)
                        ->orWhereNull('activo');
                })
                ->orderByDesc('updated_at')
                ->first();

            if (!$personalMina) {
                $mineStatus = $this->mineStatusPayload(PersonalMina::ESTADO_NO_HABILITADO);
                $okLines[] = 'Sin habilitacion registrada para la mina seleccionada. Se permite asignar si no tiene otros bloqueos.';
            } else {
                $estadoMina = $personalMina->estadoHabilitacionActual();
                $mineStatus = $this->mineStatusPayload($estadoMina);

                if ($estadoMina === PersonalMina::ESTADO_HABILITADO) {
                    $okLines[] = 'Habilitado para la mina seleccionada.';
                } else {
                    $okLines[] = $this->mineStateLine($estadoMina).' Se permite asignar si no tiene otros bloqueos.';
                }
            }

            $bloqueo = DB::table('personal_bloqueo')
                ->where('personal_id', $personalId)
                ->where('estado', 'ACTIVO')
                ->whereDate('fecha_inicio', '<=', $fechaFin)
                ->whereDate('fecha_fin', '>=', $fechaInicio)
                ->orderBy('fecha_inicio')
                ->first();

            if ($bloqueo) {
                $blockers[] = [
                    'code' => 'PERSONAL_BLOCKED',
                    'message' => 'Personal bloqueado en el rango solicitado',
                    'line' => $this->bloqueoLine($bloqueo),
                ];
            } else {
                $okLines[] = 'Sin vacaciones, descanso medico u otro bloqueo en el rango seleccionado.';
            }

            $rqConflict = RQProsergeDetalle::query()
                ->when($excludeAsignacionId, fn ($q) => $q->where('id', '!=', $excludeAsignacionId))
                ->where('personal_id', $personalId)
                ->whereDate('fecha_inicio', '<=', $fechaFin)
                ->whereDate('fecha_fin', '>=', $fechaInicio)
                ->orderBy('fecha_inicio')
                ->first();

            if ($rqConflict) {
                $blockers[] = [
                    'code' => 'PERSONAL_CONFLICT_RQ',
                    'message' => 'Personal con conflicto en otro RQ en el rango solicitado',
                    'line' => 'Ya esta asignado en otro RQ entre '.$this->formatDate($rqConflict->fecha_inicio).' y '.$this->formatDate($rqConflict->fecha_fin).'.',
                ];
            } else {
                $okLines[] = 'Sin cruces con otros RQ en el rango seleccionado.';
            }

            $groupConflict = DB::table('grupo_trabajo_detalle as gtd')
                ->join('grupo_trabajo as gt', 'gt.id', '=', 'gtd.grupo_trabajo_id')
                ->where('gtd.personal_id', $personalId)
                ->whereBetween('gt.fecha', [$fechaInicio, $fechaFin])
                ->orderBy('gt.fecha')
                ->first();

            if ($groupConflict) {
                $blockers[] = [
                    'code' => 'PERSONAL_CONFLICT_MANPOWER',
                    'message' => 'Personal ya comprometido en grupo de trabajo',
                    'line' => 'Ya figura en un grupo de trabajo el '.$this->formatDate($groupConflict->fecha).'.',
                ];
            } else {
                $okLines[] = 'Sin cruces con grupos de trabajo en el rango seleccionado.';
            }

            if ($blockers !== []) {
                $first = $blockers[0];

                return $this->businessUnavailable(
                    (string) $first['code'],
                    (string) $first['message'],
                    array_values(array_map(fn (array $blocker): string => (string) $blocker['line'], $blockers)),
                    ['mina_estado' => $mineStatus]
                );
            }

            return [
                'ok' => true,
                'available' => true,
                'reason_code' => null,
                'reason_message' => null,
                'lineas' => $okLines,
                'technical_error' => false,
                'mina_estado' => $mineStatus,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'available' => false,
                'reason_code' => 'TECHNICAL_ERROR',
                'reason_message' => 'Error tecnico validando disponibilidad',
                'lineas' => ['No se pudo validar la disponibilidad. Intenta nuevamente.'],
                'technical_error' => true,
                'exception' => $e,
            ];
        }
    }

    private function businessUnavailable(string $reasonCode, string $reasonMessage, array $lineas = [], array $extra = []): array
    {
        return array_merge([
            'ok' => true,
            'available' => false,
            'reason_code' => $reasonCode,
            'reason_message' => $reasonMessage,
            'lineas' => $lineas !== [] ? $lineas : [$reasonMessage],
            'technical_error' => false,
        ], $extra);
    }

    private function contractCoverageForRange(string $personalId, string $fechaInicio, string $fechaFin): ?object
    {
        return DB::table('personal_contratos')
            ->where('personal_id', $personalId)
            ->whereIn('estado', [PersonalContrato::ESTADO_ACTIVO, PersonalContrato::ESTADO_PREPARACION])
            ->whereNotNull('fecha_inicio')
            ->whereDate('fecha_inicio', '<=', $fechaFin)
            ->where(function ($query) use ($fechaInicio): void {
                $query->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $fechaInicio);
            })
            ->orderByRaw("FIELD(estado, 'ACTIVO', 'PREPARACION')")
            ->orderByDesc('fecha_inicio')
            ->first();
    }

    private function latestRelevantContract(string $personalId): ?object
    {
        return DB::table('personal_contratos')
            ->where('personal_id', $personalId)
            ->where('estado', '!=', PersonalContrato::ESTADO_ANULADO)
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('created_at')
            ->first();
    }

    private function isClosedContractState(string $state): bool
    {
        return in_array(strtoupper($state), [
            PersonalContrato::ESTADO_CERRADO,
            PersonalContrato::ESTADO_CESADO,
            PersonalContrato::ESTADO_NO_RENOVADO,
        ], true);
    }

    private function contractPeriodLine(object $contract): string
    {
        return 'del '.$this->formatDate($contract->fecha_inicio ?? null).' al '.$this->formatDate($contract->fecha_fin ?? null);
    }

    private function contractStateLabel(string $state): string
    {
        return match (strtoupper($state)) {
            PersonalContrato::ESTADO_CERRADO => 'CERRADO',
            PersonalContrato::ESTADO_CESADO => 'CESADO',
            PersonalContrato::ESTADO_NO_RENOVADO => 'NO RENOVADO',
            default => strtoupper($state),
        };
    }

    private function mineStateReasonCode(string $estadoMina): string
    {
        return match ($estadoMina) {
            PersonalMina::ESTADO_EN_PROCESO => 'PERSONAL_MINE_IN_PROCESS',
            PersonalMina::ESTADO_NO_HABILITADO => 'PERSONAL_MINE_NOT_ENABLED',
            PersonalMina::ESTADO_OBSERVADO => 'PERSONAL_MINE_OBSERVED',
            PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION => 'PERSONAL_MINE_REJECTED',
            default => 'PERSONAL_MINE_NOT_ASSIGNABLE',
        };
    }

    private function mineStateReasonMessage(string $estadoMina): string
    {
        return match ($estadoMina) {
            PersonalMina::ESTADO_EN_PROCESO => 'Habilitacion minera en proceso',
            PersonalMina::ESTADO_NO_HABILITADO => 'Personal no habilitado en la mina',
            PersonalMina::ESTADO_OBSERVADO => 'Habilitacion minera observada',
            PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION => 'Proceso de habilitacion finalizado por desaprobacion',
            default => 'Estado de habilitacion minera no asignable',
        };
    }

    private function mineStateLine(string $estadoMina): string
    {
        return match ($estadoMina) {
            PersonalMina::ESTADO_EN_PROCESO => 'Habilitacion minera en proceso para la mina seleccionada.',
            PersonalMina::ESTADO_NO_HABILITADO => 'No habilitado para la mina seleccionada.',
            PersonalMina::ESTADO_OBSERVADO => 'Habilitacion minera observada para la mina seleccionada.',
            PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION => 'Proceso de habilitacion finalizado por desaprobacion.',
            default => 'Estado de habilitacion minera no asignable: '.$estadoMina.'.',
        };
    }

    private function mineStatusPayload(?string $estadoMina): array
    {
        $state = strtoupper((string) ($estadoMina ?: PersonalMina::ESTADO_NO_HABILITADO));

        return match ($state) {
            PersonalMina::ESTADO_HABILITADO => [
                'estado' => PersonalMina::ESTADO_HABILITADO,
                'label' => 'Habilitado en mina',
                'class' => 'is-enabled',
            ],
            PersonalMina::ESTADO_EN_PROCESO => [
                'estado' => PersonalMina::ESTADO_EN_PROCESO,
                'label' => 'En proceso en mina',
                'class' => 'is-process',
            ],
            default => [
                'estado' => PersonalMina::ESTADO_NO_HABILITADO,
                'label' => 'No habilitado en mina',
                'class' => 'is-not-enabled',
            ],
        };
    }

    private function bloqueoLine(object $bloqueo): string
    {
        $tipo = ucfirst(str_replace('_', ' ', strtolower((string) ($bloqueo->tipo ?? 'bloqueo'))));
        $motivo = trim((string) ($bloqueo->motivo ?? ''));
        $detalle = $motivo !== '' ? ' Motivo: '.$motivo.'.' : '';

        return $tipo.' entre '.$this->formatDate($bloqueo->fecha_inicio ?? null).' y '.$this->formatDate($bloqueo->fecha_fin ?? null).'.'.$detalle;
    }

    private function formatDate(mixed $date): string
    {
        return $date ? (string) $date : 'fecha no registrada';
    }
}
