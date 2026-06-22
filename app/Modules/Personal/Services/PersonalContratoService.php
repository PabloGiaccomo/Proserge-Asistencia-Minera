<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalContratoDato;
use App\Models\PersonalFicha;
use App\Models\PersonalPuesto;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalContratoService
{
    private const RENEWAL_GRACE_DAYS_AFTER_END = 7;

    public function __construct(private readonly OperationalNotificationService $operationalNotifications)
    {
    }

    public function listForPersonal(Personal $personal, ?Usuario $user = null)
    {
        if (!Schema::hasTable('personal_contratos')) {
            return collect();
        }

        $this->ensureHistoricalContractForCeased($personal, $user);

        return PersonalContrato::query()
            ->with(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal'])
            ->where('personal_id', $personal->id)
            ->orderBy('contrato_numero')
            ->get();
    }

    public function findForPersonal(Personal $personal, string $contractId): ?PersonalContrato
    {
        if (!Schema::hasTable('personal_contratos')) {
            return null;
        }

        return PersonalContrato::query()
            ->with(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal'])
            ->where('personal_id', $personal->id)
            ->find($contractId);
    }

    public function listExpiringContracts(array $filters)
    {
        if (!Schema::hasTable('personal_contratos')) {
            return collect();
        }

        $rawMonth = $filters['mes'] ?? null;
        $rawYear = $filters['anio'] ?? null;
        $month = is_numeric($rawMonth) ? max(1, min(12, (int) $rawMonth)) : Carbon::today()->month;
        $year = is_numeric($rawYear) ? max(2000, min(2100, (int) $rawYear)) : Carbon::today()->year;
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();
        $trabajador = trim((string) ($filters['trabajador'] ?? ''));
        $isWorkerHistoryMode = $trabajador !== '';

        $query = PersonalContrato::query()
            ->with(['personal.listaNegraPor.personal', 'decisionUsuario.personal']);

        if ($isWorkerHistoryMode) {
            $query
                ->where('estado', '!=', PersonalContrato::ESTADO_ANULADO)
                ->whereHas('personal', function ($personalQuery) use ($trabajador): void {
                    foreach (preg_split('/\s+/', $trabajador, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
                        $personalQuery->where('nombre_completo', 'like', '%' . $term . '%');
                    }
                })
                ->orderBy('contrato_numero');
        } else {
            $query
                ->whereIn('estado', [
                    PersonalContrato::ESTADO_ACTIVO,
                    PersonalContrato::ESTADO_CERRADO,
                    PersonalContrato::ESTADO_CESADO,
                    PersonalContrato::ESTADO_NO_RENOVADO,
                ])
                ->whereNotNull('fecha_fin')
                ->whereDate('fecha_fin', '>=', $start->toDateString())
                ->whereDate('fecha_fin', '<=', $end->toDateString())
                ->orderBy('fecha_fin')
                ->orderBy('contrato_numero');
        }

        $cargo = trim((string) ($filters['cargo'] ?? ''));
        if (!$isWorkerHistoryMode && $cargo !== '') {
            $query->where(function ($query) use ($cargo): void {
                $query->where('puesto', 'like', '%' . $cargo . '%')
                    ->orWhereHas('personal', fn ($personalQuery) => $personalQuery->where('puesto', 'like', '%' . $cargo . '%'));
            });
        }

        $estadoLaboral = strtoupper(trim((string) ($filters['estado_laboral'] ?? '')));
        if (!$isWorkerHistoryMode && in_array($estadoLaboral, ['ACTIVO', 'FALTA_CONTRATO', 'CESADO', 'INACTIVO'], true)) {
            $query->whereHas('personal', fn ($personalQuery) => $personalQuery->where('estado', $estadoLaboral));
        }

        $rawContractType = trim((string) ($filters['tipo_contrato'] ?? ''));
        if (!$isWorkerHistoryMode && $rawContractType !== '') {
            $contractType = PersonalNormalizer::contract($rawContractType);
            $searchValues = $this->contractTypeSearchValues($contractType);

            $query->where(function ($query) use ($searchValues): void {
                $query->whereIn(DB::raw('LOWER(TRIM(COALESCE(tipo_contrato, "")))'), $searchValues)
                    ->orWhereHas('personal', function ($personalQuery) use ($searchValues): void {
                        $personalQuery->whereIn(DB::raw('LOWER(TRIM(COALESCE(contrato, "")))'), $searchValues);
                    });
            });
        }

        return $this->decorateExpiringContracts($query->get());
    }

    public function contractTypeOptions(): array
    {
        $values = collect();

        if (Schema::hasTable('personal_contratos')) {
            $values = $values->merge(
                PersonalContrato::query()
                    ->whereNotNull('tipo_contrato')
                    ->select('tipo_contrato')
                    ->distinct()
                    ->pluck('tipo_contrato')
            );
        }

        if (Schema::hasTable('personal') && Schema::hasColumn('personal', 'contrato')) {
            $values = $values->merge(
                Personal::query()
                    ->whereNotNull('contrato')
                    ->select('contrato')
                    ->distinct()
                    ->pluck('contrato')
            );
        }

        $detected = $values
            ->map(fn ($type): string => PersonalNormalizer::text($type))
            ->filter(fn (string $type): bool => $type !== '')
            ->map(fn (string $type): string => PersonalNormalizer::contract($type))
            ->unique()
            ->values();

        $optionKeys = $detected->isNotEmpty()
            ? $detected
            : collect(['REG', 'FIJO', 'INTER', 'INDET']);

        return $optionKeys
            ->sortBy(fn (string $type): int => $this->contractTypeOptionOrder($type))
            ->mapWithKeys(fn (string $type): array => [$type => PersonalNormalizer::contractLabel($type)])
            ->all();
    }

    public function registerRenewalDecision(PersonalContrato $contract, array $payload, Usuario $user): PersonalContrato
    {
        if (!$this->isDecisionAllowedContract($contract)) {
            throw ValidationException::withMessages([
                'contrato' => 'Solo se puede registrar decision sobre contratos activos o vencidos dentro de los ultimos 7 dias.',
            ]);
        }

        $state = strtoupper(trim((string) ($payload['estado_decision_renovacion'] ?? PersonalContrato::DECISION_PENDIENTE)));
        if (!in_array($state, $this->decisionStateKeys(), true)) {
            throw ValidationException::withMessages([
                'estado_decision_renovacion' => 'Selecciona una decision valida.',
            ]);
        }

        $reason = strtoupper(trim((string) ($payload['motivo_no_renovacion'] ?? '')));
        $observation = mb_substr(PersonalNormalizer::text($payload['observacion_decision'] ?? ''), 0, 5000);
        $isNoRenewalDecision = in_array($state, [PersonalContrato::DECISION_NO_RENOVAR, PersonalContrato::DECISION_NO_RENOVADO], true);

        if ($isNoRenewalDecision && $reason === '') {
            throw ValidationException::withMessages([
                'motivo_no_renovacion' => 'El motivo de no renovacion es obligatorio.',
            ]);
        }

        if ($reason !== '' && !array_key_exists($reason, $this->noRenewalReasonOptions())) {
            throw ValidationException::withMessages([
                'motivo_no_renovacion' => 'Selecciona un motivo de no renovacion valido.',
            ]);
        }

        if ($isNoRenewalDecision && $reason === PersonalContrato::MOTIVO_OTRO && $observation === '') {
            throw ValidationException::withMessages([
                'observacion_decision' => 'La observacion es obligatoria cuando el motivo es otro.',
            ]);
        }

        if (!$isNoRenewalDecision) {
            $reason = '';
        }

        $contract->forceFill([
            'estado_decision_renovacion' => $state,
            'decision_final' => match (true) {
                $state === PersonalContrato::DECISION_RENOVAR => PersonalContrato::DECISION_RENOVAR,
                $isNoRenewalDecision => PersonalContrato::DECISION_NO_RENOVAR,
                default => null,
            },
            'motivo_no_renovacion' => $reason ?: null,
            'observacion_decision' => $observation ?: null,
            'fecha_decision' => now(),
            'usuario_decision_id' => $user->id,
        ])->save();

        $updated = $contract->fresh(['personal', 'decisionUsuario.personal']);
        $this->operationalNotifications->contratoDecision($updated, $user);

        return $updated;
    }

    public function prepareRenewalFromDecision(PersonalContrato $contract, array $payload, Usuario $user): PersonalContrato
    {
        $contract = PersonalContrato::query()
            ->with('personal')
            ->findOrFail($contract->id);

        if (!$this->isDecisionAllowedContract($contract)) {
            throw ValidationException::withMessages([
                'contrato' => 'Solo se puede preparar renovacion desde un contrato activo o vencido dentro de los ultimos 7 dias.',
            ]);
        }

        if (strtoupper((string) $contract->decision_final) !== PersonalContrato::DECISION_RENOVAR) {
            throw ValidationException::withMessages([
                'decision_final' => 'Registra primero la decision final de renovar.',
            ]);
        }

        $renewal = $this->prepareRenewal($contract->personal, $payload, $user);

        $this->markBaseRenewalPrepared($contract->fresh(), $user);

        return $renewal;
    }

    public function registerBulkRenewalDecision(array $contractIds, array $payload, Usuario $user): array
    {
        if (!Schema::hasTable('personal_contratos')) {
            throw ValidationException::withMessages([
                'contratos' => 'El historial de contratos no esta disponible.',
            ]);
        }

        $ids = collect($contractIds)
            ->map(fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages([
                'contract_ids' => 'Selecciona al menos un contrato.',
            ]);
        }

        $state = strtoupper(trim((string) ($payload['estado_decision_renovacion'] ?? PersonalContrato::DECISION_RENOVAR)));
        if (!in_array($state, $this->decisionStateKeys(), true)) {
            throw ValidationException::withMessages([
                'estado_decision_renovacion' => 'Selecciona una decision valida.',
            ]);
        }

        $fechaInicio = PersonalNormalizer::isoDate($payload['fecha_inicio'] ?? null);
        $fechaFin = PersonalNormalizer::isoDate($payload['fecha_fin'] ?? null);
        if ($state === PersonalContrato::DECISION_RENOVAR && !$fechaInicio) {
            throw ValidationException::withMessages([
                'fecha_inicio' => 'La fecha de inicio del nuevo contrato es obligatoria.',
            ]);
        }
        if ($fechaInicio && $fechaFin && $fechaFin < $fechaInicio) {
            throw ValidationException::withMessages([
                'fecha_fin' => 'La fecha de fin no puede ser anterior al inicio.',
            ]);
        }

        $contracts = PersonalContrato::query()
            ->with('personal')
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');

        return DB::transaction(function () use ($ids, $contracts, $payload, $user, $state, $fechaInicio, $fechaFin): array {
            $summary = [
                'procesados' => 0,
                'decisiones' => 0,
                'renovaciones' => 0,
                'omitidos' => 0,
                'errores' => [],
                'personal_ids' => [],
                'contract_ids' => [],
            ];

            foreach ($ids as $id) {
                /** @var PersonalContrato|null $contract */
                $contract = $contracts->get($id);
                if (!$contract) {
                    $summary['omitidos']++;
                    $summary['errores'][] = 'Contrato no encontrado: ' . $id;
                    continue;
                }

                $label = $contract->personal?->nombre_completo ?: $this->contractDisplayLabel($contract);

                try {
                    $this->registerRenewalDecision($contract, $payload, $user);
                    $summary['decisiones']++;

                    if ($state === PersonalContrato::DECISION_RENOVAR) {
                        $this->prepareRenewalFromDecision($contract->fresh(['personal']), [
                            'fecha_inicio' => $fechaInicio,
                            'fecha_fin' => $fechaFin,
                            'observacion_renovacion' => $payload['observacion_renovacion'] ?? $payload['observacion_decision'] ?? null,
                        ], $user);
                        $summary['renovaciones']++;
                    }

                    $summary['procesados']++;
                    $summary['personal_ids'][] = (string) $contract->personal_id;
                    $summary['contract_ids'][] = (string) $contract->id;
                } catch (ValidationException $exception) {
                    $summary['omitidos']++;
                    $summary['errores'][] = $label . ': ' . (collect($exception->errors())->flatten()->first() ?: 'No se pudo registrar la decision.');
                }
            }

            $summary['personal_ids'] = array_values(array_unique($summary['personal_ids']));
            $summary['contract_ids'] = array_values(array_unique($summary['contract_ids']));

            if ($summary['procesados'] === 0) {
                throw ValidationException::withMessages([
                    'contratos' => $summary['errores'][0] ?? 'No se pudo registrar la decision en los contratos seleccionados.',
                ]);
            }

            return $summary;
        });
    }

    public function closeAsNotRenewed(PersonalContrato $contract, array $payload, Usuario $user): PersonalContrato
    {
        $contract = PersonalContrato::query()
            ->with(['personal.fichaColaborador'])
            ->findOrFail($contract->id);

        if (strtoupper((string) $contract->estado) !== PersonalContrato::ESTADO_ACTIVO) {
            throw ValidationException::withMessages([
                'contrato' => 'Solo se puede cerrar un contrato activo como no renovado.',
            ]);
        }

        if (strtoupper((string) $contract->decision_final) !== PersonalContrato::DECISION_NO_RENOVAR) {
            throw ValidationException::withMessages([
                'decision_final' => 'Primero registra la decision final de no renovar.',
            ]);
        }

        $contractEnd = optional($contract->fecha_fin)->toDateString();
        if (!$contractEnd) {
            throw ValidationException::withMessages([
                'fecha_fin' => 'El contrato debe tener fecha de fin para cerrarse como no renovado.',
            ]);
        }

        $today = Carbon::today()->toDateString();
        $isEarlyClosure = $contractEnd > $today;
        $confirmedEarlyClosure = filter_var($payload['confirmar_cierre_anticipado'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $closureObservation = mb_substr(PersonalNormalizer::text($payload['observacion_cierre_no_renovacion'] ?? ''), 0, 5000);

        if ($isEarlyClosure && !$confirmedEarlyClosure) {
            throw ValidationException::withMessages([
                'confirmar_cierre_anticipado' => 'El contrato aun no vence. Confirme si desea cerrar anticipadamente.',
            ]);
        }

        if ($isEarlyClosure && $closureObservation === '') {
            throw ValidationException::withMessages([
                'observacion_cierre_no_renovacion' => 'La observacion es obligatoria para un cierre anticipado.',
            ]);
        }

        $ceseReason = strtoupper(trim((string) ($payload['motivo_cese_controlado'] ?? '')));
        if ($ceseReason === '' || !array_key_exists($ceseReason, $this->controlledCeaseReasonOptions())) {
            throw ValidationException::withMessages([
                'motivo_cese_controlado' => 'Selecciona un motivo de cese valido.',
            ]);
        }

        $ceaseObservation = mb_substr(PersonalNormalizer::text($payload['observacion_cese_controlado'] ?? ''), 0, 5000);
        if ($ceseReason === PersonalContrato::CESE_OTRO && $ceaseObservation === '') {
            throw ValidationException::withMessages([
                'observacion_cese_controlado' => 'La observacion es obligatoria cuando el motivo de cese es otro.',
            ]);
        }

        $fechaCese = PersonalNormalizer::isoDate($payload['fecha_cese'] ?? null)
            ?: ($isEarlyClosure ? $today : $contractEnd);

        $closed = DB::transaction(function () use ($contract, $user, $fechaCese, $ceseReason, $ceaseObservation, $closureObservation): PersonalContrato {
            $personal = $contract->personal;
            $keepsActive = $this->hasOtherCurrentSignedContract($contract);
            $motivoCeseLabel = $this->controlledCeaseReasonOptions()[$ceseReason] ?? $ceseReason;

            $contract->forceFill([
                'estado' => PersonalContrato::ESTADO_NO_RENOVADO,
                'estado_decision_renovacion' => PersonalContrato::DECISION_NO_RENOVADO,
                'decision_final' => PersonalContrato::DECISION_NO_RENOVAR,
                'motivo_cese' => $motivoCeseLabel,
                'cerrado_at' => now(),
                'cerrado_by_usuario_id' => $user->id,
                'fecha_cierre_no_renovacion' => now(),
                'usuario_cierre_no_renovacion_id' => $user->id,
                'observacion_cierre_no_renovacion' => $closureObservation ?: null,
                'motivo_cese_controlado' => $ceseReason,
                'observacion_cese_controlado' => $ceaseObservation ?: null,
                'fecha_cese_controlado' => $fechaCese,
                'snapshot_json' => $contract->snapshot_json ?: $this->buildSnapshot($personal, 'cierre_no_renovado', [
                    'fecha_cese' => $fechaCese,
                    'motivo_cese' => $motivoCeseLabel,
                    'conserva_activo_por_otro_contrato' => $keepsActive,
                    'contrato_numero' => $contract->contrato_numero,
                ], $contract),
            ])->save();

            if (!$keepsActive) {
                $personalData = ['estado' => 'CESADO'];
                if (Schema::hasColumn('personal', 'fecha_cese')) {
                    $personalData['fecha_cese'] = $fechaCese;
                }
                if (Schema::hasColumn('personal', 'motivo_cese')) {
                    $personalData['motivo_cese'] = trim($motivoCeseLabel . ($ceaseObservation !== '' ? ': ' . $ceaseObservation : ''));
                }
                if (Schema::hasColumn('personal', 'cesado_at')) {
                    $personalData['cesado_at'] = now();
                }
                if (Schema::hasColumn('personal', 'cesado_by_usuario_id')) {
                    $personalData['cesado_by_usuario_id'] = $user->id;
                }

                $personal->forceFill($personalData)->save();

                if ($personal->fichaColaborador) {
                    $fichaData = is_array($personal->fichaColaborador->datos_json ?? null)
                        ? $personal->fichaColaborador->datos_json
                        : [];
                    $fichaData['fecha_cese'] = $fechaCese;
                    $personal->fichaColaborador->forceFill(['datos_json' => $fichaData])->save();
                }
            } elseif (strtoupper((string) $personal->estado) !== 'ACTIVO') {
                $personal->forceFill(['estado' => 'ACTIVO'])->save();
            }

            return $contract->fresh(['personal', 'decisionUsuario.personal', 'cierreNoRenovacionUsuario.personal']);
        });

        $this->operationalNotifications->contratoNoRenovadoCerrado($closed, $user);

        return $closed;
    }

    public function correctContract(Personal $personal, string $contractId, array $payload, Usuario $user): PersonalContrato
    {
        if (!Schema::hasTable('personal_contratos')) {
            throw ValidationException::withMessages([
                'contrato' => 'El historial de contratos no esta disponible.',
            ]);
        }

        $contract = PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->find($contractId);

        if (!$contract) {
            throw ValidationException::withMessages([
                'contrato' => 'Contrato no encontrado.',
            ]);
        }

        if (strtoupper((string) $contract->estado) === PersonalContrato::ESTADO_ANULADO) {
            throw ValidationException::withMessages([
                'contrato' => 'No se puede corregir un contrato anulado.',
            ]);
        }

        $fechaInicio = PersonalNormalizer::isoDate($payload['fecha_inicio'] ?? null);
        $fechaFin = PersonalNormalizer::isoDate($payload['fecha_fin'] ?? null);
        $motivo = trim(PersonalNormalizer::text($payload['motivo_correccion'] ?? ''));

        if (!$fechaInicio) {
            throw ValidationException::withMessages([
                'fecha_inicio' => 'La fecha de inicio del contrato es obligatoria.',
            ]);
        }

        if ($fechaFin && $fechaFin < $fechaInicio) {
            throw ValidationException::withMessages([
                'fecha_fin' => 'La fecha de fin no puede ser anterior al inicio.',
            ]);
        }

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'motivo_correccion' => 'El motivo de correccion es obligatorio.',
            ]);
        }

        $this->assertContractPeriodAvailable($personal, $fechaInicio, $fechaFin, $contract->id);

        return DB::transaction(function () use ($personal, $contract, $payload, $user, $fechaInicio, $fechaFin, $motivo): PersonalContrato {
            $personal = Personal::query()->with(['fichaColaborador', 'minas', 'contratoDatos'])->findOrFail($personal->id);
            $contract = PersonalContrato::query()
                ->where('personal_id', $personal->id)
                ->findOrFail($contract->id);
            $before = $this->contractAuditPayload($contract);

            $contract->forceFill([
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'tipo_contrato' => PersonalNormalizer::text($payload['tipo_contrato'] ?? '') !== ''
                    ? PersonalNormalizer::contract($payload['tipo_contrato'])
                    : $contract->tipo_contrato,
                'puesto' => mb_substr(PersonalNormalizer::text($payload['puesto'] ?? $contract->puesto), 0, 191) ?: null,
                'area' => mb_substr(PersonalNormalizer::text($payload['area'] ?? $contract->area), 0, 191) ?: null,
                'mina' => mb_substr(PersonalNormalizer::text($payload['mina'] ?? $contract->mina), 0, 191) ?: null,
                'remuneracion' => mb_substr(PersonalNormalizer::text($payload['remuneracion'] ?? $contract->remuneracion), 0, 191) ?: null,
                'costo_hora' => mb_substr(PersonalNormalizer::text($payload['costo_hora'] ?? $contract->costo_hora), 0, 191) ?: null,
                'motivo_cese' => mb_substr(PersonalNormalizer::text($payload['motivo_cese'] ?? $contract->motivo_cese), 0, 2000) ?: null,
            ])->save();

            $contract = $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']);
            $this->syncEditableContractDataAfterCorrection($personal, $contract, $user);
            $contract = $this->refreshCorrectedContractSnapshot($personal, $contract, $motivo);
            $this->reconcilePersonalStateFromContracts($personal, $user);
            $contract = $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']);

            $after = $this->contractAuditPayload($contract);
            $this->recordContractAudit($contract, 'CORRECCION', $motivo, $before, $after, $user);

            return $contract;
        });
    }

    public function annulContract(Personal $personal, string $contractId, string $motivo, Usuario $user): PersonalContrato
    {
        if (!Schema::hasTable('personal_contratos')) {
            throw ValidationException::withMessages([
                'contrato' => 'El historial de contratos no esta disponible.',
            ]);
        }

        $contract = PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->find($contractId);

        if (!$contract) {
            throw ValidationException::withMessages([
                'contrato' => 'Contrato no encontrado.',
            ]);
        }

        if (strtoupper((string) $contract->estado) === PersonalContrato::ESTADO_ANULADO) {
            throw ValidationException::withMessages([
                'contrato' => 'El contrato ya se encuentra anulado.',
            ]);
        }

        $motivo = trim($motivo);
        if ($motivo === '') {
            throw ValidationException::withMessages([
                'motivo_anulacion' => 'El motivo de anulacion es obligatorio.',
            ]);
        }

        return DB::transaction(function () use ($personal, $contract, $motivo, $user): PersonalContrato {
            $personal = Personal::query()->with(['fichaColaborador', 'minas', 'contratoDatos'])->findOrFail($personal->id);
            $contract = PersonalContrato::query()
                ->where('personal_id', $personal->id)
                ->findOrFail($contract->id);
            $before = $this->contractAuditPayload($contract);

            $contract->forceFill([
                'estado' => PersonalContrato::ESTADO_ANULADO,
                'motivo_anulacion' => $motivo,
                'anulado_at' => now(),
                'anulado_by_usuario_id' => $user->id,
                'snapshot_json' => $contract->snapshot_json ?: $this->buildSnapshot($personal, 'anulacion_contrato', [
                    'motivo_anulacion' => $motivo,
                    'contrato_numero' => $contract->contrato_numero,
                ], $contract),
            ])->save();

            $contract = $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']);
            $after = $this->contractAuditPayload($contract);
            $this->recordContractAudit($contract, 'ANULACION', $motivo, $before, $after, $user);
            $this->syncPersonalStateAfterContractAnnulment($personal, $contract, $before, $user);

            return $contract;
        });
    }

    public function ensureHistoricalContractForCeased(Personal $personal, ?Usuario $user = null): ?PersonalContrato
    {
        if (!Schema::hasTable('personal_contratos')) {
            return null;
        }

        $alreadyHasContracts = PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->exists();

        if ($alreadyHasContracts || strtoupper((string) $personal->estado) !== 'CESADO') {
            return null;
        }

        $motivo = trim((string) ($personal->motivo_cese ?? ''));
        if ($motivo === '') {
            $motivo = 'Motivo no registrado';
        }

        $fechaCese = optional($personal->fecha_cese)->toDateString()
            ?: $this->currentContractEndDate($personal)
            ?: Carbon::today()->toDateString();

        return $this->closeCurrentContract($personal, $motivo, $user, $fechaCese);
    }

    public function ensureActiveContract(Personal $personal, ?Usuario $user = null): ?PersonalContrato
    {
        if (!Schema::hasTable('personal_contratos')) {
            return null;
        }

        $active = PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->whereIn('estado', [PersonalContrato::ESTADO_PREPARACION, PersonalContrato::ESTADO_ACTIVO])
            ->latest('contrato_numero')
            ->first();

        if ($active) {
            return $active;
        }

        $initialState = strtoupper((string) $personal->estado) === PersonalContratoDatoService::PENDING_STATE
            ? PersonalContrato::ESTADO_PREPARACION
            : PersonalContrato::ESTADO_ACTIVO;

        $fechaInicio = $this->currentContractStartDate($personal);
        $fechaFin = $this->currentContractEndDate($personal);
        if ($fechaInicio) {
            $this->assertContractPeriodAvailable($personal, $fechaInicio, $fechaFin);
        }

        return PersonalContrato::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'contrato_numero' => $this->nextContractNumber($personal),
            'estado' => $initialState,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'activado_at' => now(),
            'activado_by_usuario_id' => $user?->id,
            'personal_ficha_id' => $personal->fichaColaborador?->id,
            'snapshot_inicial_json' => $this->buildSnapshot($personal, 'inicio_contrato'),
        ]);
    }

    public function closeCurrentContract(Personal $personal, string $motivo, ?Usuario $user = null, ?string $fechaFin = null): ?PersonalContrato
    {
        if (!Schema::hasTable('personal_contratos')) {
            return null;
        }

        return DB::transaction(function () use ($personal, $motivo, $user, $fechaFin): PersonalContrato {
            $personal = Personal::query()->findOrFail($personal->id);
            $personal->loadMissing(['fichaColaborador', 'minas']);

            $contract = $this->ensureActiveContract($personal, $user);
            $contractEnd = PersonalNormalizer::isoDate($fechaFin) ?: Carbon::today()->toDateString();
            $contractStart = optional($contract->fecha_inicio)->toDateString() ?: $this->currentContractStartDate($personal);
            if ($contractStart) {
                $this->assertContractPeriodAvailable($personal, $contractStart, $contractEnd, $contract->id);
            }

            $contract->forceFill([
                'estado' => PersonalContrato::ESTADO_CERRADO,
                'fecha_inicio' => $contractStart,
                'fecha_fin' => $contractEnd,
                'motivo_cese' => trim($motivo),
                'cerrado_at' => now(),
                'cerrado_by_usuario_id' => $user?->id,
                'personal_ficha_id' => $personal->fichaColaborador?->id,
                'snapshot_json' => $this->buildSnapshot($personal, 'cierre_contrato', [
                    'motivo_cese' => trim($motivo),
                    'fecha_fin' => $contractEnd,
                    'contrato_numero' => $contract->contrato_numero,
                ], $contract),
            ])->save();

            return $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']);
        });
    }

    public function syncExpiredActiveContracts(?Usuario $user = null): int
    {
        if (!Schema::hasTable('personal_contratos')) {
            return 0;
        }

        $today = Carbon::today()->toDateString();
        $ids = PersonalContrato::query()
            ->where('estado', PersonalContrato::ESTADO_ACTIVO)
            ->whereNotNull('fecha_fin')
            ->whereDate('fecha_fin', '<', $today)
            ->pluck('id');

        $closed = 0;

        foreach ($ids->chunk(100) as $chunk) {
            foreach ($chunk as $id) {
                $contract = PersonalContrato::query()->find($id);
                if (!$contract) {
                    continue;
                }

                $this->reconcilePersonalStateFromContracts(
                    Personal::query()->findOrFail($contract->personal_id),
                    $user
                );

                $fresh = PersonalContrato::query()->find($id);
                if ($fresh && strtoupper((string) $fresh->estado) !== PersonalContrato::ESTADO_ACTIVO) {
                    $closed++;
                }
            }
        }

        $endedPersonalIds = PersonalContrato::query()
            ->select('personal_id')
            ->distinct()
            ->whereIn('estado', [
                PersonalContrato::ESTADO_CERRADO,
                PersonalContrato::ESTADO_CESADO,
                PersonalContrato::ESTADO_NO_RENOVADO,
            ])
            ->whereNotNull('fecha_fin')
            ->whereDate('fecha_fin', '<', $today)
            ->whereHas('personal', function ($query): void {
                $query->where('estado', '!=', 'CESADO');
            })
            ->pluck('personal_id');

        foreach ($endedPersonalIds->chunk(100) as $chunk) {
            foreach ($chunk as $personalId) {
                $personal = Personal::query()->find($personalId);
                if (!$personal) {
                    continue;
                }

                $this->reconcilePersonalStateFromContracts($personal, $user);
            }
        }

        return $closed;
    }

    public function reconcilePersonalStateFromContracts(Personal $personal, ?Usuario $user = null): Personal
    {
        if (!Schema::hasTable('personal_contratos')) {
            return $personal;
        }

        return DB::transaction(function () use ($personal, $user): Personal {
            $personal = Personal::query()
                ->with(['fichaColaborador', 'minas', 'contratoDatos'])
                ->lockForUpdate()
                ->findOrFail($personal->id);

            $today = Carbon::today()->toDateString();
            $expiredContracts = PersonalContrato::query()
                ->where('personal_id', $personal->id)
                ->where('estado', PersonalContrato::ESTADO_ACTIVO)
                ->whereNotNull('fecha_fin')
                ->whereDate('fecha_fin', '<', $today)
                ->orderBy('fecha_fin')
                ->get();

            $latestExpiredEnd = null;
            foreach ($expiredContracts as $expiredContract) {
                $closed = $this->closeExpiredActiveContract($expiredContract, $personal, $user);
                $closedEnd = optional($closed?->fecha_fin)->toDateString();
                if ($closedEnd && (!$latestExpiredEnd || $closedEnd > $latestExpiredEnd)) {
                    $latestExpiredEnd = $closedEnd;
                }
            }

            if ($this->hasCurrentSignedActiveContractForPersonal($personal->id)) {
                $this->markPersonalActiveFromCurrentContract($personal);

                return $personal->fresh(['fichaColaborador', 'minas', 'contratoDatos', 'contratosLaborales']);
            }

            if ($latestExpiredEnd) {
                $this->markPersonalCeasedByExpiredContract($personal, $latestExpiredEnd, $user);

                return $personal->fresh(['fichaColaborador', 'minas', 'contratoDatos', 'contratosLaborales']);
            }

            $latestEnded = $this->latestEndedContractForCease($personal);
            $latestEndedDate = optional($latestEnded?->fecha_fin)->toDateString();
            if ($latestEndedDate) {
                $this->markPersonalCeasedByExpiredContract($personal, $latestEndedDate, $user);
            }

            return $personal->fresh(['fichaColaborador', 'minas', 'contratoDatos', 'contratosLaborales']);
        });
    }

    public function activateNextContract(Personal $personal, string $fechaInicio, ?string $fechaFin, Usuario $user, array $payload = []): PersonalContrato
    {
        return $this->prepareReentry($personal, array_merge($payload, [
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'observacion' => 'Reingreso individual',
        ]), $user);
    }

    public function prepareRenewal(Personal $personal, array $payload, Usuario $user): PersonalContrato
    {
        if (!Schema::hasTable('personal_contratos')) {
            throw ValidationException::withMessages(['contrato' => 'El historial de contratos no esta disponible.']);
        }

        return DB::transaction(function () use ($personal, $payload, $user): PersonalContrato {
            $personal = Personal::query()->with(['fichaColaborador', 'minas', 'contratoDatos'])->findOrFail($personal->id);
            $this->assertNoPreparingContract($personal);

            $base = $this->currentRenewableContract($personal);
            if (!$base) {
                throw ValidationException::withMessages(['contrato' => 'No hay contrato renovable para este trabajador. Debe existir un contrato activo o vencido dentro de los ultimos 7 dias.']);
            }

            if (strtoupper((string) $base->estado) === PersonalContrato::ESTADO_ANULADO) {
                throw ValidationException::withMessages(['contrato' => 'No se puede renovar un contrato anulado.']);
            }

            $fechaInicio = PersonalNormalizer::isoDate($payload['fecha_inicio'] ?? null);
            $fechaFin = PersonalNormalizer::isoDate($payload['fecha_fin'] ?? null);
            if (!$fechaInicio) {
                throw ValidationException::withMessages(['fecha_inicio' => 'La fecha de inicio del nuevo contrato es obligatoria.']);
            }
            if ($fechaFin && $fechaFin < $fechaInicio) {
                throw ValidationException::withMessages(['fecha_fin' => 'La fecha de fin no puede ser anterior al inicio.']);
            }

            $this->assertContractPeriodAvailable($personal, $fechaInicio, $fechaFin);

            $contract = $this->createPreparationContractFromBase($personal, $base, $fechaInicio, $fechaFin, $user, PersonalContrato::MOVIMIENTO_RENOVACION, $payload);
            $this->putRenewalInPreparation($personal, $fechaInicio, $fechaFin, $contract, $user);
            $this->markPersonalPendingSignedContract($personal, true);
            $this->markBaseRenewalPrepared($base, $user);

            return $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']);
        });
    }

    public function uploadSignedFileForContract(Personal $personal, PersonalContrato $contract, UploadedFile $file, Usuario $user): PersonalContrato
    {
        if ((string) $contract->personal_id !== (string) $personal->id) {
            throw ValidationException::withMessages([
                'contrato' => 'El contrato no pertenece al trabajador seleccionado.',
            ]);
        }

        if (strtoupper((string) $contract->estado) === PersonalContrato::ESTADO_ANULADO) {
            throw ValidationException::withMessages([
                'contrato' => 'No se puede cargar PDF en un contrato anulado.',
            ]);
        }

        if (!$this->canUploadSignedFileForContract($contract)) {
            throw ValidationException::withMessages([
                'contrato' => 'El contrato ya esta registrado en el historial y no puede modificarse.',
            ]);
        }

        $path = $file->storeAs(
            'personal_contratos/' . $personal->id,
            'contrato_firmado_' . $this->contractFileSlug($contract) . '_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.pdf',
            'local',
        );

        $signed = DB::transaction(function () use ($personal, $contract, $file, $user, $path): PersonalContrato {
            $personal = Personal::query()->with(['fichaColaborador', 'minas', 'contratoDatos'])->findOrFail($personal->id);
            $contract = PersonalContrato::query()
                ->where('personal_id', $personal->id)
                ->findOrFail($contract->id);
            $wasPreparation = strtoupper((string) $contract->estado) === PersonalContrato::ESTADO_PREPARACION;
            $isEditableContract = $contract->isEditable();
            $fechaInicio = optional($contract->fecha_inicio)->toDateString();
            if ($fechaInicio) {
                $this->assertContractPeriodAvailable($personal, $fechaInicio, optional($contract->fecha_fin)->toDateString(), $contract->id);
            }

            $data = [
                'signed_at' => now(),
                'signed_by_usuario_id' => $user->id,
                'signed_contract_path' => $path,
                'signed_contract_original_name' => $file->getClientOriginalName(),
                'signed_contract_mime' => $file->getMimeType(),
                'signed_contract_size' => $file->getSize(),
                'archivo_pendiente_regularizacion' => false,
                'personal_ficha_id' => $personal->fichaColaborador?->id ?: $contract->personal_ficha_id,
            ];

            if ($wasPreparation) {
                $data['estado'] = PersonalContrato::ESTADO_ACTIVO;
                $data['activado_at'] = now();
                $data['activado_by_usuario_id'] = $user->id;
            }

            $contract->forceFill($data)->save();

            if ($isEditableContract) {
                $contractDataService = app(PersonalContratoDatoService::class);
                $datos = $contractDataService->ensureForPersonal($personal, [
                    'fecha_inicio_contrato' => optional($contract->fecha_inicio)->toDateString(),
                    'fecha_fin_contrato' => optional($contract->fecha_fin)->toDateString(),
                    'puesto' => $contract->puesto ?: $personal->puesto,
                    'sueldo_num' => $contract->remuneracion,
                    'sueldo_hora_paradas' => $contract->costo_hora,
                ], $user);

                $datos->forceFill([
                    'fecha_inicio_contrato' => optional($contract->fecha_inicio)->toDateString() ?: $datos->fecha_inicio_contrato,
                    'fecha_fin_contrato' => optional($contract->fecha_fin)->toDateString(),
                    'puesto' => $contract->puesto ?: $datos->puesto,
                    'sueldo_num' => $contract->remuneracion ?: $datos->sueldo_num,
                    'sueldo_hora_paradas' => $contract->costo_hora ?: $datos->sueldo_hora_paradas,
                    'fecha_firma' => optional($contract->signed_at)->toDateString(),
                    'signed_at' => $contract->signed_at,
                    'signed_contract_path' => $contract->signed_contract_path,
                    'signed_contract_original_name' => $contract->signed_contract_original_name,
                    'signed_contract_mime' => $contract->signed_contract_mime,
                    'signed_contract_size' => $contract->signed_contract_size,
                    'updated_by_usuario_id' => $user->id,
                ])->save();
            }

            if ($wasPreparation && strtoupper((string) $contract->tipo_movimiento) === PersonalContrato::MOVIMIENTO_RENOVACION) {
                $this->closeRenewedOriginContract($personal, $contract, $user);
            }

            if ($wasPreparation && strtoupper((string) $personal->estado) === PersonalContratoDatoService::PENDING_STATE) {
                $personal->forceFill(['estado' => 'ACTIVO'])->save();
            }

            $this->reconcilePersonalStateFromContracts($personal, $user);
            $this->markPersonalPendingSignedContract($personal, false);

            return $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']);
        });

        $this->operationalNotifications->contratoFirmado($personal, $signed, $user);

        return $signed;
    }

    public function canUploadSignedFileForContract(PersonalContrato $contract): bool
    {
        return strtoupper((string) $contract->estado) === PersonalContrato::ESTADO_PREPARACION
            && !$contract->hasSignedFile();
    }

    public function prepareReentry(Personal $personal, array $payload, Usuario $user): PersonalContrato
    {
        if (!Schema::hasTable('personal_contratos')) {
            throw ValidationException::withMessages(['contrato' => 'El historial de contratos no esta disponible.']);
        }

        return DB::transaction(function () use ($personal, $payload, $user): PersonalContrato {
            $personal = Personal::query()->with(['fichaColaborador', 'minas', 'contratoDatos', 'cesadoPor'])->findOrFail($personal->id);
            if (strtoupper((string) $personal->estado) !== 'CESADO') {
                throw ValidationException::withMessages(['contrato' => 'Solo se puede reingresar a un trabajador cesado.']);
            }

            $this->assertNoPreparingContract($personal);
            $base = $this->latestNonAnnulledContract($personal);
            if (!$base) {
                $motivo = trim((string) ($personal->motivo_cese ?? '')) ?: 'Cierre previo a reingreso';
                $base = $this->closeCurrentContract($personal, $motivo, $personal->cesadoPor ?: $user, optional($personal->fecha_cese)->toDateString() ?: Carbon::today()->toDateString());
            }

            if (!$base || strtoupper((string) $base->estado) === PersonalContrato::ESTADO_ANULADO) {
                throw ValidationException::withMessages(['contrato' => 'No hay contrato base valido para reingresar.']);
            }

            $fechaInicio = PersonalNormalizer::isoDate($payload['fecha_inicio'] ?? null) ?: Carbon::today()->toDateString();
            $fechaFin = PersonalNormalizer::isoDate($payload['fecha_fin'] ?? null);
            if ($fechaFin && $fechaFin < $fechaInicio) {
                throw ValidationException::withMessages(['fecha_fin' => 'La fecha de fin no puede ser anterior al inicio.']);
            }

            $this->assertContractPeriodAvailable($personal, $fechaInicio, $fechaFin);
            $this->applyReentryProfileData($personal, $payload, $fechaInicio, $fechaFin, $user);
            $personal = Personal::query()->with(['fichaColaborador', 'minas', 'contratoDatos', 'cesadoPor'])->findOrFail($personal->id);

            $contract = $this->createPreparationContractFromBase($personal, $base, $fechaInicio, $fechaFin, $user, PersonalContrato::MOVIMIENTO_REINGRESO, $payload);
            $this->putPersonalInPendingContract($personal, $fechaInicio, $fechaFin, $contract, $user);
            $this->markPersonalPendingSignedContract($personal, true);

            return $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']);
        });
    }

    private function applyReentryProfileData(Personal $personal, array $payload, string $fechaInicio, ?string $fechaFin, Usuario $user): void
    {
        $handledKeys = [
            'puesto', 'tipo_contrato', 'ocupacion', 'area', 'remuneracion', 'costo_hora',
            'banco', 'banco_otro', 'numero_cuenta', 'cci', 'sistema_pensionario',
            'tipo_comision', 'tipo_afp', 'cuspp',
        ];

        $hasEditableData = collect($handledKeys)->contains(fn (string $key): bool => array_key_exists($key, $payload));
        if (!$hasEditableData) {
            return;
        }

        $personalData = [];
        if (array_key_exists('puesto', $payload)) {
            $puestoText = mb_substr(PersonalNormalizer::text($payload['puesto'] ?? ''), 0, 191);
            if ($puestoText !== '') {
                $puestoCatalogo = $this->resolvePuestoCatalogo($puestoText);
                $personalData['puesto'] = $puestoCatalogo?->nombre ?: $puestoText;
                if ($puestoCatalogo && Schema::hasColumn('personal', 'puesto_id')) {
                    $personalData['puesto_id'] = $puestoCatalogo->id;
                }
            }
        }

        if (array_key_exists('tipo_contrato', $payload) && PersonalNormalizer::text($payload['tipo_contrato'] ?? '') !== '') {
            $personalData['contrato'] = PersonalNormalizer::contract($payload['tipo_contrato']);
        }

        if (array_key_exists('ocupacion', $payload)) {
            $ocupacion = mb_substr(PersonalNormalizer::text($payload['ocupacion'] ?? ''), 0, 191);
            $personalData['ocupacion'] = $ocupacion !== '' ? $ocupacion : null;
        }

        if ($personalData !== []) {
            $personal->forceFill($personalData)->save();
        }

        $contractDataPayload = [
            'fecha_inicio_contrato' => $fechaInicio,
            'fecha_fin_contrato' => $fechaFin,
            'puesto' => $personalData['puesto'] ?? ($payload['puesto'] ?? $personal->puesto),
            'sueldo_num' => $payload['remuneracion'] ?? null,
            'sueldo_hora_paradas' => $payload['costo_hora'] ?? null,
        ];
        app(PersonalContratoDatoService::class)->ensureForPersonal($personal->fresh() ?: $personal, $contractDataPayload, $user);

        $ficha = $personal->fichaColaborador;
        $fichaData = is_array($ficha?->datos_json ?? null) ? $ficha->datos_json : [];
        $fichaData = array_merge($fichaData, [
            'puesto' => $personalData['puesto'] ?? (array_key_exists('puesto', $payload) ? PersonalNormalizer::text($payload['puesto'] ?? '') : ($fichaData['puesto'] ?? $personal->puesto)),
            'contrato' => array_key_exists('tipo_contrato', $payload) && PersonalNormalizer::text($payload['tipo_contrato'] ?? '') !== ''
                ? PersonalNormalizer::contract($payload['tipo_contrato'])
                : ($fichaData['contrato'] ?? $personal->contrato),
            'ocupacion' => array_key_exists('ocupacion', $payload) ? PersonalNormalizer::text($payload['ocupacion'] ?? '') : ($fichaData['ocupacion'] ?? $personal->ocupacion),
            'fecha_ingreso' => $fechaInicio,
            'fecha_fin_contrato' => $fechaFin ?: '',
            'remuneracion' => array_key_exists('remuneracion', $payload) ? PersonalNormalizer::text($payload['remuneracion'] ?? '') : ($fichaData['remuneracion'] ?? ''),
            'banco' => array_key_exists('banco', $payload) ? PersonalNormalizer::text($payload['banco'] ?? '') : ($fichaData['banco'] ?? ''),
            'banco_otro' => array_key_exists('banco_otro', $payload) ? PersonalNormalizer::text($payload['banco_otro'] ?? '') : ($fichaData['banco_otro'] ?? ''),
            'numero_cuenta' => array_key_exists('numero_cuenta', $payload) ? PersonalNormalizer::text($payload['numero_cuenta'] ?? '') : ($fichaData['numero_cuenta'] ?? ''),
            'cci' => array_key_exists('cci', $payload) ? PersonalNormalizer::text($payload['cci'] ?? '') : ($fichaData['cci'] ?? ''),
            'sistema_pensionario' => array_key_exists('sistema_pensionario', $payload) ? PersonalNormalizer::text($payload['sistema_pensionario'] ?? '') : ($fichaData['sistema_pensionario'] ?? ''),
            'tipo_comision' => array_key_exists('tipo_comision', $payload) ? PersonalNormalizer::text($payload['tipo_comision'] ?? '') : ($fichaData['tipo_comision'] ?? ''),
            'tipo_afp' => array_key_exists('tipo_afp', $payload) ? PersonalNormalizer::text($payload['tipo_afp'] ?? '') : ($fichaData['tipo_afp'] ?? ''),
            'cuspp' => array_key_exists('cuspp', $payload) ? PersonalNormalizer::text($payload['cuspp'] ?? '') : ($fichaData['cuspp'] ?? ''),
        ]);

        $fichaData = app(PersonalFichaService::class)->normalizeFichaData($fichaData);
        if ($ficha) {
            $ficha->forceFill([
                'datos_json' => $fichaData,
                'macro_tipo_contrato' => $fichaData['contrato'] ?? $ficha->macro_tipo_contrato,
            ])->save();

            return;
        }

        PersonalFicha::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'estado' => PersonalFicha::ESTADO_APROBADO,
            'tipo_documento' => $personal->tipo_documento ?: 'DNI',
            'numero_documento' => $personal->numero_documento ?: $personal->dni,
            'macro_tipo_contrato' => $fichaData['contrato'] ?? $personal->contrato,
            'datos_json' => $fichaData,
            'created_by_usuario_id' => $user->id,
            'submitted_at' => now(),
            'approved_at' => now(),
            'approved_by_usuario_id' => $user->id,
        ]);
    }

    private function resolvePuestoCatalogo(string $puesto): ?PersonalPuesto
    {
        if (!Schema::hasTable('personal_puestos') || !Schema::hasColumn('personal', 'puesto_id')) {
            return null;
        }

        $nombre = mb_substr(trim($puesto), 0, 191);
        if ($nombre === '') {
            return null;
        }

        return PersonalPuesto::query()
            ->where('nombre', $nombre)
            ->where('activo', true)
            ->first();
    }

    public function registerLegacyContract(Personal $personal, array $payload, ?UploadedFile $signedFile, Usuario $user): PersonalContrato
    {
        if (!Schema::hasTable('personal_contratos')) {
            throw ValidationException::withMessages([
                'contrato' => 'El historial de contratos no esta disponible.',
            ]);
        }

        $fechaInicio = PersonalNormalizer::isoDate($payload['fecha_inicio'] ?? null);
        $fechaFin = PersonalNormalizer::isoDate($payload['fecha_fin'] ?? null);
        $fechaFirma = PersonalNormalizer::isoDate($payload['fecha_firma'] ?? null);
        $estadoLaboral = strtoupper(trim((string) ($payload['estado_laboral'] ?? 'FALTA_CONTRATO')));
        $estadoContratoInput = strtoupper(trim((string) ($payload['estado_contrato'] ?? 'VIGENTE')));
        $motivoCese = trim((string) ($payload['motivo_cese'] ?? ''));

        if (!$fechaInicio) {
            throw ValidationException::withMessages([
                'contrato.fecha_inicio' => 'La fecha de inicio del contrato es obligatoria.',
            ]);
        }

        if ($fechaFin && $fechaFin < $fechaInicio) {
            throw ValidationException::withMessages([
                'contrato.fecha_fin' => 'La fecha de fin no puede ser anterior al inicio.',
            ]);
        }

        if ($estadoLaboral === 'CESADO' && !$fechaFin) {
            throw ValidationException::withMessages([
                'contrato.fecha_fin' => 'Un trabajador antiguo cesado debe tener fecha de fin de contrato.',
            ]);
        }

        $this->assertContractPeriodAvailable($personal, $fechaInicio, $fechaFin);

        $isCurrent = $estadoLaboral !== 'CESADO' && $estadoContratoInput === 'VIGENTE';
        $hasSignedFile = $signedFile !== null;
        $contractState = match (true) {
            !$isCurrent => PersonalContrato::ESTADO_CERRADO,
            $hasSignedFile => PersonalContrato::ESTADO_ACTIVO,
            default => PersonalContrato::ESTADO_PREPARACION,
        };

        $storedFile = $hasSignedFile ? $this->storeLegacySignedFile($personal, $signedFile) : null;
        $now = now();

        return DB::transaction(function () use ($personal, $payload, $user, $fechaInicio, $fechaFin, $fechaFirma, $motivoCese, $isCurrent, $hasSignedFile, $contractState, $storedFile, $now): PersonalContrato {
            $personal = Personal::query()->with(['fichaColaborador', 'minas'])->findOrFail($personal->id);
            $contractData = [
                'id' => (string) Str::uuid(),
                'personal_id' => $personal->id,
                'contrato_numero' => $this->nextContractNumber($personal),
                'estado' => $contractState,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'motivo_cese' => $isCurrent ? null : ($motivoCese !== '' ? $motivoCese : 'Contrato historico'),
                'activado_at' => $isCurrent ? $now : null,
                'activado_by_usuario_id' => $isCurrent ? $user->id : null,
                'cerrado_at' => $isCurrent ? null : $now,
                'cerrado_by_usuario_id' => $isCurrent ? null : $user->id,
                'signed_at' => $storedFile ? ($fechaFirma ? Carbon::parse($fechaFirma)->startOfDay() : $now) : null,
                'signed_by_usuario_id' => $storedFile ? $user->id : null,
                'signed_contract_path' => $storedFile['path'] ?? null,
                'signed_contract_original_name' => $storedFile['original_name'] ?? null,
                'signed_contract_mime' => $storedFile['mime'] ?? null,
                'signed_contract_size' => $storedFile['size'] ?? null,
                'personal_ficha_id' => $personal->fichaColaborador?->id,
            ];

            foreach ($this->legacyContractOptionalColumns($payload, !$isCurrent, !$hasSignedFile, $user) as $column => $value) {
                if (Schema::hasColumn('personal_contratos', $column)) {
                    $contractData[$column] = $value;
                }
            }

            $contract = PersonalContrato::query()->create($contractData);
            $snapshot = $this->buildSnapshot($personal, $isCurrent ? 'registro_contrato_antiguo_vigente' : 'registro_contrato_historico', [
                'origen_registro' => 'ANTIGUO',
                'archivo_pendiente_regularizacion' => !$hasSignedFile,
                'observacion_historica' => trim((string) ($payload['observacion_historica'] ?? '')),
            ], $contract);

            $contract->forceFill([
                'snapshot_inicial_json' => $snapshot,
                'snapshot_json' => $contract->isHistoricalLocked() ? $snapshot : null,
            ])->save();

            app(PersonalContratoDatoService::class)->ensureForPersonal($personal, [
                'fecha_inicio_contrato' => $fechaInicio,
                'fecha_fin_contrato' => $fechaFin,
                'fecha_firma' => $fechaFirma,
                'puesto' => $payload['puesto'] ?? $personal->puesto,
                'sueldo_hora_paradas' => $payload['costo_hora'] ?? null,
                'sueldo_num' => $payload['remuneracion'] ?? null,
            ], $user);

            if ($isCurrent && $storedFile) {
                PersonalContratoDato::query()
                    ->where('personal_id', $personal->id)
                    ->update([
                        'signed_at' => $contract->signed_at,
                        'signed_contract_path' => $contract->signed_contract_path,
                        'signed_contract_original_name' => $contract->signed_contract_original_name,
                        'signed_contract_mime' => $contract->signed_contract_mime,
                        'signed_contract_size' => $contract->signed_contract_size,
                        'updated_by_usuario_id' => $user->id,
                        'updated_at' => $now,
                    ]);
            }

            $personalData = ['estado' => $isCurrent && $storedFile ? 'ACTIVO' : ($isCurrent ? PersonalContratoDatoService::PENDING_STATE : 'CESADO')];
            if ($personalData['estado'] === 'CESADO') {
                if (Schema::hasColumn('personal', 'fecha_cese')) {
                    $personalData['fecha_cese'] = $fechaFin;
                }
                if (Schema::hasColumn('personal', 'motivo_cese')) {
                    $personalData['motivo_cese'] = $motivoCese !== '' ? $motivoCese : 'Contrato historico';
                }
                if (Schema::hasColumn('personal', 'cesado_at')) {
                    $personalData['cesado_at'] = $now;
                }
                if (Schema::hasColumn('personal', 'cesado_by_usuario_id')) {
                    $personalData['cesado_by_usuario_id'] = $user->id;
                }
            }

            $personal->forceFill($personalData)->save();

            return $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']);
        });
    }

    public function syncLegacyContractForExisting(Personal $personal, array $payload, ?UploadedFile $signedFile, Usuario $user): array
    {
        if (!Schema::hasTable('personal_contratos')) {
            throw ValidationException::withMessages([
                'contrato' => 'El historial de contratos no esta disponible.',
            ]);
        }

        $personal = Personal::query()
            ->with(['fichaColaborador', 'minas', 'contratoDatos'])
            ->findOrFail($personal->id);
        $datos = $personal->contratoDatos;
        $fechaInicio = PersonalNormalizer::isoDate($payload['fecha_inicio'] ?? null)
            ?: optional($datos?->fecha_inicio_contrato)->toDateString()
            ?: $this->currentContractStartDate($personal);
        $fechaFin = PersonalNormalizer::isoDate($payload['fecha_fin'] ?? null)
            ?: optional($datos?->fecha_fin_contrato)->toDateString()
            ?: $this->currentContractEndDate($personal);
        $fechaFirma = $fechaInicio;
        $estadoPersonal = strtoupper((string) $personal->estado);
        $estadoContratoInput = strtoupper(trim((string) ($payload['estado_contrato'] ?? ($estadoPersonal === 'CESADO' ? 'CERRADO' : 'VIGENTE'))));

        if (!$fechaInicio) {
            throw ValidationException::withMessages([
                'fecha_inicio' => 'La fecha de inicio es necesaria para sincronizar el contrato.',
            ]);
        }

        if ($fechaFin && $fechaFin < $fechaInicio) {
            throw ValidationException::withMessages([
                'fecha_fin' => 'La fecha de fin no puede ser anterior al inicio.',
            ]);
        }

        $isCurrent = $estadoContratoInput === 'VIGENTE';
        $existingEquivalent = $this->findEquivalentContract($personal, $fechaInicio, $fechaFin, $isCurrent);
        $this->assertContractPeriodAvailable($personal, $fechaInicio, $fechaFin, $existingEquivalent?->id);

        $storedFile = $signedFile ? $this->storeLegacySignedFile($personal, $signedFile) : null;
        $legacyFile = !$storedFile ? $this->legacySignedFileFromContractData($datos) : null;
        $signedData = $storedFile ?: $legacyFile;
        if (!$signedData && $existingEquivalent?->hasSignedFile()) {
            $signedData = $this->signedFileFromContract($existingEquivalent);
        }
        if ($signedData) {
            $signedData['signed_at'] = Carbon::parse($fechaFirma)->startOfDay();
        }
        $hasSignedFile = $signedData !== null;
        $warning = null;

        if ($isCurrent && !$hasSignedFile) {
            $warning = 'El contrato vigente quedo registrado, pero falta asociar el contrato firmado. El trabajador no se activara hasta regularizar ese archivo.';
        }

        $contractState = match (true) {
            !$isCurrent => PersonalContrato::ESTADO_CERRADO,
            default => PersonalContrato::ESTADO_ACTIVO,
        };

        return DB::transaction(function () use ($personal, $payload, $user, $fechaInicio, $fechaFin, $fechaFirma, $isCurrent, $hasSignedFile, $signedData, $contractState, $warning): array {
            $contract = $this->findEquivalentContract($personal, $fechaInicio, $fechaFin, $isCurrent);
            $created = false;
            $currentActivationAt = Carbon::parse($fechaInicio)->startOfDay();

            if (!$contract) {
                $contract = PersonalContrato::query()->create([
                    'id' => (string) Str::uuid(),
                    'personal_id' => $personal->id,
                    'contrato_numero' => $this->nextContractNumber($personal),
                    'estado' => $contractState,
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                    'motivo_cese' => $isCurrent ? null : trim((string) ($payload['motivo_cese'] ?? $personal->motivo_cese ?? 'Contrato historico')),
                    'activado_at' => $isCurrent ? $currentActivationAt : null,
                    'activado_by_usuario_id' => $isCurrent ? $user->id : null,
                    'cerrado_at' => $isCurrent ? null : now(),
                    'cerrado_by_usuario_id' => $isCurrent ? null : $user->id,
                    'personal_ficha_id' => $personal->fichaColaborador?->id,
                ]);
                $created = true;
            } elseif ($contract->isHistoricalLocked()) {
                return [
                    'contract' => $contract,
                    'created' => false,
                    'warning' => 'Ya existia un contrato historico equivalente. No se modifico porque el historial es inamovible.',
                ];
            }

            $update = [
                'estado' => $contractState,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'personal_ficha_id' => $personal->fichaColaborador?->id ?: $contract->personal_ficha_id,
                'motivo_cese' => $isCurrent ? null : trim((string) ($payload['motivo_cese'] ?? $personal->motivo_cese ?? 'Contrato historico')),
                'activado_at' => $isCurrent ? ($contract->activado_at ?: $currentActivationAt) : null,
                'activado_by_usuario_id' => $isCurrent ? ($contract->activado_by_usuario_id ?: $user->id) : null,
                'cerrado_at' => $isCurrent ? null : ($contract->cerrado_at ?: now()),
                'cerrado_by_usuario_id' => $isCurrent ? null : ($contract->cerrado_by_usuario_id ?: $user->id),
            ];

            if ($signedData) {
                $update = array_merge($update, [
                    'signed_at' => $signedData['signed_at'] ?? now(),
                    'signed_by_usuario_id' => $user->id,
                    'signed_contract_path' => $signedData['path'],
                    'signed_contract_original_name' => $signedData['original_name'],
                    'signed_contract_mime' => $signedData['mime'],
                    'signed_contract_size' => $signedData['size'],
                ]);
            }

            foreach ($this->legacyContractOptionalColumns($payload, !$isCurrent, !$hasSignedFile, $user) as $column => $value) {
                if (Schema::hasColumn('personal_contratos', $column)) {
                    $update[$column] = $value;
                }
            }

            $contract->forceFill($update)->save();
            $snapshot = $this->buildSnapshot($personal, $created ? 'sincronizacion_contrato_antiguo' : 'regularizacion_contrato_antiguo', [
                'origen_registro' => $payload['origen_registro'] ?? 'ANTIGUO',
                'archivo_pendiente_regularizacion' => !$hasSignedFile,
                'contrato_existente_reutilizado' => !$created,
            ], $contract);

            $contract->forceFill([
                'snapshot_inicial_json' => $contract->snapshot_inicial_json ?: $snapshot,
                'snapshot_json' => $contract->isHistoricalLocked() ? ($contract->snapshot_json ?: $snapshot) : null,
            ])->save();

            if ($isCurrent) {
                $contractDataService = app(PersonalContratoDatoService::class);
                $contractData = $contractDataService->ensureForPersonal($personal, [
                    'fecha_inicio_contrato' => $fechaInicio,
                    'fecha_fin_contrato' => $fechaFin,
                    'fecha_firma' => $fechaFirma,
                    'puesto' => $payload['puesto'] ?? $personal->puesto,
                    'sueldo_num' => $payload['remuneracion'] ?? null,
                    'sueldo_hora_paradas' => $payload['costo_hora'] ?? null,
                ], $user);

                $contractDataUpdate = [
                    'fecha_firma' => $fechaFirma,
                    'updated_by_usuario_id' => $user->id,
                ];

                if ($signedData) {
                    $contractDataUpdate = array_merge($contractDataUpdate, [
                        'signed_at' => $signedData['signed_at'] ?? now(),
                        'signed_contract_path' => $signedData['path'],
                        'signed_contract_original_name' => $signedData['original_name'],
                        'signed_contract_mime' => $signedData['mime'],
                        'signed_contract_size' => $signedData['size'],
                    ]);
                }

                $contractData->forceFill($contractDataUpdate)->save();
            }

            if ($isCurrent) {
                $personalUpdate = ['estado' => $hasSignedFile ? 'ACTIVO' : PersonalContratoDatoService::PENDING_STATE];

                foreach (['fecha_cese', 'motivo_cese', 'cesado_at', 'cesado_by_usuario_id'] as $column) {
                    if (Schema::hasColumn('personal', $column)) {
                        $personalUpdate[$column] = null;
                    }
                }

                $personal->forceFill($personalUpdate)->save();
                $this->markPersonalPendingSignedContract($personal, !$hasSignedFile);
            }

            return [
                'contract' => $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']),
                'created' => $created,
                'warning' => $warning,
            ];
        });
    }

    public function editableContractForPersonal(Personal $personal, ?Usuario $user = null): ?PersonalContrato
    {
        if (!Schema::hasTable('personal_contratos')) {
            return null;
        }

        $contract = PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->whereIn('estado', [PersonalContrato::ESTADO_PREPARACION, PersonalContrato::ESTADO_ACTIVO])
            ->latest('contrato_numero')
            ->first();

        if ($contract) {
            return $contract;
        }

        $state = strtoupper((string) $personal->estado);
        if (in_array($state, [PersonalContratoDatoService::PENDING_STATE, 'ACTIVO'], true)) {
            return $this->ensureActiveContract($personal, $user);
        }

        return null;
    }

    public function assertContractEditable(Personal $personal, ?Usuario $user = null): PersonalContrato
    {
        $contract = $this->editableContractForPersonal($personal, $user);

        if (!$contract || !$contract->isEditable()) {
            throw ValidationException::withMessages([
                'contrato' => 'Solo se puede modificar el contrato vigente o en preparacion.',
            ]);
        }

        return $contract;
    }

    public function syncEditableContractData(Personal $personal, PersonalContratoDato $datos, Usuario $user): PersonalContrato
    {
        $contract = $this->assertContractEditable($personal, $user);
        $fechaInicio = optional($datos->fecha_inicio_contrato)->toDateString()
            ?: optional($contract->fecha_inicio)->toDateString();
        $fechaFin = optional($datos->fecha_fin_contrato)->toDateString();
        if ($fechaInicio) {
            $this->assertContractPeriodAvailable($personal, $fechaInicio, $fechaFin, $contract->id);
        }

        $contract->forceFill([
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'puesto' => $datos->puesto ?: $contract->puesto,
            'remuneracion' => $datos->sueldo_num ?: $contract->remuneracion,
            'costo_hora' => $datos->sueldo_hora_paradas ?: $contract->costo_hora,
            'tipo_contrato' => PersonalNormalizer::contract($personal->contrato ?? null) ?: $contract->tipo_contrato,
            'personal_ficha_id' => $personal->fichaColaborador?->id ?: $contract->personal_ficha_id,
            'snapshot_inicial_json' => $this->buildSnapshot($personal->fresh(['fichaColaborador', 'minas']) ?: $personal, 'datos_contrato_actualizados', [
                'contrato_numero' => $contract->contrato_numero,
            ], $contract),
        ])->save();

        return $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']);
    }

    public function markEditableContractSigned(Personal $personal, PersonalContratoDato $datos, Usuario $user): PersonalContrato
    {
        $signed = DB::transaction(function () use ($personal, $datos, $user): PersonalContrato {
            $personal = Personal::query()->with(['fichaColaborador', 'minas'])->findOrFail($personal->id);
            $contract = $this->assertContractEditable($personal, $user);
            $fechaInicio = optional($datos->fecha_inicio_contrato)->toDateString()
                ?: optional($contract->fecha_inicio)->toDateString()
                ?: $this->currentContractStartDate($personal);
            $fechaFin = optional($datos->fecha_fin_contrato)->toDateString();
            if ($fechaInicio) {
                $this->assertContractPeriodAvailable($personal, $fechaInicio, $fechaFin, $contract->id);
            }

            $contract->forceFill([
                'estado' => PersonalContrato::ESTADO_ACTIVO,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'signed_at' => $datos->signed_at ?: now(),
                'signed_by_usuario_id' => $user->id,
                'signed_contract_path' => $datos->signed_contract_path,
                'signed_contract_original_name' => $datos->signed_contract_original_name,
                'signed_contract_mime' => $datos->signed_contract_mime,
                'signed_contract_size' => $datos->signed_contract_size,
                'personal_ficha_id' => $personal->fichaColaborador?->id ?: $contract->personal_ficha_id,
                'snapshot_inicial_json' => $contract->snapshot_inicial_json ?: $this->buildSnapshot($personal, 'firma_contrato', [
                    'contrato_numero' => $contract->contrato_numero,
                ], $contract),
            ])->save();

            if (strtoupper((string) $contract->tipo_movimiento) === PersonalContrato::MOVIMIENTO_RENOVACION) {
                $this->closeRenewedOriginContract($personal, $contract, $user);
            }

            if (strtoupper((string) $personal->estado) === PersonalContratoDatoService::PENDING_STATE) {
                $personal->forceFill(['estado' => 'ACTIVO'])->save();
            }
            $this->markPersonalPendingSignedContract($personal, false);

            return $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']);
        });

        $this->operationalNotifications->contratoFirmado($personal, $signed, $user);

        return $signed;
    }

    public function contractLabel(PersonalContrato $contract): string
    {
        return $this->contractDisplayLabel($contract);
    }

    public function contractDisplayLabel(PersonalContrato $contract): string
    {
        return 'Contrato ' . $this->contractPeriodLabel($contract);
    }

    public function contractPeriodLabel(PersonalContrato $contract): string
    {
        $inicio = optional($contract->fecha_inicio)->format('d/m/Y') ?: 'sin inicio';
        $fin = optional($contract->fecha_fin)->format('d/m/Y') ?: 'vigente';

        return $inicio . ' al ' . $fin;
    }

    public function contractFileSlug(PersonalContrato $contract): string
    {
        $inicio = optional($contract->fecha_inicio)->toDateString() ?: 'sin-inicio';
        $fin = optional($contract->fecha_fin)->toDateString() ?: 'vigente';

        return Str::of($inicio . '_al_' . $fin)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString() ?: 'periodo';
    }

    public function decisionStateOptions(): array
    {
        return [
            PersonalContrato::DECISION_PENDIENTE => 'Pendiente',
            PersonalContrato::DECISION_EN_EVALUACION => 'En evaluacion',
            PersonalContrato::DECISION_RENOVAR => 'Renovar',
            PersonalContrato::DECISION_NO_RENOVAR => 'No renovar',
            PersonalContrato::DECISION_RENOVACION_PREPARADA => 'Renovacion preparada',
            PersonalContrato::DECISION_NO_RENOVADO => 'No renovado',
        ];
    }

    public function noRenewalReasonOptions(): array
    {
        return [
            PersonalContrato::MOTIVO_BAJO_DESEMPENO => 'Bajo desempeno',
            PersonalContrato::MOTIVO_FIN_NECESIDAD_OPERATIVA => 'Fin de necesidad operativa',
            PersonalContrato::MOTIVO_DECISION_AREA => 'Decision del area',
            PersonalContrato::MOTIVO_RENUNCIA => 'Renuncia',
            PersonalContrato::MOTIVO_FALTA_DOCUMENTACION => 'Falta de documentacion',
            PersonalContrato::MOTIVO_NO_APTO_MINA => 'No apto para mina',
            PersonalContrato::MOTIVO_OTRO => 'Otro',
        ];
    }

    public function controlledCeaseReasonOptions(): array
    {
        return [
            PersonalContrato::CESE_NO_RENOVACION_CONTRATO => 'No renovacion de contrato',
            PersonalContrato::CESE_RENUNCIA => 'Renuncia',
            PersonalContrato::CESE_FIN_CONTRATO => 'Fin de contrato',
            PersonalContrato::CESE_DECISION_AREA => 'Decision del area',
            PersonalContrato::CESE_OTRO => 'Otro',
        ];
    }

    public function buildSnapshot(Personal $personal, string $event, array $extra = [], ?PersonalContrato $contract = null): array
    {
        $personal->loadMissing([
            'minas',
            'fichaColaborador.familiares',
            'fichaColaborador.archivos',
            'usuario.rol',
            'usuario.rolesAdicionales',
            'usuario.scopesMina.mina',
            'contratoDatos',
            'bloqueos',
            'cesadoPor.personal',
        ]);

        $start = optional($contract?->fecha_inicio)->toDateString()
            ?: optional($personal->fecha_ingreso)->toDateString();
        $end = $extra['fecha_fin'] ?? optional($contract?->fecha_fin)->toDateString();
        $end = $end ?: Carbon::today()->toDateString();

        return [
            'evento' => $event,
            'capturado_at' => now()->toIso8601String(),
            'rango' => [
                'fecha_inicio' => $start,
                'fecha_fin' => $end,
            ],
            'extra' => $extra,
            'trabajador' => $this->modelAttributes($personal),
            'contrato' => $contract ? $this->modelAttributes($contract) : [],
            'datos_contrato' => $this->contractDataSnapshot($personal),
            'ficha' => $this->fichaSnapshot($personal),
            'documentos' => $this->documentSnapshot($personal),
            'usuario_proserge' => $this->userSnapshot($personal),
            'minas_sedes' => $this->mineSnapshot($personal),
            'bienestar' => $this->bloqueoSnapshot($personal, $start, $end),
            'paradas_y_asignaciones' => $this->assignmentSnapshot($personal, $start, $end),
            'asistencia' => $this->attendanceSnapshot($personal, $start, $end),
            'faltas' => $this->genericTableSnapshot('faltas', 'trabajador_id', $personal->id, 'fecha', $start, $end),
            'evaluaciones' => [
                'desempeno' => $this->genericTableSnapshot('evaluacion_desempeno', 'trabajador_id', $personal->id, 'fecha', $start, $end),
                'supervisor' => $this->genericTableSnapshot('evaluacion_supervisor', 'evaluado_id', $personal->id, 'fecha', $start, $end),
            ],
        ];
    }

    private function latestContract(Personal $personal): ?PersonalContrato
    {
        if (!Schema::hasTable('personal_contratos')) {
            return null;
        }

        return PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->latest('contrato_numero')
            ->first();
    }

    private function decorateExpiringContracts(Collection $contracts): Collection
    {
        if ($contracts->isEmpty()) {
            return $contracts;
        }

        $contractIds = $contracts->pluck('id')->filter()->values();
        $personalIds = $contracts->pluck('personal_id')->filter()->unique()->values();

        $renewalsByOrigin = PersonalContrato::query()
            ->whereIn('origen_contrato_id', $contractIds->all())
            ->where('estado', '!=', PersonalContrato::ESTADO_ANULADO)
            ->get()
            ->groupBy('origen_contrato_id');

        $contractsByPersonal = PersonalContrato::query()
            ->whereIn('personal_id', $personalIds->all())
            ->where('estado', '!=', PersonalContrato::ESTADO_ANULADO)
            ->orderBy('contrato_numero')
            ->get()
            ->groupBy('personal_id');

        return $contracts->map(function (PersonalContrato $contract) use ($renewalsByOrigin, $contractsByPersonal): PersonalContrato {
            $renewals = $renewalsByOrigin->get($contract->id, collect());
            $related = $contractsByPersonal->get($contract->personal_id, collect());
            $end = optional($contract->fecha_fin)->toDateString();
            $contractType = $this->effectiveContractType($contract);

            $hasPreparation = $renewals->contains(fn (PersonalContrato $item): bool => strtoupper((string) $item->estado) === PersonalContrato::ESTADO_PREPARACION)
                || $related->contains(fn (PersonalContrato $item): bool => strtoupper((string) $item->estado) === PersonalContrato::ESTADO_PREPARACION);

            $hasLaterContract = $renewals->isNotEmpty()
                || $related->contains(function (PersonalContrato $item) use ($contract, $end): bool {
                    if ($item->id === $contract->id) {
                        return false;
                    }

                    if ((int) $item->contrato_numero <= (int) $contract->contrato_numero) {
                        return false;
                    }

                    $start = optional($item->fecha_inicio)->toDateString();
                    return !$end || !$start || $start >= $end;
                });

            $explicitDecision = strtoupper(trim((string) ($contract->estado_decision_renovacion ?? '')));
            $visualDecision = $explicitDecision !== '' ? $explicitDecision : (
                $hasLaterContract ? PersonalContrato::DECISION_RENOVAR : PersonalContrato::DECISION_PENDIENTE
            );

            $contract->setAttribute('has_preparation_contract', $hasPreparation);
            $contract->setAttribute('has_later_contract', $hasLaterContract);
            $contract->setAttribute('estado_visual', $hasLaterContract ? 'RENOVADO' : strtoupper((string) $contract->estado));
            $contract->setAttribute('decision_visual', $visualDecision);
            $contract->setAttribute('decision_visual_inferida', $explicitDecision === '' && $hasLaterContract);
            $contract->setAttribute('can_register_decision', $this->isDecisionAllowedContract($contract));
            $contract->setAttribute('previous_contracts_summary', $this->previousContractSummaries($contract, $related));
            $contract->setAttribute('tipo_contrato_visual', $contractType);
            $contract->setAttribute('tipo_contrato_label', $contractType !== '' ? PersonalNormalizer::contractLabel($contractType) : '');

            return $contract;
        });
    }

    private function effectiveContractType(PersonalContrato $contract): string
    {
        $contractValue = PersonalNormalizer::text($contract->tipo_contrato);
        if ($contractValue !== '') {
            return PersonalNormalizer::contract($contractValue);
        }

        $personalValue = PersonalNormalizer::text($contract->personal?->contrato);
        if ($personalValue !== '') {
            return PersonalNormalizer::contract($personalValue);
        }

        return '';
    }

    private function contractTypeOptionOrder(string $type): int
    {
        return [
            'FIJO' => 10,
            'INTER' => 20,
            'INDET' => 30,
            'REG' => 40,
        ][$type] ?? 99;
    }

    /**
     * Valores historicos equivalentes a una clave contractual normalizada.
     * Se usan para que el filtro reconozca tanto contratos nuevos como datos antiguos del trabajador.
     */
    private function contractTypeSearchValues(string $type): array
    {
        $aliases = [
            'FIJO' => [
                'FIJO',
                'SE',
                'SERVICIO ESPECIFICO',
                'SERVICIO ESPECÍFICO',
                'PERSONAL FIJO',
                'PERSONAL FIJO / SERVICIO ESPECIFICO',
                'PERSONAL FIJO / SERVICIO ESPECÍFICO',
            ],
            'INTER' => [
                'INTER',
                'INTERMITENTE',
            ],
            'INDET' => [
                'INDET',
                'INDETERMINADO',
            ],
            'REG' => [
                'REG',
                'REGIMEN',
                'RÉGIMEN',
                'REGIMEN LABORAL',
                'RÉGIMEN LABORAL',
            ],
        ][$type] ?? [$type];

        return collect($aliases)
            ->merge([PersonalNormalizer::contractLabel($type)])
            ->map(fn (string $value): string => mb_strtolower(trim($value)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function previousContractSummaries(PersonalContrato $contract, Collection $related): Collection
    {
        return $related
            ->filter(function (PersonalContrato $item) use ($contract): bool {
                if ($item->id === $contract->id) {
                    return false;
                }

                return (int) $item->contrato_numero < (int) $contract->contrato_numero;
            })
            ->sortBy('contrato_numero')
            ->values()
            ->map(fn (PersonalContrato $item): array => [
                'numero' => (int) $item->contrato_numero,
                'fecha_inicio' => optional($item->fecha_inicio)->toDateString(),
                'fecha_fin' => optional($item->fecha_fin)->toDateString(),
                'puesto' => trim((string) ($item->puesto ?: '')),
                'remuneracion' => trim((string) ($item->remuneracion ?: '')),
                'costo_hora' => trim((string) ($item->costo_hora ?: '')),
            ]);
    }

    private function decisionStateKeys(): array
    {
        return array_keys($this->decisionStateOptions());
    }

    private function isDecisionAllowedContract(PersonalContrato $contract): bool
    {
        $state = strtoupper((string) $contract->estado);

        if ($state === PersonalContrato::ESTADO_ANULADO) {
            return false;
        }

        return $state === PersonalContrato::ESTADO_ACTIVO
            || $this->isWithinRenewalGrace($contract);
    }

    private function closeExpiredActiveContract(PersonalContrato $contract, Personal $personal, ?Usuario $user = null): ?PersonalContrato
    {
        $contract = PersonalContrato::query()
            ->whereKey($contract->id)
            ->lockForUpdate()
            ->first();

        if (!$contract || strtoupper((string) $contract->estado) !== PersonalContrato::ESTADO_ACTIVO) {
            return null;
        }

        $fechaFin = optional($contract->fecha_fin)->toDateString();
        if (!$fechaFin || $fechaFin >= Carbon::today()->toDateString()) {
            return null;
        }

        $motivo = trim((string) ($contract->motivo_cese ?: 'Termino de contrato'));

        $contract->forceFill([
            'estado' => PersonalContrato::ESTADO_CERRADO,
            'motivo_cese' => $motivo,
            'cerrado_at' => $contract->cerrado_at ?: now(),
            'cerrado_by_usuario_id' => $contract->cerrado_by_usuario_id ?: $user?->id,
            'personal_ficha_id' => $personal->fichaColaborador?->id ?: $contract->personal_ficha_id,
            'snapshot_json' => $contract->snapshot_json ?: $this->buildSnapshot($personal, 'cierre_contrato_vencido', [
                'motivo_cese' => $motivo,
                'fecha_fin' => $fechaFin,
                'contrato_numero' => $contract->contrato_numero,
            ], $contract),
        ])->save();

        return $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']);
    }

    private function hasCurrentSignedActiveContractForPersonal(string $personalId): bool
    {
        $today = Carbon::today()->toDateString();

        return PersonalContrato::query()
            ->where('personal_id', $personalId)
            ->where('estado', PersonalContrato::ESTADO_ACTIVO)
            ->whereNotNull('signed_at')
            ->whereNotNull('signed_contract_path')
            ->where('signed_contract_path', '!=', '')
            ->where(function ($query) use ($today): void {
                $query->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $today);
            })
            ->exists();
    }

    private function markPersonalActiveFromCurrentContract(Personal $personal): void
    {
        $data = ['estado' => 'ACTIVO'];
        if (Schema::hasColumn('personal', 'fecha_cese')) {
            $data['fecha_cese'] = null;
        }
        if (Schema::hasColumn('personal', 'motivo_cese')) {
            $data['motivo_cese'] = null;
        }
        if (Schema::hasColumn('personal', 'cesado_at')) {
            $data['cesado_at'] = null;
        }
        if (Schema::hasColumn('personal', 'cesado_by_usuario_id')) {
            $data['cesado_by_usuario_id'] = null;
        }

        $personal->forceFill($data)->save();

        if ($personal->fichaColaborador) {
            $fichaData = is_array($personal->fichaColaborador->datos_json ?? null)
                ? $personal->fichaColaborador->datos_json
                : [];
            $fichaData['fecha_cese'] = '';
            $personal->fichaColaborador->forceFill(['datos_json' => $fichaData])->save();
        }
    }

    private function markPersonalCeasedByExpiredContract(Personal $personal, string $fechaCese, ?Usuario $user = null): void
    {
        $data = ['estado' => 'CESADO'];
        if (Schema::hasColumn('personal', 'fecha_cese')) {
            $data['fecha_cese'] = $fechaCese;
        }
        if (Schema::hasColumn('personal', 'motivo_cese')) {
            $data['motivo_cese'] = 'Termino de contrato';
        }
        if (Schema::hasColumn('personal', 'cesado_at')) {
            $data['cesado_at'] = now();
        }
        if (Schema::hasColumn('personal', 'cesado_by_usuario_id') && $user?->id) {
            $data['cesado_by_usuario_id'] = $user->id;
        }

        $personal->forceFill($data)->save();

        if ($personal->fichaColaborador) {
            $fichaData = is_array($personal->fichaColaborador->datos_json ?? null)
                ? $personal->fichaColaborador->datos_json
                : [];
            $fichaData['fecha_cese'] = $fechaCese;
            $personal->fichaColaborador->forceFill(['datos_json' => $fichaData])->save();
        }
    }

    private function hasOtherCurrentSignedContract(PersonalContrato $contract): bool
    {
        $today = Carbon::today()->toDateString();

        return PersonalContrato::query()
            ->where('personal_id', $contract->personal_id)
            ->where('id', '!=', $contract->id)
            ->where('estado', PersonalContrato::ESTADO_ACTIVO)
            ->whereNotNull('signed_at')
            ->whereNotNull('signed_contract_path')
            ->where(function ($query) use ($today): void {
                $query->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $today);
            })
            ->exists();
    }

    private function latestEndedContractForCease(Personal $personal): ?PersonalContrato
    {
        return PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->whereIn('estado', [
                PersonalContrato::ESTADO_CERRADO,
                PersonalContrato::ESTADO_CESADO,
                PersonalContrato::ESTADO_NO_RENOVADO,
            ])
            ->whereNotNull('fecha_fin')
            ->whereDate('fecha_fin', '<', Carbon::today()->toDateString())
            ->orderByDesc('fecha_fin')
            ->orderByDesc('contrato_numero')
            ->first();
    }

    private function assertNoPreparingContract(Personal $personal): void
    {
        $preparing = PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->where('estado', PersonalContrato::ESTADO_PREPARACION)
            ->latest('contrato_numero')
            ->first();

        if ($preparing) {
            throw ValidationException::withMessages([
                'contrato' => 'Ya existe un contrato en preparacion para este trabajador. Revisalo antes de crear otro.',
            ]);
        }
    }

    private function currentRenewableContract(Personal $personal): ?PersonalContrato
    {
        $active = PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->where('estado', PersonalContrato::ESTADO_ACTIVO)
            ->latest('contrato_numero')
            ->first();

        if ($active) {
            return $active;
        }

        return PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->whereIn('estado', [
                PersonalContrato::ESTADO_CERRADO,
                PersonalContrato::ESTADO_CESADO,
            ])
            ->whereNotNull('fecha_fin')
            ->whereDate('fecha_fin', '>=', Carbon::today()->subDays(self::RENEWAL_GRACE_DAYS_AFTER_END)->toDateString())
            ->whereDate('fecha_fin', '<=', Carbon::today()->toDateString())
            ->orderByDesc('fecha_fin')
            ->orderByDesc('contrato_numero')
            ->first();
    }

    private function isWithinRenewalGrace(PersonalContrato $contract): bool
    {
        $state = strtoupper((string) $contract->estado);
        if (!in_array($state, [PersonalContrato::ESTADO_CERRADO, PersonalContrato::ESTADO_CESADO], true)) {
            return false;
        }

        $fechaFin = optional($contract->fecha_fin)->toDateString();
        if (!$fechaFin) {
            return false;
        }

        $today = Carbon::today();
        $endDate = Carbon::parse($fechaFin)->startOfDay();

        return $endDate->betweenIncluded(
            $today->copy()->subDays(self::RENEWAL_GRACE_DAYS_AFTER_END)->startOfDay(),
            $today->copy()->endOfDay()
        );
    }

    private function isCurrentSignedContractValid(PersonalContrato $contract): bool
    {
        if (!$contract->hasSignedFile()) {
            return false;
        }

        $end = optional($contract->fecha_fin)->toDateString();

        return !$end || $end >= Carbon::today()->toDateString();
    }

    private function closeRenewedOriginContract(Personal $personal, PersonalContrato $renewal, Usuario $user): void
    {
        if (!$renewal->origen_contrato_id) {
            return;
        }

        $base = PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->where('id', $renewal->origen_contrato_id)
            ->first();

        if (!$base || strtoupper((string) $base->estado) !== PersonalContrato::ESTADO_ACTIVO) {
            return;
        }

        $baseEnd = $this->renewalBaseEndDate($base, optional($renewal->fecha_inicio)->toDateString() ?: Carbon::today()->toDateString());
        $base->forceFill([
            'estado' => PersonalContrato::ESTADO_CERRADO,
            'fecha_fin' => $baseEnd,
            'motivo_cese' => 'Renovacion contractual',
            'cerrado_at' => now(),
            'cerrado_by_usuario_id' => $user->id,
            'snapshot_json' => $base->snapshot_json ?: $this->buildSnapshot($personal, 'cierre_por_renovacion', [
                'nuevo_contrato_id' => $renewal->id,
                'nuevo_contrato_numero' => $renewal->contrato_numero,
                'nuevo_inicio' => optional($renewal->fecha_inicio)->toDateString(),
                'contrato_numero' => $base->contrato_numero,
            ], $base),
        ])->save();
    }

    private function markBaseRenewalPrepared(?PersonalContrato $base, Usuario $user): void
    {
        if (!$base) {
            return;
        }

        $base->forceFill([
            'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVACION_PREPARADA,
            'decision_final' => PersonalContrato::DECISION_RENOVAR,
            'fecha_decision' => now(),
            'usuario_decision_id' => $user->id,
        ])->save();
    }

    private function latestNonAnnulledContract(Personal $personal): ?PersonalContrato
    {
        return PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->where('estado', '!=', PersonalContrato::ESTADO_ANULADO)
            ->latest('contrato_numero')
            ->first();
    }

    private function renewalBaseEndDate(PersonalContrato $base, string $fechaInicio): string
    {
        $newPreviousEnd = Carbon::parse($fechaInicio)->subDay()->toDateString();
        $currentEnd = optional($base->fecha_fin)->toDateString();

        if (!$currentEnd || $currentEnd >= $fechaInicio) {
            return $newPreviousEnd;
        }

        return $currentEnd;
    }

    private function createPreparationContractFromBase(
        Personal $personal,
        ?PersonalContrato $base,
        string $fechaInicio,
        ?string $fechaFin,
        Usuario $user,
        string $movement,
        array $payload
    ): PersonalContrato {
        $contractData = [
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'contrato_numero' => $this->nextContractNumber($personal),
            'estado' => PersonalContrato::ESTADO_PREPARACION,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'activado_at' => null,
            'activado_by_usuario_id' => null,
            'cerrado_at' => null,
            'cerrado_by_usuario_id' => null,
            'signed_at' => null,
            'signed_by_usuario_id' => null,
            'signed_contract_path' => null,
            'signed_contract_original_name' => null,
            'signed_contract_mime' => null,
            'signed_contract_size' => null,
            'origen_contrato_id' => $base?->id,
            'tipo_movimiento' => $movement,
            'observacion_renovacion' => mb_substr(PersonalNormalizer::text($payload['observacion_renovacion'] ?? $payload['observacion'] ?? ''), 0, 5000) ?: null,
            'personal_ficha_id' => $personal->fichaColaborador?->id,
        ];

        foreach ($this->preparationOptionalColumns($personal, $base, $movement, $payload, $user) as $column => $value) {
            if (Schema::hasColumn('personal_contratos', $column)) {
                $contractData[$column] = $value;
            }
        }

        $contract = PersonalContrato::query()->create($contractData);
        $snapshot = $this->buildSnapshot($personal, $movement === PersonalContrato::MOVIMIENTO_REINGRESO ? 'preparacion_reingreso' : 'preparacion_renovacion', [
            'contrato_anterior_id' => $base?->id,
            'contrato_anterior_numero' => $base?->contrato_numero,
            'tipo_movimiento' => $movement,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
        ], $contract);

        $contract->forceFill(['snapshot_inicial_json' => $snapshot])->save();

        return $contract;
    }

    private function preparationOptionalColumns(Personal $personal, ?PersonalContrato $base, string $movement, array $payload, Usuario $user): array
    {
        $datos = $personal->contratoDatos;
        return [
            'origen_registro' => $personal->origen_registro ?: ($base?->origen_registro ?: 'NUEVO'),
            'es_historico' => false,
            'archivo_pendiente_regularizacion' => true,
            'tipo_contrato' => PersonalNormalizer::text($payload['tipo_contrato'] ?? '') !== ''
                ? PersonalNormalizer::contract($payload['tipo_contrato'])
                : ($base?->tipo_contrato ?: PersonalNormalizer::contract($personal->contrato ?? null)),
            'puesto' => mb_substr(PersonalNormalizer::text($payload['puesto'] ?? ''), 0, 191)
                ?: $base?->puesto
                ?: $datos?->puesto
                ?: $personal->puesto,
            'area' => mb_substr(PersonalNormalizer::text($payload['area'] ?? ''), 0, 191) ?: $base?->area,
            'mina' => mb_substr(PersonalNormalizer::text($payload['mina'] ?? ''), 0, 191) ?: $base?->mina,
            'remuneracion' => mb_substr(PersonalNormalizer::text($payload['remuneracion'] ?? ''), 0, 191)
                ?: $base?->remuneracion
                ?: $datos?->sueldo_num,
            'costo_hora' => mb_substr(PersonalNormalizer::text($payload['costo_hora'] ?? ''), 0, 191)
                ?: $base?->costo_hora
                ?: $datos?->sueldo_hora_paradas,
            'es_supervisor' => array_key_exists('es_supervisor', $payload)
                ? filter_var($payload['es_supervisor'], FILTER_VALIDATE_BOOLEAN)
                : (bool) ($base?->es_supervisor ?? $personal->es_supervisor ?? false),
            'registrado_by_usuario_id' => $user->id,
        ];
    }

    private function putPersonalInPendingContract(
        Personal $personal,
        string $fechaInicio,
        ?string $fechaFin,
        PersonalContrato $contract,
        Usuario $user
    ): void {
        $contractDataService = app(PersonalContratoDatoService::class);
        $datos = $contractDataService->ensureForPersonal($personal, [
            'fecha_inicio_contrato' => $fechaInicio,
            'fecha_fin_contrato' => $fechaFin,
            'puesto' => $contract->puesto,
            'sueldo_num' => $contract->remuneracion,
            'sueldo_hora_paradas' => $contract->costo_hora,
        ], $user);

        $datos->forceFill([
            'fecha_inicio_contrato' => $fechaInicio,
            'fecha_fin_contrato' => $fechaFin,
            'puesto' => $contract->puesto ?: $datos->puesto,
            'sueldo_num' => $contract->remuneracion ?: $datos->sueldo_num,
            'sueldo_hora_paradas' => $contract->costo_hora ?: $datos->sueldo_hora_paradas,
            'fecha_firma' => null,
            'signed_at' => null,
            'signed_contract_path' => null,
            'signed_contract_original_name' => null,
            'signed_contract_mime' => null,
            'signed_contract_size' => null,
            'updated_by_usuario_id' => $user->id,
        ])->save();

        $personalData = [
            'estado' => PersonalContratoDatoService::PENDING_STATE,
            'fecha_ingreso' => $fechaInicio,
        ];

        if (Schema::hasColumn('personal', 'fecha_cese')) {
            $personalData['fecha_cese'] = null;
        }
        if (Schema::hasColumn('personal', 'motivo_cese')) {
            $personalData['motivo_cese'] = null;
        }
        if (Schema::hasColumn('personal', 'cesado_at')) {
            $personalData['cesado_at'] = null;
        }
        if (Schema::hasColumn('personal', 'cesado_by_usuario_id')) {
            $personalData['cesado_by_usuario_id'] = null;
        }

        $personal->forceFill($personalData)->save();

        if ($personal->fichaColaborador) {
            $fichaData = is_array($personal->fichaColaborador->datos_json ?? null)
                ? $personal->fichaColaborador->datos_json
                : [];
            $fichaData['fecha_ingreso'] = $fechaInicio;
            $fichaData['fecha_fin_contrato'] = $fechaFin ?: '';
            $personal->fichaColaborador->forceFill(['datos_json' => $fichaData])->save();
        }
    }

    private function putRenewalInPreparation(
        Personal $personal,
        string $fechaInicio,
        ?string $fechaFin,
        PersonalContrato $contract,
        Usuario $user
    ): void {
        $contractDataService = app(PersonalContratoDatoService::class);
        $datos = $contractDataService->ensureForPersonal($personal, [
            'fecha_inicio_contrato' => $fechaInicio,
            'fecha_fin_contrato' => $fechaFin,
            'puesto' => $contract->puesto,
            'sueldo_num' => $contract->remuneracion,
            'sueldo_hora_paradas' => $contract->costo_hora,
        ], $user);

        $datos->forceFill([
            'fecha_inicio_contrato' => $fechaInicio,
            'fecha_fin_contrato' => $fechaFin,
            'puesto' => $contract->puesto ?: $datos->puesto,
            'sueldo_num' => $contract->remuneracion ?: $datos->sueldo_num,
            'sueldo_hora_paradas' => $contract->costo_hora ?: $datos->sueldo_hora_paradas,
            'updated_by_usuario_id' => $user->id,
        ])->save();
    }

    private function markPersonalPendingSignedContract(Personal $personal, bool $pending): void
    {
        if (!Schema::hasColumn('personal', 'pendiente_contrato_firmado')) {
            return;
        }

        $personal->forceFill(['pendiente_contrato_firmado' => $pending])->save();
    }

    private function assertContractPeriodAvailable(Personal $personal, string $fechaInicio, ?string $fechaFin, ?string $ignoreContractId = null): void
    {
        if (!Schema::hasTable('personal_contratos')) {
            return;
        }

        $rangeEnd = $fechaFin ?: '9999-12-31';
        $query = PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->where('estado', '!=', PersonalContrato::ESTADO_ANULADO)
            ->whereNotNull('fecha_inicio')
            ->whereDate('fecha_inicio', '<=', $rangeEnd)
            ->where(function ($query) use ($fechaInicio): void {
                $query->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $fechaInicio);
            });

        if ($ignoreContractId) {
            $query->where('id', '!=', $ignoreContractId);
        }

        $conflict = $query
            ->orderBy('fecha_inicio')
            ->orderBy('fecha_fin')
            ->first();

        if (!$conflict) {
            return;
        }

        throw ValidationException::withMessages([
            'fecha_inicio' => sprintf(
                'Ya existe un contrato de este trabajador que cruza ese periodo: %s. Corrige las fechas antes de guardar otro contrato.',
                $this->contractPeriodLabel($conflict)
            ),
        ]);
    }

    private function legacyContractOptionalColumns(array $payload, bool $isHistorical, bool $missingFile, Usuario $user): array
    {
        return [
            'origen_registro' => in_array(strtoupper((string) ($payload['origen_registro'] ?? 'ANTIGUO')), ['ANTIGUO', 'HISTORICO', 'IMPORTADO'], true)
                ? strtoupper((string) ($payload['origen_registro'] ?? 'ANTIGUO'))
                : 'ANTIGUO',
            'es_historico' => $isHistorical,
            'archivo_pendiente_regularizacion' => $missingFile,
            'observacion_historica' => PersonalNormalizer::text($payload['observacion_historica'] ?? '') ?: null,
            'tipo_contrato' => PersonalNormalizer::contract($payload['tipo_contrato'] ?? $payload['contrato'] ?? null),
            'puesto' => PersonalNormalizer::text($payload['puesto'] ?? '') ?: null,
            'area' => PersonalNormalizer::text($payload['area'] ?? '') ?: null,
            'mina' => PersonalNormalizer::text($payload['mina'] ?? '') ?: null,
            'remuneracion' => PersonalNormalizer::text($payload['remuneracion'] ?? '') ?: null,
            'costo_hora' => PersonalNormalizer::text($payload['costo_hora'] ?? '') ?: null,
            'es_supervisor' => filter_var($payload['es_supervisor'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'registrado_by_usuario_id' => $user->id,
        ];
    }

    private function findEquivalentContract(Personal $personal, string $fechaInicio, ?string $fechaFin, bool $isCurrent): ?PersonalContrato
    {
        $query = PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->whereDate('fecha_inicio', $fechaInicio);

        if ($fechaFin) {
            $query->whereDate('fecha_fin', $fechaFin);
        } else {
            $query->whereNull('fecha_fin');
        }

        if ($isCurrent) {
            $query->whereIn('estado', [PersonalContrato::ESTADO_PREPARACION, PersonalContrato::ESTADO_ACTIVO]);
        } else {
            $query->whereIn('estado', [PersonalContrato::ESTADO_CERRADO, PersonalContrato::ESTADO_CESADO, PersonalContrato::ESTADO_NO_RENOVADO]);
        }

        return $query->latest('contrato_numero')->first();
    }

    /**
     * @return array{path:string,original_name:string,mime:?string,size:int|false,signed_at:mixed}|null
     */
    private function legacySignedFileFromContractData(?PersonalContratoDato $datos): ?array
    {
        if (!$datos?->signed_at || trim((string) ($datos->signed_contract_path ?? '')) === '') {
            return null;
        }

        return [
            'path' => (string) $datos->signed_contract_path,
            'original_name' => (string) ($datos->signed_contract_original_name ?: 'contrato_firmado.pdf'),
            'mime' => $datos->signed_contract_mime,
            'size' => $datos->signed_contract_size ?: 0,
            'signed_at' => $datos->signed_at,
        ];
    }

    /**
     * @return array{path:string,original_name:string,mime:?string,size:int,signed_at:mixed}|null
     */
    private function signedFileFromContract(PersonalContrato $contract): ?array
    {
        if (!$contract->hasSignedFile()) {
            return null;
        }

        return [
            'path' => (string) $contract->signed_contract_path,
            'original_name' => (string) ($contract->signed_contract_original_name ?: 'contrato_firmado.pdf'),
            'mime' => $contract->signed_contract_mime,
            'size' => (int) ($contract->signed_contract_size ?: 0),
            'signed_at' => $contract->signed_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contractAuditPayload(PersonalContrato $contract): array
    {
        return [
            'id' => (string) $contract->id,
            'contrato_numero' => (int) $contract->contrato_numero,
            'estado' => strtoupper((string) $contract->estado),
            'fecha_inicio' => optional($contract->fecha_inicio)->toDateString(),
            'fecha_fin' => optional($contract->fecha_fin)->toDateString(),
            'tipo_contrato' => $contract->tipo_contrato,
            'puesto' => $contract->puesto,
            'area' => $contract->area,
            'mina' => $contract->mina,
            'remuneracion' => $contract->remuneracion,
            'costo_hora' => $contract->costo_hora,
            'motivo_cese' => $contract->motivo_cese,
            'signed_at' => optional($contract->signed_at)->toDateTimeString(),
            'signed_contract_original_name' => $contract->signed_contract_original_name,
            'anulado_at' => optional($contract->anulado_at)->toDateTimeString(),
            'motivo_anulacion' => $contract->motivo_anulacion,
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function recordContractAudit(
        PersonalContrato $contract,
        string $action,
        string $reason,
        array $before,
        array $after,
        Usuario $user
    ): void {
        if (!Schema::hasTable('personal_contrato_correcciones')) {
            return;
        }

        DB::table('personal_contrato_correcciones')->insert([
            'id' => (string) Str::uuid(),
            'personal_contrato_id' => $contract->id,
            'personal_id' => $contract->personal_id,
            'usuario_id' => $user->id,
            'accion' => strtoupper($action),
            'motivo' => mb_substr($reason, 0, 2000),
            'datos_anteriores_json' => json_encode($before, JSON_UNESCAPED_UNICODE),
            'datos_nuevos_json' => json_encode($after, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    private function syncEditableContractDataAfterCorrection(Personal $personal, PersonalContrato $contract, Usuario $user): void
    {
        $state = strtoupper((string) $contract->estado);
        if (!in_array($state, [PersonalContrato::ESTADO_PREPARACION, PersonalContrato::ESTADO_ACTIVO], true)) {
            return;
        }

        $contractDataService = app(PersonalContratoDatoService::class);
        $datos = $contractDataService->ensureForPersonal($personal, [
            'fecha_inicio_contrato' => optional($contract->fecha_inicio)->toDateString(),
            'fecha_fin_contrato' => optional($contract->fecha_fin)->toDateString(),
            'puesto' => $contract->puesto ?: $personal->puesto,
            'sueldo_num' => $contract->remuneracion,
            'sueldo_hora_paradas' => $contract->costo_hora,
        ], $user);

        $datos->forceFill([
            'fecha_inicio_contrato' => optional($contract->fecha_inicio)->toDateString(),
            'fecha_fin_contrato' => optional($contract->fecha_fin)->toDateString(),
            'puesto' => $contract->puesto ?: $datos->puesto,
            'sueldo_num' => $contract->remuneracion ?: $datos->sueldo_num,
            'sueldo_hora_paradas' => $contract->costo_hora ?: $datos->sueldo_hora_paradas,
            'updated_by_usuario_id' => $user->id,
        ])->save();

        if ($state === PersonalContrato::ESTADO_ACTIVO) {
            $personalData = [];
            if (Schema::hasColumn('personal', 'fecha_ingreso')) {
                $personalData['fecha_ingreso'] = optional($contract->fecha_inicio)->toDateString();
            }
            if ($contract->puesto) {
                $personalData['puesto'] = $contract->puesto;
            }
            if ($contract->tipo_contrato) {
                $personalData['contrato'] = $contract->tipo_contrato;
            }
            if ($personalData !== []) {
                $personal->forceFill($personalData)->save();
            }
        }
    }

    private function refreshCorrectedContractSnapshot(Personal $personal, PersonalContrato $contract, string $reason): PersonalContrato
    {
        $contract = $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']);
        if (!$contract) {
            throw ValidationException::withMessages([
                'contrato' => 'Contrato no encontrado despues de la correccion.',
            ]);
        }

        $state = strtoupper((string) $contract->estado);
        $snapshotColumn = in_array($state, [PersonalContrato::ESTADO_PREPARACION, PersonalContrato::ESTADO_ACTIVO], true)
            ? 'snapshot_inicial_json'
            : 'snapshot_json';

        $snapshot = $this->buildSnapshot($personal, 'correccion_contrato', [
            'contrato_numero' => $contract->contrato_numero,
            'motivo_correccion' => $reason,
            'fecha_fin' => optional($contract->fecha_fin)->toDateString(),
        ], $contract);

        $contract->forceFill([
            $snapshotColumn => $snapshot,
        ])->save();

        return $contract->fresh(['activadoPor.personal', 'cerradoPor.personal', 'firmadoPor.personal', 'anuladoPor.personal']);
    }

    /**
     * @param array<string, mixed> $before
     */
    private function syncPersonalStateAfterContractAnnulment(Personal $personal, PersonalContrato $contract, array $before, Usuario $user): void
    {
        $previousState = strtoupper((string) ($before['estado'] ?? ''));
        if (!in_array($previousState, [PersonalContrato::ESTADO_PREPARACION, PersonalContrato::ESTADO_ACTIVO], true)) {
            return;
        }

        $personal = $this->reconcilePersonalStateFromContracts($personal, $user);
        $workerState = strtoupper((string) $personal->estado);
        if ($workerState === 'CESADO') {
            return;
        }

        if ($this->hasOtherCurrentSignedContract($contract)) {
            if ($workerState !== 'ACTIVO') {
                $personal->forceFill(['estado' => 'ACTIVO'])->save();
            }

            return;
        }

        if ($workerState === 'ACTIVO') {
            $personal->forceFill(['estado' => PersonalContratoDatoService::PENDING_STATE])->save();
        }
    }

    /**
     * @return array{path:string,original_name:string,mime:?string,size:int|false}
     */
    private function storeLegacySignedFile(Personal $personal, UploadedFile $file): array
    {
        $path = $file->storeAs(
            'personal_contratos/' . $personal->id,
            'contrato_antiguo_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.pdf',
            'local',
        );

        return [
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function nextContractNumber(Personal $personal): int
    {
        return ((int) PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->max('contrato_numero')) + 1;
    }

    private function currentContractStartDate(Personal $personal): ?string
    {
        $fichaData = is_array($personal->fichaColaborador?->datos_json ?? null)
            ? $personal->fichaColaborador->datos_json
            : [];

        return PersonalNormalizer::isoDate($fichaData['fecha_ingreso'] ?? null)
            ?: optional($personal->fecha_ingreso)->toDateString()
            ?: optional($personal->created_at)->toDateString();
    }

    private function currentContractEndDate(Personal $personal): ?string
    {
        $fichaData = is_array($personal->fichaColaborador?->datos_json ?? null)
            ? $personal->fichaColaborador->datos_json
            : [];

        return PersonalNormalizer::isoDate($fichaData['fecha_fin_contrato'] ?? null);
    }

    private function modelAttributes($model): array
    {
        if (!$model) {
            return [];
        }

        return collect($model->getAttributes())
            ->map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value)
            ->all();
    }

    private function fichaSnapshot(Personal $personal): array
    {
        $ficha = $personal->fichaColaborador;
        if (!$ficha) {
            return [];
        }

        return [
            'registro' => $this->modelAttributes($ficha),
            'datos' => $ficha->datos_json ?? [],
            'familiares' => $ficha->familiares->map(fn ($item) => $this->modelAttributes($item))->values()->all(),
        ];
    }

    private function documentSnapshot(Personal $personal): array
    {
        $ficha = $personal->fichaColaborador;
        if (!$ficha) {
            return [];
        }

        return $ficha->archivos
            ->map(fn ($archivo) => [
                'id' => (string) $archivo->id,
                'tipo' => (string) $archivo->tipo,
                'nombre_original' => (string) ($archivo->nombre_original ?? ''),
                'path' => (string) ($archivo->path ?? ''),
                'mime' => (string) ($archivo->mime ?? ''),
                'size' => (int) ($archivo->size ?? 0),
                'uploaded_by_usuario_id' => $archivo->uploaded_by_usuario_id,
                'uploaded_by_public' => (bool) $archivo->uploaded_by_public,
                'created_at' => optional($archivo->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function contractDataSnapshot(Personal $personal): array
    {
        $datos = $personal->contratoDatos;
        if (!$datos) {
            return [];
        }

        return $this->modelAttributes($datos);
    }

    private function userSnapshot(Personal $personal): array
    {
        $usuario = $personal->usuario;
        if (!$usuario) {
            return [
                'tiene_usuario' => false,
            ];
        }

        return [
            'tiene_usuario' => true,
            'usuario' => [
                'id' => (string) $usuario->id,
                'email' => (string) $usuario->email,
                'estado' => (string) ($usuario->estado ?? ''),
                'rol' => $usuario->rol ? [
                    'id' => (string) $usuario->rol->id,
                    'nombre' => (string) $usuario->rol->nombre,
                ] : null,
                'roles_adicionales' => $usuario->rolesAdicionales
                    ->map(fn ($rol) => [
                        'id' => (string) $rol->id,
                        'nombre' => (string) $rol->nombre,
                        'tipo' => (string) ($rol->pivot->tipo ?? ''),
                    ])
                    ->values()
                    ->all(),
                'scopes_mina' => $usuario->scopesMina
                    ->map(fn ($scope) => [
                        'mina_id' => (string) $scope->mina_id,
                        'mina' => (string) ($scope->mina?->nombre ?? ''),
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    private function mineSnapshot(Personal $personal): array
    {
        return $personal->minas
            ->map(fn ($mina) => [
                'id' => (string) $mina->id,
                'nombre' => (string) $mina->nombre,
                'unidad_minera' => (string) ($mina->unidad_minera ?? ''),
                'estado_relacion' => (string) ($mina->pivot->estado ?? ''),
            ])
            ->values()
            ->all();
    }

    private function bloqueoSnapshot(Personal $personal, ?string $start, ?string $end): array
    {
        return $personal->bloqueos
            ->filter(fn ($bloqueo) => $this->dateInRange(optional($bloqueo->fecha_inicio)->toDateString(), optional($bloqueo->fecha_fin)->toDateString(), $start, $end))
            ->map(fn ($bloqueo) => $this->modelAttributes($bloqueo))
            ->values()
            ->all();
    }

    private function assignmentSnapshot(Personal $personal, ?string $start, ?string $end): array
    {
        return [
            'grupos_trabajo' => $this->groupRows($personal->id, $start, $end),
            'rq_proserge' => $this->rqProsergeRows($personal->id, $start, $end),
        ];
    }

    private function groupRows(string $personalId, ?string $start, ?string $end): array
    {
        if (!Schema::hasTable('grupo_trabajo_detalle') || !Schema::hasTable('grupo_trabajo')) {
            return [];
        }

        $query = DB::table('grupo_trabajo_detalle as gtd')
            ->join('grupo_trabajo as gt', 'gt.id', '=', 'gtd.grupo_trabajo_id')
            ->leftJoin('rq_mina as rm', 'rm.id', '=', 'gt.rq_mina_id')
            ->leftJoin('rq_proserge as rp', 'rp.id', '=', 'gt.rq_proserge_id')
            ->where('gtd.personal_id', $personalId)
            ->select([
                'gtd.*',
                'gt.fecha',
                'gt.mina',
                'gt.servicio',
                'gt.area',
                'gt.turno',
                'gt.estado as grupo_estado',
                'rm.id as rq_mina_id',
                'rm.area as rq_mina_area',
                'rm.fecha_inicio as rq_mina_inicio',
                'rm.fecha_fin as rq_mina_fin',
                'rm.estado as rq_mina_estado',
                'rp.id as rq_proserge_relacionado_id',
                'rp.estado as rq_proserge_estado',
            ]);

        $this->applyDateRange($query, 'gt.fecha', $start, $end);

        return $query->orderByDesc('gt.fecha')->limit(200)->get()->map(fn ($row) => (array) $row)->all();
    }

    private function rqProsergeRows(string $personalId, ?string $start, ?string $end): array
    {
        if (!Schema::hasTable('rq_proserge_detalle')) {
            return [];
        }

        $query = DB::table('rq_proserge_detalle as rpd')
            ->leftJoin('rq_proserge as rp', 'rp.id', '=', 'rpd.rq_proserge_id')
            ->where('rpd.personal_id', $personalId)
            ->select([
                'rpd.*',
                'rp.rq_mina_id',
                'rp.mina_id',
                'rp.estado as rq_proserge_estado',
            ]);

        $this->applyOverlappingRange($query, 'rpd.fecha_inicio', 'rpd.fecha_fin', $start, $end);

        return $query->orderByDesc('rpd.fecha_inicio')->limit(200)->get()->map(fn ($row) => (array) $row)->all();
    }

    private function attendanceSnapshot(Personal $personal, ?string $start, ?string $end): array
    {
        if (!Schema::hasTable('asistencia_detalle')) {
            return [];
        }

        $query = DB::table('asistencia_detalle as ad')
            ->leftJoin('asistencia_encabezado as ae', 'ae.id', '=', 'ad.asistencia_id')
            ->leftJoin('grupo_trabajo as gt', 'gt.id', '=', 'ae.grupo_trabajo_id')
            ->where('ad.trabajador_id', $personal->id)
            ->select([
                'ad.*',
                'ae.fecha as asistencia_fecha',
                'ae.estado as asistencia_estado',
                'gt.mina',
                'gt.servicio',
                'gt.turno',
            ]);

        if (Schema::hasColumn('asistencia_encabezado', 'fecha')) {
            $this->applyDateRange($query, 'ae.fecha', $start, $end);
        }

        return $query->orderByDesc('ae.fecha')->limit(200)->get()->map(fn ($row) => (array) $row)->all();
    }

    private function genericTableSnapshot(string $table, string $column, string $personalId, ?string $dateColumn, ?string $start, ?string $end): array
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return [];
        }

        $query = DB::table($table)->where($column, $personalId);

        if ($dateColumn && Schema::hasColumn($table, $dateColumn)) {
            $this->applyDateRange($query, $dateColumn, $start, $end);
            $query->orderByDesc($dateColumn);
        }

        return $query->limit(200)->get()->map(fn ($row) => (array) $row)->all();
    }

    private function applyDateRange(Builder $query, string $column, ?string $start, ?string $end): void
    {
        if ($start) {
            $query->whereDate($column, '>=', $start);
        }

        if ($end) {
            $query->whereDate($column, '<=', $end);
        }
    }

    private function applyOverlappingRange(Builder $query, string $startColumn, string $endColumn, ?string $start, ?string $end): void
    {
        if ($start) {
            $query->where(function (Builder $range) use ($endColumn, $start): void {
                $range->whereNull($endColumn)
                    ->orWhereDate($endColumn, '>=', $start);
            });
        }

        if ($end) {
            $query->whereDate($startColumn, '<=', $end);
        }
    }

    private function dateInRange(?string $itemStart, ?string $itemEnd, ?string $start, ?string $end): bool
    {
        if (!$itemStart && !$itemEnd) {
            return true;
        }

        $rangeStart = $start ?: '0001-01-01';
        $rangeEnd = $end ?: '9999-12-31';
        $itemStart = $itemStart ?: $itemEnd;
        $itemEnd = $itemEnd ?: $itemStart;

        return $itemEnd >= $rangeStart && $itemStart <= $rangeEnd;
    }
}
