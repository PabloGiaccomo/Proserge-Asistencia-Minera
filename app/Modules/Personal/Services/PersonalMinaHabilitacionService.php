<?php

namespace App\Modules\Personal\Services;

use App\Models\ExamenMinero;
use App\Models\ExamenMineroPrecio;
use App\Models\Mina;
use App\Models\MinaRequisito;
use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalCursoRegistro;
use App\Models\PersonalEmoCita;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Models\PersonalMina;
use App\Models\PersonalMinaHistorial;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Modules\Personal\Support\PersonalNormalizer;
use App\Modules\Personal\Support\CompleteEmoData;
use App\Modules\Personal\Support\EmoOrigin;
use App\Modules\Personal\Support\EmoOutcome;
use App\Modules\Personal\Support\ScheduleEmoData;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalMinaHabilitacionService
{
    public function __construct(private readonly OperationalNotificationService $operationalNotifications)
    {
    }

    private function operationalToday(): Carbon
    {
        return Carbon::today(config('app.display_timezone', 'America/Lima'));
    }

    public function listAssignments(array $filters)
    {
        $perPage = $this->perPageFromFilters($filters);
        if (!Schema::hasTable('personal_mina')) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        $query = PersonalMina::query()
            ->with([
                'personal.contratoLaboralActual',
                'mina',
                'actualizadoPor.personal',
                'historial.usuario.personal',
                'examenes.intentos.emoCita',
                'examenes.requisitoMina',
                'examenes.examen',
            ])
            ->whereHas('mina', fn ($mineQuery) => $mineQuery->activeOperational())
            ->where('activo', true)
            ->orderByDesc('updated_at');

        $minaId = trim((string) ($filters['mina_id'] ?? ''));
        if ($minaId !== '') {
            $query->where('mina_id', $minaId);
        }

        $worker = trim((string) ($filters['trabajador'] ?? ''));
        if ($worker !== '') {
            $needle = '%' . mb_strtolower($worker) . '%';
            $query->whereHas('personal', function ($personalQuery) use ($needle): void {
                $personalQuery->whereRaw('LOWER(nombre_completo) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(numero_documento) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(dni) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(puesto) LIKE ?', [$needle]);
            });
        }

        $estado = strtoupper(trim((string) ($filters['estado_habilitacion'] ?? '')));
        if ($estado !== '' && array_key_exists($estado, $this->habilitationStateOptions())) {
            $query->where(function ($stateQuery) use ($estado): void {
                $stateQuery->where('estado_habilitacion', $estado)
                    ->orWhere(function ($legacyQuery) use ($estado): void {
                        $legacyQuery->whereNull('estado_habilitacion')
                            ->where('estado', $estado);
                    });
            });
        }

        $estadoLaboral = strtoupper(trim((string) ($filters['estado_laboral'] ?? '')));
        if (in_array($estadoLaboral, ['ACTIVO', 'FALTA_CONTRATO', 'CESADO', 'INACTIVO', 'PENDIENTE_COMPLETAR_FICHA', 'FICHA_ENVIADA', 'OBSERVADO'], true)) {
            $query->whereHas('personal', fn ($personalQuery) => $personalQuery->where('estado', $estadoLaboral));
        }

        $estadoExamen = strtoupper(trim((string) ($filters['estado_examen'] ?? '')));
        if ($estadoExamen !== '' && array_key_exists($estadoExamen, $this->examStateOptions())) {
            $query->whereHas('examenes', fn ($examQuery) => $examQuery->where('estado', $estadoExamen));
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function listGroupedByWorker(array $filters): LengthAwarePaginator
    {
        $perPage = $this->perPageFromFilters($filters);
        if (!Schema::hasTable('personal_mina')) {
            return new LengthAwarePaginator([], 0, $perPage, 1);
        }

        $baseQuery = PersonalMina::query()->where('activo', true)
            ->whereHas('mina', fn ($mineQuery) => $mineQuery->activeOperational());
        $minaId = trim((string) ($filters['mina_id'] ?? ''));
        if ($minaId !== '') {
            $baseQuery->where('mina_id', $minaId);
        }
        $worker = trim((string) ($filters['trabajador'] ?? ''));
        if ($worker !== '') {
            $needle = '%' . mb_strtolower($worker) . '%';
            $baseQuery->whereHas('personal', function ($q) use ($needle): void {
                $q->whereRaw('LOWER(nombre_completo) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(numero_documento) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(dni) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(puesto) LIKE ?', [$needle]);
            });
        }
        $estado = strtoupper(trim((string) ($filters['estado_habilitacion'] ?? '')));
        if ($estado !== '' && array_key_exists($estado, $this->habilitationStateOptions())) {
            $baseQuery->where(function ($q) use ($estado): void {
                $q->where('estado_habilitacion', $estado)
                    ->orWhere(function ($lq): void {
                        $lq->whereNull('estado_habilitacion')->where('estado', $estado);
                    });
            });
        }
        $estadoLaboral = strtoupper(trim((string) ($filters['estado_laboral'] ?? '')));
        if (in_array($estadoLaboral, ['ACTIVO', 'FALTA_CONTRATO', 'CESADO', 'INACTIVO', 'PENDIENTE_COMPLETAR_FICHA', 'FICHA_ENVIADA', 'OBSERVADO'], true)) {
            $baseQuery->whereHas('personal', fn ($q) => $q->where('estado', $estadoLaboral));
        }
        $estadoExamen = strtoupper(trim((string) ($filters['estado_examen'] ?? '')));
        if ($estadoExamen !== '' && array_key_exists($estadoExamen, $this->examStateOptions())) {
            $baseQuery->whereHas('examenes', fn ($q) => $q->where('estado', $estadoExamen));
        }

        try {
            $total = (clone $baseQuery)->selectRaw('COUNT(DISTINCT personal_id) as cnt')->value('cnt') ?? 0;
        } catch (\Throwable) {
            $total = 0;
        }

        $page = LengthAwarePaginator::resolveCurrentPage();
        $personalIds = (clone $baseQuery)
            ->select('personal_id')
            ->distinct()
            ->orderBy('personal_id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->pluck('personal_id')
            ->all();

        $grouped = collect();
        if (!empty($personalIds)) {
            $detailQuery = PersonalMina::query()
                ->with([
                    'personal.contratoLaboralActual',
                    'mina',
                    'actualizadoPor.personal',
                    'historial.usuario.personal',
                    'examenes.intentos.emoCita',
                    'examenes.requisitoMina',
                    'examenes.examen',
                ])
                ->whereHas('mina', fn ($mineQuery) => $mineQuery->activeOperational())
                ->where('activo', true)
                ->whereIn('personal_id', $personalIds);

            if ($minaId !== '') {
                $detailQuery->where('mina_id', $minaId);
            }
            if ($estado !== '' && array_key_exists($estado, $this->habilitationStateOptions())) {
                $detailQuery->where(function ($q) use ($estado): void {
                    $q->where('estado_habilitacion', $estado)
                        ->orWhere(function ($lq): void {
                            $lq->whereNull('estado_habilitacion')->where('estado', $estado);
                        });
                });
            }
            if ($estadoExamen !== '' && array_key_exists($estadoExamen, $this->examStateOptions())) {
                $detailQuery->whereHas('examenes', fn ($q) => $q->where('estado', $estadoExamen));
            }

            $grouped = $detailQuery
                ->orderBy('personal_id')
                ->orderByDesc('updated_at')
                ->get()
                ->groupBy('personal_id');
        }

        $paginator = new LengthAwarePaginator($grouped, $total, $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
        $paginator->appends(request()->query());

        return $paginator;
    }

    public function perPageFromFilters(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $allowed = [10, 15, 25, 50, 100];

        return in_array($perPage, $allowed, true) ? $perPage : 15;
    }

    public function listRequirements(?string $minaId = null)
    {
        if (!Schema::hasTable('mina_requisitos')) {
            return collect();
        }

        return MinaRequisito::query()
            ->with(['mina', 'examen'])
            ->whereHas('mina', fn ($mineQuery) => $mineQuery->activeOperational())
            ->when($minaId, fn ($query) => $query->where('mina_id', $minaId))
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();
    }

    public function listMiningExams()
    {
        if (!Schema::hasTable('examenes_mineros')) {
            return collect();
        }

        return ExamenMinero::query()
            ->with('precios')
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();
    }

    public function listAllMiningExams()
    {
        if (!Schema::hasTable('examenes_mineros')) {
            return collect();
        }

        return ExamenMinero::query()
            ->with('precios')
            ->orderBy('activo')
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();
    }

    public function upcomingExamExpirations(int $days = 60)
    {
        if (!Schema::hasTable('personal_mina_examenes') || !Schema::hasTable('personal_mina')) {
            return collect();
        }

        $today = $this->operationalToday();
        $until = $today->copy()->addDays(max(1, $days));
        $activeStates = [
            PersonalMinaExamen::ESTADO_APROBADO,
            PersonalMinaExamen::ESTADO_VIGENTE,
            PersonalMinaExamen::ESTADO_POR_VENCER,
            PersonalMinaExamen::ESTADO_CONVALIDADO,
        ];

        $exams = PersonalMinaExamen::query()
            ->with([
                'asignacion.personal.contratoLaboralActual',
                'asignacion.mina',
                'asignacion.examenes.intentos',
                'asignacion.examenes.requisitoMina',
                'asignacion.examenes.examen',
                'intentos',
            ])
            ->whereIn('estado', $activeStates)
            ->whereNotNull('fecha_vencimiento')
            ->whereBetween('fecha_vencimiento', [$today->toDateString(), $until->toDateString()])
            ->whereHas('asignacion', fn ($query) => $query->where('activo', true)
                ->whereHas('mina', fn ($mineQuery) => $mineQuery->activeOperational()))
            ->orderBy('fecha_vencimiento')
            ->orderBy('nombre_snapshot')
            ->limit(300)
            ->get();

        return $exams
            ->filter(fn (PersonalMinaExamen $exam) => $exam->asignacion?->personal && $exam->asignacion?->mina)
            ->groupBy(function (PersonalMinaExamen $exam): string {
                return implode('|', [
                    (string) $exam->asignacion->personal_id,
                    mb_strtolower(trim((string) $exam->nombre_snapshot)),
                    $exam->fecha_vencimiento ? Carbon::parse($exam->fecha_vencimiento)->toDateString() : '',
                ]);
            })
            ->map(function ($group) use ($today): array {
                /** @var PersonalMinaExamen $first */
                $first = $group->first();
                $expiresAt = $first->fecha_vencimiento
                    ? Carbon::parse($first->fecha_vencimiento->toDateString(), $today->getTimezone()) : null;

                return [
                    'worker' => $first->asignacion->personal,
                    'exam_name' => $first->nombre_snapshot ?: 'Examen',
                    'fecha_vencimiento' => $expiresAt,
                    'dias_restantes' => $expiresAt ? (int) $today->diffInDays($expiresAt, false) : null,
                    'estado' => $first->estado,
                    'mines' => $group
                        ->map(fn (PersonalMinaExamen $exam): array => [
                            'assignment' => $exam->asignacion,
                            'mine' => $exam->asignacion->mina,
                            'exam' => $exam,
                        ])
                        ->unique(fn (array $row): string => (string) $row['assignment']->id)
                        ->sortBy(fn (array $row): string => (string) ($row['mine']->nombre ?? ''))
                        ->values(),
                ];
            })
            ->sortBy(fn (array $row): string => sprintf(
                '%s|%s|%s',
                $row['fecha_vencimiento'] ? $row['fecha_vencimiento']->toDateString() : '9999-12-31',
                $row['worker']->nombre_completo ?? '',
                $row['exam_name']
            ))
            ->values();
    }

    public function scheduledExamAttempts(int $days = 60)
    {
        if (
            !Schema::hasTable('personal_mina_examen_intentos')
            || !Schema::hasTable('personal_mina_examenes')
            || !Schema::hasTable('personal_mina')
        ) {
            return collect();
        }

        $today = $this->operationalToday();
        $from = $today->copy()->subDays(30);
        $until = $today->copy()->addDays(max(1, $days));

        $attempts = PersonalMinaExamenIntento::query()
            ->with([
                'examenTrabajador.asignacion.personal.contratoLaboralActual',
                'examenTrabajador.asignacion.mina',
                'examenTrabajador.asignacion.examenes.intentos',
                'examenTrabajador.asignacion.examenes.requisitoMina',
                'examenTrabajador.asignacion.examenes.examen',
                'examenTrabajador.intentos',
                'examenTrabajador.requisitoMina',
            ])
            ->where('resultado', PersonalMinaExamenIntento::RESULTADO_PENDIENTE)
            ->whereNotNull('fecha_programacion')
            ->whereBetween('fecha_programacion', [$from->toDateString(), $until->toDateString()])
            ->whereHas('examenTrabajador.asignacion', fn ($query) => $query->where('activo', true)
                ->whereHas('mina', fn ($mineQuery) => $mineQuery->activeOperational()))
            ->orderBy('fecha_programacion')
            ->limit(500)
            ->get();

        return $attempts
            ->filter(function (PersonalMinaExamenIntento $attempt): bool {
                $exam = $attempt->examenTrabajador;

                return (bool) ($exam?->asignacion?->personal && $exam?->asignacion?->mina);
            })
            ->groupBy(function (PersonalMinaExamenIntento $attempt): string {
                $exam = $attempt->examenTrabajador;

                return implode('|', [
                    (string) $exam->asignacion->personal_id,
                    mb_strtolower(trim((string) $exam->nombre_snapshot)),
                    $attempt->fecha_programacion ? Carbon::parse($attempt->fecha_programacion)->toDateString() : '',
                ]);
            })
            ->map(function ($group) use ($today): array {
                /** @var PersonalMinaExamenIntento $firstAttempt */
                $firstAttempt = $group->first();
                $firstExam = $firstAttempt->examenTrabajador;
                $scheduledAt = $firstAttempt->fecha_programacion
                    ? Carbon::parse($firstAttempt->fecha_programacion->toDateString(), $today->getTimezone()) : null;

                return [
                    'worker' => $firstExam->asignacion->personal,
                    'exam_name' => $firstExam->nombre_snapshot ?: 'Examen',
                    'fecha_programacion' => $scheduledAt,
                    'dias_para_programacion' => $scheduledAt ? (int) $today->diffInDays($scheduledAt, false) : null,
                    'estado' => $firstExam->estado,
                    'mines' => $group
                        ->map(function (PersonalMinaExamenIntento $attempt): array {
                            $exam = $attempt->examenTrabajador;

                            return [
                                'assignment' => $exam->asignacion,
                                'mine' => $exam->asignacion->mina,
                                'exam' => $exam,
                                'attempt' => $attempt,
                                'numero_intento' => $attempt->numero_intento,
                            ];
                        })
                        ->unique(fn (array $row): string => implode('|', [
                            (string) $row['assignment']->id,
                            (string) $row['exam']->id,
                            (string) $row['attempt']->id,
                        ]))
                        ->sortBy(fn (array $row): string => (string) ($row['mine']->nombre ?? ''))
                        ->values(),
                ];
            })
            ->sortBy(fn (array $row): string => sprintf(
                '%s|%s|%s',
                $row['fecha_programacion'] ? $row['fecha_programacion']->toDateString() : '9999-12-31',
                $row['worker']->nombre_completo ?? '',
                $row['exam_name']
            ))
            ->values();
    }

    public function listPriceHistory(?string $examId = null)
    {
        if (!Schema::hasTable('examen_minero_precios')) {
            return collect();
        }

        return ExamenMineroPrecio::query()
            ->with('examen')
            ->when($examId, fn ($query) => $query->where('examen_id', $examId))
            ->orderByDesc('fecha_inicio')
            ->limit(120)
            ->get();
    }

    public function activeMines()
    {
        return Mina::query()
            ->activeOperational()
            ->orderBy('nombre')
            ->get();
    }

    public function workerOptions(?string $search = null, int $limit = 80, int $page = 1): LengthAwarePaginator
    {
        $allowedLimits = [10, 20, 50, 80, 200];
        $limit = in_array($limit, $allowedLimits, true) ? $limit : 20;
        $page = max(1, $page);

        $query = Personal::query()
            ->withCount(['relacionesMina as minas_activas_count' => fn ($q) => $q->where('activo', true)])
            ->orderBy('nombre_completo');
        $search = trim((string) $search);

        if ($search !== '') {
            $this->applyWorkerSearch($query, $search);
        }

        return $query
            ->paginate($limit, ['id', 'nombre_completo', 'dni', 'numero_documento', 'puesto', 'estado'], 'worker_page', $page)
            ->withQueryString();
    }

    public function workerOptionsTotal(?string $search = null): int
    {
        $query = Personal::query();
        $search = trim((string) $search);

        if ($search !== '') {
            $this->applyWorkerSearch($query, $search);
        }

        return $query->count();
    }

    private function applyWorkerSearch($query, string $search): void
    {
        $search = Str::squish($search);
        $contains = '%'.$search.'%';
        $startsWith = $search.'%';

        $query->where(function ($personalQuery) use ($search, $contains, $startsWith): void {
            if (preg_match('/^\d+$/', $search)) {
                $personalQuery->where('dni', 'like', $startsWith)
                    ->orWhere('numero_documento', 'like', $startsWith);

                return;
            }

            $personalQuery->where('nombre_completo', 'like', $contains)
                ->orWhere('puesto', 'like', $contains)
                ->orWhere('ocupacion', 'like', $contains);
        });
    }

    public function findWorker(?string $workerId): ?Personal
    {
        $workerId = trim((string) $workerId);
        if ($workerId === '') {
            return null;
        }

        return Personal::query()
            ->with('contratoLaboralActual')
            ->withCount(['relacionesMina as minas_activas_count' => fn ($q) => $q->where('activo', true)])
            ->find($workerId);
    }

    public function storeRequirementDeferred(array $payload, Usuario $user): MinaRequisito
    {
        app(MineRequirementSyncService::class)->ensureAvailable();

        return DB::transaction(function () use ($payload, $user): MinaRequisito {
            Mina::query()->whereKey($payload['mina_id'] ?? null)->lockForUpdate()->first();
            $requirement = $this->storeRequirement($payload);
            $requirement->forceFill([
                'convalidacion_configurada_por_id' => $user->id,
                'convalidacion_configurada_at' => now(),
            ])->save();
            app(MineRequirementSyncService::class)->request($requirement->mina_id, $user);

            return $requirement;
        });
    }

    public function storeRequirement(array $payload, ?Usuario $user = null): MinaRequisito
    {
        $mina = Mina::query()->activeOperational()->find($payload['mina_id'] ?? null);
        if (!$mina) {
            throw ValidationException::withMessages(['mina_id' => 'Selecciona una mina valida.']);
        }

        $exam = $this->resolveExamForRequirement($payload, null);
        $name = mb_substr(PersonalNormalizer::text($payload['nombre'] ?? $exam->nombre ?? ''), 0, 191);
        if ($name === '') {
            throw ValidationException::withMessages(['nombre' => 'El nombre del examen es obligatorio.']);
        }

        $duplicate = MinaRequisito::query()
            ->where('mina_id', $mina->id)
            ->where('activo', true)
            ->where(function ($query) use ($name, $exam): void {
                $query->where('examen_id', $exam->id)
                    ->orWhereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower(trim($name))]);
            })
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe un requisito activo con ese nombre para esta mina.']);
        }

        $requirement = MinaRequisito::query()->create([
            'id' => (string) Str::uuid(),
            'mina_id' => $mina->id,
            'examen_id' => $exam->id,
            'nombre' => $name,
            'tipo' => mb_substr(PersonalNormalizer::text($payload['tipo'] ?? $exam->tipo ?? ''), 0, 80) ?: null,
            'descripcion' => PersonalNormalizer::text($payload['descripcion'] ?? $exam->descripcion ?? '') ?: null,
            'obligatorio' => filter_var($payload['obligatorio'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'critico' => filter_var($payload['critico'] ?? $exam->critico ?? false, FILTER_VALIDATE_BOOLEAN),
            'reprogramable' => filter_var($payload['reprogramable'] ?? $exam->permite_reintento ?? true, FILTER_VALIDATE_BOOLEAN),
            'vigencia_dias' => $this->positiveIntegerOrNull($payload['vigencia_dias'] ?? $exam->vigencia_dias ?? null),
            'activo' => true,
            'orden' => $this->positiveIntegerOrNull($payload['orden'] ?? null) ?? 0,
            'permite_no_aplica' => filter_var($payload['permite_no_aplica'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'permite_convalidacion_mina' => filter_var($payload['permite_convalidacion_mina'] ?? $exam->permite_convalidacion ?? false, FILTER_VALIDATE_BOOLEAN),
            'examenes_equivalentes_json' => $this->normalizeEquivalentExams($payload['examenes_equivalentes'] ?? [], $exam->id),
            'convalidacion_configurada_por_id' => $user?->id,
            'convalidacion_configurada_at' => $user ? now() : null,
            'fecha_inicio_convalidacion' => PersonalNormalizer::isoDate($payload['fecha_inicio_convalidacion'] ?? null),
            'fecha_fin_convalidacion' => PersonalNormalizer::isoDate($payload['fecha_fin_convalidacion'] ?? null),
            'convalidar_desde_otras_minas' => filter_var($payload['convalidar_desde_otras_minas'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'minas_origen_convalidacion_json' => $this->normalizeMineOriginList($payload['minas_origen_convalidacion'] ?? []),
            'vigencia_dias_override' => $this->positiveIntegerOrNull($payload['vigencia_dias_override'] ?? null),
            'observacion_mina' => PersonalNormalizer::text($payload['observacion_mina'] ?? '') ?: null,
        ]);

        if ($user) {
            $this->syncMineAssignmentsForRequirementChange($requirement->mina_id, $user);
        }

        return $requirement;
    }

    private function normalizeEquivalentExams(array $ids, string $targetExamId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (in_array($targetExamId, $ids, true)
            || ExamenMinero::query()->where('activo', true)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['examenes_equivalentes' => 'Selecciona examenes activos diferentes del examen destino.']);
        }

        return $ids;
    }

    public function configureConvalidation(MinaRequisito $requirement, array $payload, Usuario $user): void
    {
        DB::transaction(function () use ($requirement, $payload, $user): void {
            $requirement = MinaRequisito::query()->lockForUpdate()->findOrFail($requirement->id);
            if (!$requirement->activo) {
                throw ValidationException::withMessages(['requisito' => 'El requisito ya no esta activo.']);
            }
            $requirement->forceFill([
                'permite_convalidacion_mina' => (bool) ($payload['permite_convalidacion_mina'] ?? false),
                'convalidar_desde_otras_minas' => (bool) ($payload['convalidar_desde_otras_minas'] ?? false),
                'examenes_equivalentes_json' => $this->normalizeEquivalentExams($payload['examenes_equivalentes'] ?? [], $requirement->examen_id),
                'convalidacion_configurada_por_id' => $user->id,
                'convalidacion_configurada_at' => now(),
            ])->save();
        });
    }

    public function storeMiningExam(array $payload, Usuario $user): ExamenMinero
    {
        $name = mb_substr(PersonalNormalizer::text($payload['nombre'] ?? ''), 0, 191);
        if ($name !== '') {
            $duplicate = ExamenMinero::query()
                ->where('activo', true)
                ->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower(trim($name))])
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['nombre' => 'Ya existe un examen activo con ese nombre.']);
            }
        }

        return DB::transaction(function () use ($payload, $user): ExamenMinero {
            $exam = $this->resolveExamForRequirement($payload, $user);
            if (($payload['precio'] ?? null) !== null && ($payload['precio'] ?? '') !== '' && filter_var($payload['empresa_paga'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $this->storeExamPrice($exam, [
                    'precio' => $payload['precio'],
                    'moneda' => $payload['moneda'] ?? 'PEN',
                    'fecha_inicio' => $payload['precio_desde'] ?? $this->operationalToday()->toDateString(),
                    'observacion' => $payload['observacion_precio'] ?? 'Precio inicial del examen.',
                ], $user);
            }

            return $exam;
        });
    }

    public function storeExamPrice(ExamenMinero $exam, array $payload, Usuario $user): ExamenMineroPrecio
    {
        if (!Schema::hasTable('examen_minero_precios')) {
            throw ValidationException::withMessages(['precio' => 'La tabla de historial de precios no esta disponible.']);
        }

        $price = $payload['precio'] ?? null;
        if ($price === null || $price === '' || (float) $price < 0) {
            throw ValidationException::withMessages(['precio' => 'El precio del examen no puede ser negativo.']);
        }

        $start = PersonalNormalizer::isoDate($payload['fecha_inicio'] ?? null);
        if (!$start) {
            throw ValidationException::withMessages(['fecha_inicio' => 'La fecha desde la cual aplica el precio es obligatoria.']);
        }

        $end = PersonalNormalizer::isoDate($payload['fecha_fin'] ?? null);
        if ($end && $end < $start) {
            throw ValidationException::withMessages(['fecha_fin' => 'La fecha hasta no puede ser menor a la fecha desde.']);
        }

        return DB::transaction(function () use ($exam, $payload, $user, $price, $start, $end): ExamenMineroPrecio {
            $exam = ExamenMinero::query()->whereKey($exam->id)->lockForUpdate()->firstOrFail();
            ExamenMineroPrecio::query()
                ->where('examen_id', $exam->id)
                ->whereNull('fecha_fin')
                ->where('fecha_inicio', '<', $start)
                ->update(['fecha_fin' => Carbon::parse($start)->subDay()->toDateString()]);

            $history = ExamenMineroPrecio::query()->create([
                'id' => (string) Str::uuid(),
                'examen_id' => $exam->id,
                'precio' => (float) $price,
                'moneda' => mb_substr(strtoupper(PersonalNormalizer::text($payload['moneda'] ?? 'PEN')), 0, 10) ?: 'PEN',
                'fecha_inicio' => $start,
                'fecha_fin' => $end,
                'observacion' => PersonalNormalizer::text($payload['observacion'] ?? '') ?: null,
                'usuario_id' => $user->id,
            ]);

            $exam->forceFill([
                'empresa_paga' => true,
                'precio' => (float) $price,
                'moneda' => $history->moneda,
                'precio_desde' => $start,
                'updated_by_usuario_id' => $user->id,
            ])->save();

            return $history;
        });
    }

    public function updateMiningExam(ExamenMinero $exam, array $payload, Usuario $user): ExamenMinero
    {
        $name = mb_substr(PersonalNormalizer::text($payload['nombre'] ?? ''), 0, 191);
        if ($name === '') {
            throw ValidationException::withMessages(['nombre' => 'El nombre del examen es obligatorio.']);
        }

        $active = filter_var($payload['activo'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($active) {
            $duplicate = ExamenMinero::query()
                ->where('id', '!=', $exam->id)
                ->where('activo', true)
                ->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower(trim($name))])
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['nombre' => 'Ya existe un examen activo con ese nombre.']);
            }
        }

        $payload = array_merge($payload, ['nombre' => $name]);
        $normalized = $this->normalizedExamPayload($payload);

        return DB::transaction(function () use ($exam, $payload, $user, $normalized): ExamenMinero {
            $exam->forceFill([
                'nombre' => $normalized['nombre'],
                'descripcion' => $normalized['descripcion'],
                'tipo' => $normalized['tipo'],
                'requiere_lugar' => $normalized['requiere_lugar'],
                'lugar' => $normalized['lugar'],
                'empresa_paga' => $normalized['empresa_paga'],
                'precio' => $normalized['precio'],
                'moneda' => $normalized['moneda'],
                'precio_desde' => $normalized['precio_desde'],
                'tiene_vigencia' => $normalized['tiene_vigencia'],
                'vigencia_dias' => $normalized['vigencia_dias'],
                'permite_reintento' => $normalized['permite_reintento'],
                'max_intentos' => $normalized['max_intentos'],
                'critico' => $normalized['critico'],
                'desaprueba_finaliza_proceso' => $normalized['desaprueba_finaliza_proceso'],
                'requiere_nota' => $normalized['requiere_nota'],
                'nota_minima' => $normalized['nota_minima'],
                'solo_resultado' => !$normalized['requiere_nota'],
                'permite_convalidacion' => $normalized['permite_convalidacion'],
                'observacion' => $normalized['observacion'],
                'activo' => $normalized['activo'],
                'orden' => $normalized['orden'],
                'updated_by_usuario_id' => $user->id,
            ])->save();

            if ($normalized['empresa_paga'] && $normalized['precio'] !== null) {
                $this->storeExamPrice($exam, [
                    'precio' => $normalized['precio'],
                    'moneda' => $normalized['moneda'],
                    'fecha_inicio' => $normalized['precio_desde'] ?: $this->operationalToday()->toDateString(),
                    'observacion' => $payload['observacion_precio'] ?? 'Actualizacion de precio del examen.',
                ], $user);
            }

            return $exam->fresh('precios');
        });
    }

    public function assignMine(array $payload, Usuario $user, bool $notify = true): PersonalMina
    {
        $personal = Personal::query()->find($payload['personal_id'] ?? null);
        if (!$personal) {
            throw ValidationException::withMessages(['personal_id' => 'Selecciona un trabajador valido.']);
        }

        $mina = Mina::query()->activeOperational()->find($payload['mina_id'] ?? null);
        if (!$mina) {
            throw ValidationException::withMessages(['mina_id' => 'Selecciona una mina valida.']);
        }

        if ($blocked = $this->blockingReasonForMine($personal, $mina)) {
            throw ValidationException::withMessages(['mina_id' => $blocked]);
        }

        $state = $this->normalizeHabilitationState($payload['estado_habilitacion'] ?? PersonalMina::ESTADO_EN_PROCESO);
        $this->assertCeasedWorkerConfirmation($personal, $state, $payload);

        return DB::transaction(function () use ($personal, $mina, $state, $payload, $user, $notify): PersonalMina {
            Personal::query()->whereKey($personal->id)->lockForUpdate()->firstOrFail();
            $existing = PersonalMina::query()
                ->where('personal_id', $personal->id)
                ->where('mina_id', $mina->id)
                ->lockForUpdate()
                ->first();

            if ($existing && (bool) ($existing->activo ?? true)) {
                throw ValidationException::withMessages([
                    'mina_id' => 'El trabajador ya tiene una asignacion activa para esta mina.',
                ]);
            }

            $date = PersonalNormalizer::isoDate($payload['fecha_asignacion'] ?? null) ?: $this->operationalToday()->toDateString();
            $observation = mb_substr(PersonalNormalizer::text($payload['observacion'] ?? ''), 0, 5000) ?: null;

            $relation = $existing ?: new PersonalMina([
                'id' => (string) Str::uuid(),
                'personal_id' => $personal->id,
                'mina_id' => $mina->id,
            ]);
            $previousState = $existing?->estadoHabilitacionActual();

            $relation->forceFill([
                'estado' => $state,
                'estado_habilitacion' => $state,
                'fecha_asignacion' => $date,
                'fecha_inicio_proceso' => PersonalNormalizer::isoDate($payload['fecha_inicio_proceso'] ?? null) ?: $date,
                'fecha_habilitacion' => $state === PersonalMina::ESTADO_HABILITADO
                    ? (PersonalNormalizer::isoDate($payload['fecha_habilitacion'] ?? null) ?: $this->operationalToday()->toDateString())
                    : null,
                'observacion' => $observation,
                'activo' => true,
                'usuario_actualizacion_id' => $user->id,
            ])->save();

            $this->recordHistory($relation, $previousState, $state, $observation, $user);
            $this->generateRequiredExams($relation, $user, $notify, false);
            $this->refreshAssignmentStatus($relation, $user, $notify);

            if ($notify) {
                $this->operationalNotifications->habilitacionAsignacion(
                    $relation->fresh(['personal', 'mina']) ?: $relation,
                    $user,
                    $previousState
                );
            }

            return $relation->fresh(['personal.contratoLaboralActual', 'mina', 'actualizadoPor.personal', 'historial.usuario.personal', 'examenes.intentos']);
        });
    }

    public function updateAssignment(PersonalMina $relation, array $payload, Usuario $user): PersonalMina
    {
        return DB::transaction(function () use ($relation, $payload, $user): PersonalMina {
            $relation = PersonalMina::query()
                ->with('personal')
                ->lockForUpdate()
                ->findOrFail($relation->id);

            if (!(bool) ($relation->activo ?? true)) {
                throw ValidationException::withMessages(['asignacion' => 'La asignacion esta inactiva. Reactivala antes de cambiar su estado.']);
            }

            $state = $this->normalizeHabilitationState($payload['estado_habilitacion'] ?? $relation->estadoHabilitacionActual());
            $this->assertCeasedWorkerConfirmation($relation->personal, $state, $payload);
            $previousState = $relation->estadoHabilitacionActual();
            $observation = mb_substr(PersonalNormalizer::text($payload['observacion'] ?? $relation->observacion ?? ''), 0, 5000) ?: null;

            if ($state === PersonalMina::ESTADO_HABILITADO) {
                $relation->setRelation('examenes', $relation->examenes()->lockForUpdate()->get());
                $calculated = $this->calculateAssignmentStatus($relation);

                if ($calculated !== PersonalMina::ESTADO_HABILITADO) {
                    throw ValidationException::withMessages([
                        'estado_habilitacion' => 'No se puede marcar como habilitado hasta que tenga examenes generados y todos los requisitos esten resueltos.',
                    ]);
                }
            }

            $relation->forceFill([
                'estado' => $state,
                'estado_habilitacion' => $state,
                'fecha_inicio_proceso' => PersonalNormalizer::isoDate($payload['fecha_inicio_proceso'] ?? null) ?: $relation->fecha_inicio_proceso,
                'fecha_habilitacion' => $state === PersonalMina::ESTADO_HABILITADO
                    ? (PersonalNormalizer::isoDate($payload['fecha_habilitacion'] ?? null) ?: optional($relation->fecha_habilitacion)->toDateString() ?: $this->operationalToday()->toDateString())
                    : null,
                'observacion' => $observation,
                'usuario_actualizacion_id' => $user->id,
            ])->save();

            $this->recordHistory($relation, $previousState, $state, $observation, $user);
            $this->operationalNotifications->habilitacionAsignacion(
                $relation->fresh(['personal', 'mina']) ?: $relation,
                $user,
                $previousState
            );

            return $relation->load(['personal.contratoLaboralActual', 'mina', 'actualizadoPor.personal', 'historial.usuario.personal']);
        }, 3);
    }

    public function deactivateAssignment(PersonalMina $relation, Usuario $user, ?string $observation = null): PersonalMina
    {
        return DB::transaction(function () use ($relation, $user, $observation): PersonalMina {
            $relation = PersonalMina::query()->lockForUpdate()->findOrFail($relation->id);
            $previousState = $relation->estadoHabilitacionActual();

            $relation->forceFill([
                'activo' => false,
                'observacion' => mb_substr(PersonalNormalizer::text($observation ?? $relation->observacion ?? ''), 0, 5000) ?: $relation->observacion,
                'usuario_actualizacion_id' => $user->id,
            ])->save();

            $this->recordHistory($relation, $previousState, $previousState, 'Asignacion desactivada. ' . trim((string) $observation), $user);

            return $relation->fresh(['personal', 'mina']);
        }, 3);
    }

    public function generateRequiredExams(
        PersonalMina $relation,
        Usuario $user,
        bool $notify = true,
        bool $recalculate = true,
    ): int
    {
        if (!Schema::hasTable('personal_mina_examenes')) {
            return 0;
        }

        return DB::transaction(
            fn (): int => $this->generateRequiredExamsLocked($relation, $user, $notify, $recalculate),
            3,
        );
    }

    private function generateRequiredExamsLocked(PersonalMina $relation, Usuario $user, bool $notify, bool $recalculate): int
    {
        $relation = PersonalMina::query()->with('mina')->lockForUpdate()->findOrFail($relation->id);
        $requirements = MinaRequisito::query()
            ->with('examen')
            ->where('mina_id', $relation->mina_id)
            ->where('activo', true)
            ->whereNotNull('examen_id')
            ->orderBy('orden')
            ->get();

        $created = 0;
        foreach ($requirements as $requirement) {
            $exam = $requirement->examen;
            if (!$exam || !$exam->activo) {
                continue;
            }

            $existing = PersonalMinaExamen::query()
                ->where('personal_mina_id', $relation->id)
                ->where('examen_id', $exam->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (!$existing->requisitoMina?->activo) {
                    DB::transaction(function () use ($existing, $requirement, $relation, $user): void {
                        $locked = PersonalMinaExamen::query()->lockForUpdate()->findOrFail($existing->id);
                        if ($locked->requisitoMina?->activo) {
                            return;
                        }
                        $previousRequirement = $locked->mina_requisito_id;
                        $locked->forceFill([
                            'mina_requisito_id' => $requirement->id,
                            'usuario_actualizacion_id' => $user->id,
                            'fecha_actualizacion' => now(),
                        ])->save();
                        $state = $relation->estadoHabilitacionActual();
                        $this->recordHistory($relation, $state, $state,
                            'Requisito reincorporado: '.$locked->nombre_snapshot.'. Referencia anterior: '
                                .$previousRequirement.'. Referencia actual: '.$requirement->id, $user);
                    });
                }
                continue;
            }

            PersonalMinaExamen::query()->create($this->examSnapshotPayload($relation, $requirement, $exam, $user));
            $created++;
        }

        $this->syncCourseRecordsForAssignment($relation, $user);

        if ($recalculate) {
            $this->refreshAssignmentStatus($relation, $user, $notify);
        }

        return $created;
    }

    public function syncCourseRecordToMines(PersonalCursoRegistro $record, Usuario $user): array
    {
        return DB::transaction(fn (): array => $this->syncCourseRecordToMinesLocked($record, $user), 3);
    }

    private function syncCourseRecordToMinesLocked(PersonalCursoRegistro $record, Usuario $user): array
    {
        if (blank($record->mina_id)) {
            throw ValidationException::withMessages([
                'archivo' => 'El curso historico no tiene mina de origen. Revise su trazabilidad antes de aplicarlo.',
            ]);
        }
        if (!Schema::hasColumn('personal_mina_examen_intentos', 'personal_curso_registro_id')) {
            throw ValidationException::withMessages([
                'archivo' => 'Falta instalar la migracion que vincula cursos con habilitacion minera.',
            ]);
        }

        $mineIds = MinaRequisito::query()
            ->where('examen_id', $record->examen_id)
            ->where('activo', true)
            ->where('mina_id', $record->mina_id)
            ->pluck('mina_id')
            ->unique()
            ->values();

        if ($mineIds->isEmpty()) {
            return ['minas_actualizadas' => 0, 'examenes_generados' => 0];
        }

        $catalogExam = ExamenMinero::query()->find($record->examen_id);
        if (!$catalogExam || !$catalogExam->activo) {
            return ['minas_actualizadas' => 0, 'examenes_generados' => 0];
        }

        $assignments = PersonalMina::query()
            ->with('mina')
            ->where('personal_id', $record->personal_id)
            ->where('activo', true)
            ->whereIn('mina_id', $mineIds)
            ->whereHas('mina', fn ($query) => $query->activeOperational())
            ->lockForUpdate()
            ->get();

        $updated = 0;
        $generated = 0;
        foreach ($assignments as $assignment) {
            $workerExam = PersonalMinaExamen::query()
                ->where('personal_mina_id', $assignment->id)
                ->where('examen_id', $record->examen_id)
                ->lockForUpdate()->first();
            if (!$workerExam) {
                $requirement = MinaRequisito::query()
                    ->where('mina_id', $assignment->mina_id)
                    ->where('examen_id', $record->examen_id)
                    ->where('activo', true)
                    ->orderBy('orden')
                    ->first();
                if (!$requirement) {
                    continue;
                }
                $workerExam = PersonalMinaExamen::query()->create(
                    $this->examSnapshotPayload($assignment, $requirement, $catalogExam, $user)
                );
                $workerExam->load('intentos');
                $generated++;
            }
            $workerExam->setRelation('asignacion', $assignment);

            if ($this->syncCourseRecordToWorkerExam($record, $workerExam, $user)) {
                $updated++;
            }
            $this->refreshAssignmentStatus($assignment, $user, false);
        }

        return ['minas_actualizadas' => $updated, 'examenes_generados' => $generated];
    }

    private function syncCourseRecordsForAssignment(PersonalMina $assignment, Usuario $user): void
    {
        if (!Schema::hasTable('personal_curso_registros')
            || !Schema::hasColumn('personal_curso_registros', 'mina_id')
            || !Schema::hasColumn('personal_mina_examen_intentos', 'personal_curso_registro_id')) {
            return;
        }

        $examIds = PersonalMinaExamen::query()
            ->where('personal_mina_id', $assignment->id)
            ->lockForUpdate()
            ->pluck('examen_id')
            ->filter()
            ->unique();
        if ($examIds->isEmpty()) {
            return;
        }

        $records = PersonalCursoRegistro::query()
            ->where('personal_id', $assignment->personal_id)
            ->whereIn('examen_id', $examIds)
            ->where('mina_id', $assignment->mina_id)
            ->orderByRaw('COALESCE(fecha_realizacion, fecha_programacion) ASC')
            ->orderBy('created_at')
            ->get();

        foreach ($records as $record) {
            $this->syncCourseRecordToMines($record, $user);
        }
    }

    private function syncCourseRecordToWorkerExam(
        PersonalCursoRegistro $record,
        PersonalMinaExamen $workerExam,
        Usuario $user
    ): bool {
        $dateScheduled = optional($record->fecha_programacion)->toDateString();
        $dateDone = optional($record->fecha_realizacion)->toDateString();
        $expiration = optional($record->fecha_vencimiento)->toDateString();
        $score = $record->nota !== null ? (float) $record->nota : null;
        $result = strtoupper((string) $record->resultado);

        $attempt = PersonalMinaExamenIntento::query()
            ->where('personal_mina_examen_id', $workerExam->id)
            ->where('personal_curso_registro_id', $record->id)
            ->lockForUpdate()
            ->first();

        if (!$attempt && $dateDone) {
            $attempt = PersonalMinaExamenIntento::query()
                ->where('personal_mina_examen_id', $workerExam->id)
                ->whereDate('fecha_realizacion', $dateDone)
                ->where('resultado', $result)
                ->lockForUpdate()
                ->first();
        }
        if (!$attempt && $dateScheduled) {
            $attempt = PersonalMinaExamenIntento::query()
                ->where('personal_mina_examen_id', $workerExam->id)
                ->whereDate('fecha_programacion', $dateScheduled)
                ->where('resultado', PersonalMinaExamenIntento::RESULTADO_PENDIENTE)
                ->lockForUpdate()
                ->first();
        }

        $price = $this->resolveAttemptPriceSnapshot(
            $workerExam,
            $this->operationalToday()->toDateString(),
            $dateScheduled,
            $dateDone,
        );
        $attemptPayload = [
            'personal_curso_registro_id' => $record->id,
            'fecha_programacion' => $dateScheduled,
            'fecha_realizacion' => $dateDone,
            'resultado' => $result,
            'nota' => $score,
            'precio_aplicado' => $price['precio'],
            'moneda_aplicada' => $price['moneda'],
            'fecha_precio_aplicado' => $price['fecha'],
            'fuente_precio' => $price['fuente'],
            'observacion' => $record->observacion,
            'usuario_registro_id' => $user->id,
        ];

        if ($attempt) {
            $attempt->forceFill($attemptPayload)->save();
        } else {
            $attempt = PersonalMinaExamenIntento::query()->create($attemptPayload + [
                'id' => (string) Str::uuid(),
                'personal_mina_examen_id' => $workerExam->id,
                'numero_intento' => $this->nextStoredAttemptNumber($workerExam),
            ]);
        }

        $recordDate = $dateDone ?: $dateScheduled;
        $currentDate = optional($workerExam->fecha_realizacion)->toDateString()
            ?: optional($workerExam->fecha_programacion)->toDateString();
        $isPending = $result === PersonalMinaExamenIntento::RESULTADO_PENDIENTE;
        $resolved = in_array($workerExam->estado, [
            PersonalMinaExamen::ESTADO_APROBADO,
            PersonalMinaExamen::ESTADO_VIGENTE,
            PersonalMinaExamen::ESTADO_POR_VENCER,
            PersonalMinaExamen::ESTADO_CONVALIDADO,
            PersonalMinaExamen::ESTADO_NO_APLICA,
        ], true);
        $shouldApply = !$currentDate || !$recordDate || $recordDate >= $currentDate;
        if ($isPending && $resolved) {
            $shouldApply = false;
        }
        if ($shouldApply) {
            $this->applyAttemptToExam($workerExam, $result, $dateScheduled, $dateDone, $score, $expiration, [
                'observacion' => $record->observacion,
            ], $user);
        }

        return true;
    }

    public function calculatedAssignmentStatus(PersonalMina $relation): string
    {
        return $this->calculateAssignmentStatus(
            PersonalMina::query()->with('examenes')->findOrFail($relation->id)
        );
    }

    public function refreshAssignmentFromExams(PersonalMina $relation, ?Usuario $user, bool $notify = true): bool
    {
        return $this->refreshAssignmentStatus($relation, $user, $notify);
    }

    public function deactivateRequirementDeferred(MinaRequisito $requirement, Usuario $user): MinaRequisito
    {
        app(MineRequirementSyncService::class)->ensureAvailable();

        return DB::transaction(function () use ($requirement, $user): MinaRequisito {
            Mina::query()->whereKey($requirement->mina_id)->lockForUpdate()->first();
            $requirement->refresh();
            if (!$requirement->activo) {
                return $requirement;
            }
            $deactivated = $this->deactivateRequirement($requirement);
            app(MineRequirementSyncService::class)->request($deactivated->mina_id, $user);

            return $deactivated;
        });
    }

    public function deactivateRequirement(MinaRequisito $requirement, ?Usuario $user = null): MinaRequisito
    {
        $relatedRequirements = MinaRequisito::query()
            ->where('mina_id', $requirement->mina_id)
            ->where('activo', true)
            ->where(function ($query) use ($requirement): void {
                if (filled($requirement->examen_id)) {
                    $query->where('examen_id', $requirement->examen_id);
                } else {
                    $query->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower(trim((string) $requirement->nombre))]);
                }
            })
            ->get();

        if ($relatedRequirements->isEmpty()) {
            $relatedRequirements = collect([$requirement]);
        }

        $deactivatedIds = $relatedRequirements->pluck('id')->values()->all();

        MinaRequisito::query()
            ->whereIn('id', $deactivatedIds)
            ->update(['activo' => false]);

        $requirement->refresh();
        if ($user) {
            $this->syncMineAssignmentsForRequirementChange($requirement->mina_id, $user);
        }

        return $requirement
            ->fresh(['mina', 'examen'])
            ->setAttribute('deactivated_requirement_ids', $deactivatedIds);
    }

    public function syncMineAssignmentsAfterRequirementChanges(string $mineId, Usuario $user, bool $notify = true): array
    {
        return $this->syncMineAssignmentsForRequirementChange($mineId, $user, $notify);
    }

    public function syncCurrentInformation(Usuario $user): array
    {
        $generated = 0;
        $recalculated = 0;
        $prices = 0;
        $corrected = 0;
        $errors = 0;
        $withoutRequirements = 0;

        $exams = ExamenMinero::query()
            ->where('activo', true)
            ->where('empresa_paga', true)
            ->whereNotNull('precio')
            ->get();
        foreach ($exams as $exam) {
            $exists = ExamenMineroPrecio::query()
                ->where('examen_id', $exam->id)
                ->where('precio', $exam->precio)
                ->where('moneda', $exam->moneda ?: 'PEN')
                ->where('fecha_inicio', optional($exam->precio_desde)->toDateString() ?: $this->operationalToday()->toDateString())
                ->exists();
            if (!$exists) {
                $this->storeExamPrice($exam, [
                    'precio' => $exam->precio,
                    'moneda' => $exam->moneda ?: 'PEN',
                    'fecha_inicio' => optional($exam->precio_desde)->toDateString() ?: $this->operationalToday()->toDateString(),
                    'observacion' => 'Sincronizacion de informacion actual.',
                ], $user);
                $prices++;
            }
        }

        PersonalMina::query()
            ->where('activo', true)
            ->whereHas('mina', fn ($mineQuery) => $mineQuery->activeOperational())
            ->chunk(100, function ($relations) use ($user, &$generated, &$recalculated, &$corrected, &$errors, &$withoutRequirements): void {
                foreach ($relations as $relation) {
                    try {
                        $previousState = $relation->estadoHabilitacionActual();
                        $hasRequirements = MinaRequisito::query()
                            ->where('mina_id', $relation->mina_id)
                            ->where('activo', true)
                            ->whereNotNull('examen_id')
                            ->exists();

                        if (!$hasRequirements) {
                            $withoutRequirements++;
                        }

                        $generated += $this->generateRequiredExams($relation, $user, true, false);
                        $fresh = PersonalMina::query()->find($relation->id);
                        $changedDuringGeneration = $fresh && $fresh->estadoHabilitacionActual() !== $previousState;
                        if ($this->refreshAssignmentStatus($relation, $user) || $changedDuringGeneration) {
                            $corrected++;
                        }
                        $recalculated++;
                    } catch (\Throwable) {
                        $errors++;
                    }
                }
            }, 'id');

        return [
            'asignaciones_revisadas' => $recalculated,
            'examenes_generados' => $generated,
            'habilitaciones_corregidas' => $corrected,
            'trabajadores_no_recalculados' => $errors,
            'minas_sin_examenes_configurados' => $withoutRequirements,
            'errores_encontrados' => $errors,
            'asignaciones_corregidas' => $corrected,
            'estados_recalculados' => $recalculated,
            'precios_revisados' => $prices,
        ];
    }

    public function syncTemporalStatuses(): array
    {
        $assignments = 0;
        $exams = 0;
        $errors = 0;
        $requirementsByMine = MinaRequisito::query()
            ->where('activo', true)
            ->whereNotNull('examen_id')
            ->get(['id', 'mina_id', 'examen_id', 'obligatorio'])
            ->groupBy('mina_id');

        PersonalMina::query()
            ->where('activo', true)
            ->whereHas('mina', fn ($mineQuery) => $mineQuery->activeOperational())
            ->chunkById(100, function ($relations) use ($requirementsByMine, &$assignments, &$exams, &$errors): void {
                foreach ($relations as $relation) {
                    try {
                        DB::transaction(function () use ($relation, $requirementsByMine, &$assignments, &$exams): void {
                            $fresh = PersonalMina::query()->with('examenes')->lockForUpdate()->find($relation->id);
                            if (!$fresh) {
                                return;
                            }

                            $beforeExamStates = $fresh->examenes->pluck('estado', 'id');
                            $previous = $fresh->estadoHabilitacionActual();
                            $newState = $this->calculateAssignmentStatus(
                                $fresh,
                                $requirementsByMine->get($fresh->mina_id, collect()),
                            );
                            $changedExamCount = $fresh->examenes->filter(
                                fn (PersonalMinaExamen $exam): bool => $beforeExamStates->get($exam->id) !== $exam->estado
                            )->count();

                            if ($previous === $newState) {
                                $exams += $changedExamCount;

                                return;
                            }

                            $fresh->forceFill([
                                'estado' => $newState,
                                'estado_habilitacion' => $newState,
                                'fecha_habilitacion' => $newState === PersonalMina::ESTADO_HABILITADO
                                    ? ($fresh->fecha_habilitacion ?: $this->operationalToday()->toDateString())
                                    : null,
                            ])->save();
                            $this->recordHistory(
                                $fresh,
                                $previous,
                                $newState,
                                'Actualizacion automatica por vigencia de examenes mineros.',
                                null,
                            );
                            $exams += $changedExamCount;
                            $assignments++;
                        });
                    } catch (\Throwable $error) {
                        report($error);
                        $errors++;
                    }
                }
            }, 'id');

        return [
            'examenes_actualizados' => $exams,
            'asignaciones_actualizadas' => $assignments,
            'errores' => $errors,
        ];
    }

    private function syncMineAssignmentsForRequirementChange(string $mineId, Usuario $user, bool $notify = true): array
    {
        $generated = 0;
        $recalculated = 0;

        PersonalMina::query()
            ->where('mina_id', $mineId)
            ->where('activo', true)
            ->chunkById(100, function ($relations) use ($user, $notify, &$generated, &$recalculated): void {
                foreach ($relations as $relation) {
                    $previousState = $relation->estadoHabilitacionActual();
                    $generated += $this->generateRequiredExams($relation, $user, $notify, false);
                    $fresh = PersonalMina::query()->find($relation->id);
                    $changedDuringGeneration = $fresh && $fresh->estadoHabilitacionActual() !== $previousState;

                    if ($this->refreshAssignmentStatus($relation, $user, $notify) || $changedDuringGeneration) {
                        $recalculated++;
                    }
                }
            }, 'id');

        return [
            'examenes_generados' => $generated,
            'asignaciones_recalculadas' => $recalculated,
        ];
    }

    public function mineStatusBoardFor(?Personal $worker = null): array
    {
        $mines = $this->activeMines();
        if (!$worker) {
            return $mines->map(fn (Mina $mine) => [
                'mine' => $mine,
                'state' => 'NEUTRO',
                'label' => 'Disponible',
                'reason' => 'Selecciona un trabajador para evaluar esta mina.',
            ])->all();
        }

        $relations = PersonalMina::query()
            ->with(['personal.contratoLaboralActual', 'mina', 'examenes.intentos.emoCita', 'examenes.requisitoMina', 'examenes.examen'])
            ->where('personal_id', $worker->id)
            ->where('activo', true)
            ->get()
            ->keyBy('mina_id');

        return $mines->map(function (Mina $mine) use ($worker, $relations): array {
            $relation = $relations->get($mine->id);
            if ($relation) {
                $state = $this->visualAssignmentStateFor($relation);
                $summary = $this->assignmentExamSummary($relation);

                return [
                    'mine' => $mine,
                    'assignment' => $relation,
                    'state' => $state,
                    'label' => $this->boardLabelForState($state),
                    'reason' => $this->boardColorReason($state, $summary),
                    'summary' => $summary,
                ];
            }

            $blocked = $this->blockingReasonForMine($worker, $mine);

            return [
                'mine' => $mine,
                'state' => $blocked ? 'BLOQUEADA' : 'NEUTRO',
                'label' => $blocked ? 'Bloqueada' : 'Disponible',
                'reason' => $blocked ?: 'Sin proceso iniciado.',
                'summary' => ['total' => 0, 'resueltos' => 0, 'pendientes' => 0, 'programados' => 0, 'desaprobados' => 0, 'vencidos' => 0],
            ];
        })->all();
    }

    public function visualAssignmentStateFor(PersonalMina $relation): string
    {
        $relation = $relation->relationLoaded('examenes')
            ? $relation
            : PersonalMina::query()->with('examenes.intentos')->find($relation->id);
        if (!$relation) {
            return PersonalMina::ESTADO_EN_PROCESO;
        }

        // The board is operational: derive it from current requirements and
        // exams so a stale persisted state cannot show the wrong card color.
        $state = $this->calculateAssignmentStatus($relation);
        if (
            $state === PersonalMina::ESTADO_EN_PROCESO
            && $relation->examenes->isNotEmpty()
            && !$this->assignmentHasStarted($relation)
        ) {
            return 'ASIGNADO_PENDIENTE_INICIO';
        }

        return $state;
    }

    public function assignmentHasStarted(PersonalMina $relation): bool
    {
        $exams = $relation->relationLoaded('examenes')
            ? $relation->examenes
            : PersonalMinaExamen::query()->with('intentos')->where('personal_mina_id', $relation->id)->get();

        return $exams->contains(function (PersonalMinaExamen $exam): bool {
            $attempts = $exam->relationLoaded('intentos')
                ? $exam->intentos
                : collect();

            return filled($exam->fecha_programacion)
                || filled($exam->fecha_realizacion)
                || filled($exam->fecha_vencimiento)
                || filled($exam->resultado)
                || filled($exam->nota_obtenida)
                || filled($exam->observacion)
                || (bool) $exam->es_convalidado
                || $exam->estado !== PersonalMinaExamen::ESTADO_PENDIENTE
                || $attempts->where('resultado', '!=', PersonalMinaExamenIntento::RESULTADO_ANULADO)->isNotEmpty();
        });
    }

    public function assignmentExamSummary(PersonalMina $relation): array
    {
        $exams = $relation->relationLoaded('examenes')
            ? $relation->examenes
            : PersonalMinaExamen::query()->where('personal_mina_id', $relation->id)->get();

        return [
            'total' => $exams->count(),
            'resueltos' => $exams->whereIn('estado', [
                PersonalMinaExamen::ESTADO_APROBADO,
                PersonalMinaExamen::ESTADO_VIGENTE,
                PersonalMinaExamen::ESTADO_POR_VENCER,
                PersonalMinaExamen::ESTADO_CONVALIDADO,
                PersonalMinaExamen::ESTADO_NO_APLICA,
            ])->count(),
            'pendientes' => $exams->where('estado', PersonalMinaExamen::ESTADO_PENDIENTE)->count(),
            'programados' => $exams->where('estado', PersonalMinaExamen::ESTADO_PROGRAMADO)->count(),
            'desaprobados' => $exams->where('estado', PersonalMinaExamen::ESTADO_DESAPROBADO)->count(),
            'vencidos' => $exams->where('estado', PersonalMinaExamen::ESTADO_VENCIDO)->count(),
        ];
    }

    public function registerAttempt(PersonalMinaExamen $workerExam, array $payload, ?UploadedFile $file, Usuario $user): PersonalMinaExamen
    {
        $currentExam = PersonalMinaExamen::with('examen')->findOrFail($workerExam->id);
        if ($currentExam->examen?->categoria_operativa === ExamenMinero::CATEGORIA_EMO) {
            return $this->registerEmoAttempt($currentExam, $payload, $file, $user);
        }
        $payload = \App\Modules\Personal\Support\CursoResultado::payload($payload);
        $workerExam = PersonalMinaExamen::query()
            ->with(['asignacion', 'intentos'])
            ->findOrFail($workerExam->id);

        $this->assertExamOperational($workerExam);

        $isExpired = $this->expiredAttemptCycleCutoff($workerExam) !== null;

        if (!$isExpired && in_array(strtoupper((string) $workerExam->estado), [
            PersonalMinaExamen::ESTADO_CONVALIDADO,
            PersonalMinaExamen::ESTADO_VIGENTE,
            PersonalMinaExamen::ESTADO_APROBADO,
        ], true)) {
            throw ValidationException::withMessages(['examen' => 'Este examen ya fue resuelto.']);
        }

        $result = $this->normalizeAttemptResult($payload['resultado'] ?? PersonalMinaExamenIntento::RESULTADO_PENDIENTE);
        $dateDone = PersonalNormalizer::isoDate($payload['fecha_realizacion'] ?? null);
        $this->validateRealizationDate($dateDone, $result);
        $dateScheduled = PersonalNormalizer::isoDate($payload['fecha_programacion'] ?? null);
        $score = $payload['nota'] ?? null;

        if ($workerExam->estado !== PersonalMinaExamen::ESTADO_NO_APLICA && $result === PersonalMinaExamenIntento::RESULTADO_PENDIENTE && $dateScheduled) {
            $hasPendingSchedule = $this->currentCycleAttempts($workerExam)
                ->where('resultado', PersonalMinaExamenIntento::RESULTADO_PENDIENTE)
                ->filter(fn (PersonalMinaExamenIntento $attempt): bool => filled($attempt->fecha_programacion))
                ->isNotEmpty();

            if ($hasPendingSchedule) {
                throw ValidationException::withMessages(['fecha_programacion' => 'Este examen ya tiene una programacion pendiente.']);
            }
        }

        if ($workerExam->requiere_nota_snapshot && $result === PersonalMinaExamenIntento::RESULTADO_APROBADO && ($score === null || $score === '')) {
            throw ValidationException::withMessages(['nota' => 'La nota es obligatoria para este examen.']);
        }

        if ($workerExam->requiere_nota_snapshot && $score !== null && $score !== '') {
            $score = (float) $score;
            if ($result === PersonalMinaExamenIntento::RESULTADO_APROBADO && $workerExam->nota_minima_snapshot !== null && $score < (float) $workerExam->nota_minima_snapshot) {
                $result = PersonalMinaExamenIntento::RESULTADO_DESAPROBADO;
            }
        } else {
            $score = null;
        }

        $manualExpiration = PersonalNormalizer::isoDate($payload['fecha_vencimiento'] ?? null);
        if ($manualExpiration && $dateDone && $manualExpiration < $dateDone) {
            throw ValidationException::withMessages(['fecha_vencimiento' => 'La fecha de vencimiento no puede ser menor a la fecha de realizacion.']);
        }

        $storedFile = null;
        try {
            $createdAttempt = DB::transaction(function () use ($workerExam, $dateScheduled, $dateDone, $result, $score, $payload, $file, &$storedFile, $manualExpiration, $user): PersonalMinaExamenIntento {
                $lockedAssignment = PersonalMina::query()->whereKey($workerExam->personal_mina_id)->lockForUpdate()->firstOrFail();
                $lockedExam = PersonalMinaExamen::query()->whereKey($workerExam->id)->lockForUpdate()->firstOrFail();
                $lockedExam->setRelation('asignacion', $lockedAssignment);
                $lockedExam->setRelation('intentos', $lockedExam->intentos()->lockForUpdate()->get());
                $this->assertExamOperational($lockedExam);
                if ($this->expiredAttemptCycleCutoff($lockedExam) === null && in_array($lockedExam->estado, [
                    PersonalMinaExamen::ESTADO_CONVALIDADO,
                    PersonalMinaExamen::ESTADO_VIGENTE,
                    PersonalMinaExamen::ESTADO_APROBADO,
                ], true)) {
                    throw ValidationException::withMessages(['examen' => 'Este examen ya fue resuelto.']);
                }
                if ($lockedExam->estado === PersonalMinaExamen::ESTADO_NO_APLICA) {
                    app(LegacyMiningDeployRepairService::class)->closeNotApplicablePendingLocked($lockedExam, $lockedExam->intentos, $user);
                    $lockedExam->forceFill([
                        'intentos_ciclo_desde' => now(),
                        'intentos_ciclo_numero_desde' => $this->nextStoredAttemptNumber($lockedExam),
                    ])->save();
                    $lockedExam->setRelation('intentos', collect());
                }
                if ($result === PersonalMinaExamenIntento::RESULTADO_PENDIENTE && $dateScheduled
                    && $this->currentCycleAttempts($lockedExam)
                        ->where('resultado', PersonalMinaExamenIntento::RESULTADO_PENDIENTE)
                        ->contains(fn (PersonalMinaExamenIntento $attempt): bool => filled($attempt->fecha_programacion))) {
                    throw ValidationException::withMessages(['fecha_programacion' => 'Este examen ya tiene una programacion pendiente.']);
                }

                $storedFile = $file ? $this->storeAttemptFile($lockedExam, $file) : null;
                $storedAttemptNumber = $this->nextStoredAttemptNumber($lockedExam);
                $priceSnapshot = $this->resolveAttemptPriceSnapshot($lockedExam, $this->operationalToday()->toDateString(), $dateScheduled, $dateDone);

                $createdAttempt = PersonalMinaExamenIntento::query()->create([
                    'id' => (string) Str::uuid(),
                    'personal_mina_examen_id' => $lockedExam->id,
                    'numero_intento' => $storedAttemptNumber,
                    'fecha_programacion' => $dateScheduled,
                    'fecha_realizacion' => $dateDone,
                    'resultado' => $result,
                    'nota' => $score,
                    'precio_aplicado' => $priceSnapshot['precio'],
                    'moneda_aplicada' => $priceSnapshot['moneda'],
                    'fecha_precio_aplicado' => $priceSnapshot['fecha'],
                    'fuente_precio' => $priceSnapshot['fuente'],
                    'archivo_path' => $storedFile['path'] ?? null,
                    'archivo_nombre_original' => $storedFile['original_name'] ?? null,
                    'archivo_mime' => $storedFile['mime'] ?? null,
                    'archivo_size' => $storedFile['size'] ?? null,
                    'observacion' => mb_substr(PersonalNormalizer::text($payload['observacion'] ?? ''), 0, 5000) ?: null,
                    'usuario_registro_id' => $user->id,
                ]);

                $this->applyAttemptToExam($lockedExam, $result, $dateScheduled, $dateDone, $score, $manualExpiration, $payload, $user);
                $this->refreshAssignmentStatus($lockedExam->asignacion, $user);

                return $createdAttempt;
            });
        } catch (\Throwable $exception) {
            $this->deleteFailedAttemptFile($storedFile);
            $this->rethrowAttemptNumberConflict($exception);
            throw $exception;
        }

        $updated = $workerExam->fresh(['asignacion.personal', 'asignacion.mina', 'intentos']);
        if ($updated && $result === PersonalMinaExamenIntento::RESULTADO_PENDIENTE && $dateScheduled) {
            $this->operationalNotifications->examenProgramado($updated, $user, $createdAttempt);
        }

        return $updated;
    }

    public function completeScheduledAttempt(PersonalMinaExamenIntento $attempt, array $payload, ?UploadedFile $file, Usuario $user): PersonalMinaExamen
    {
        $currentAttempt = PersonalMinaExamenIntento::with('examenTrabajador.examen')->findOrFail($attempt->id);
        if ($currentAttempt->emo_cita_id || $currentAttempt->examenTrabajador->examen?->categoria_operativa === ExamenMinero::CATEGORIA_EMO) {
            if (!$currentAttempt->emo_cita_id) {
                throw ValidationException::withMessages(['clinica_id' => 'Regularice la clinica del EMO historico antes de registrar su resultado.']);
            }
            app(EmoAppointmentService::class)->complete(
                PersonalEmoCita::findOrFail($currentAttempt->emo_cita_id), $this->completeEmoData($payload, $file), $user,
            );
            return $currentAttempt->examenTrabajador->fresh(['asignacion', 'intentos']);
        }
        $payload = \App\Modules\Personal\Support\CursoResultado::payload($payload);
        $attempt = PersonalMinaExamenIntento::query()
            ->with('examenTrabajador.asignacion')
            ->findOrFail($attempt->id);
        $workerExam = PersonalMinaExamen::query()
            ->with(['asignacion', 'intentos'])
            ->findOrFail($attempt->personal_mina_examen_id);

        $this->assertExamOperational($workerExam);

        if ($attempt->resultado !== PersonalMinaExamenIntento::RESULTADO_PENDIENTE) {
            throw ValidationException::withMessages(['intento' => 'Este examen programado ya tiene resultado registrado.']);
        }

        if (!$attempt->fecha_programacion) {
            throw ValidationException::withMessages(['fecha_programacion' => 'Este intento no tiene fecha de programacion.']);
        }

        if ($attempt->fecha_programacion->toDateString() > $this->operationalToday()->toDateString()) {
            throw ValidationException::withMessages(['fecha_programacion' => 'Todavia no se puede registrar resultado porque la fecha programada no ha pasado.']);
        }

        if (in_array(strtoupper((string) $workerExam->estado), [
            PersonalMinaExamen::ESTADO_NO_APLICA,
            PersonalMinaExamen::ESTADO_CONVALIDADO,
            PersonalMinaExamen::ESTADO_VIGENTE,
            PersonalMinaExamen::ESTADO_APROBADO,
        ], true)) {
            throw ValidationException::withMessages(['examen' => 'Este examen ya fue resuelto.']);
        }

        $result = $this->normalizeAttemptResult($payload['resultado'] ?? '');
        if ($result === PersonalMinaExamenIntento::RESULTADO_PENDIENTE) {
            throw ValidationException::withMessages(['resultado' => 'Selecciona el resultado obtenido.']);
        }

        $dateDone = PersonalNormalizer::isoDate($payload['fecha_realizacion'] ?? null);
        if (!$dateDone) {
            throw ValidationException::withMessages(['fecha_realizacion' => 'La fecha de realizacion es obligatoria.']);
        }
        $this->validateRealizationDate($dateDone, $result);

        $dateScheduled = $attempt->fecha_programacion->toDateString();
        $score = $payload['nota'] ?? null;
        if ($workerExam->requiere_nota_snapshot && $result === PersonalMinaExamenIntento::RESULTADO_APROBADO && ($score === null || $score === '')) {
            throw ValidationException::withMessages(['nota' => 'La nota es obligatoria para este examen.']);
        }

        if ($workerExam->requiere_nota_snapshot && $score !== null && $score !== '') {
            $score = (float) $score;
            if ($result === PersonalMinaExamenIntento::RESULTADO_APROBADO && $workerExam->nota_minima_snapshot !== null && $score < (float) $workerExam->nota_minima_snapshot) {
                $result = PersonalMinaExamenIntento::RESULTADO_DESAPROBADO;
            }
        } else {
            $score = null;
        }

        $manualExpiration = PersonalNormalizer::isoDate($payload['fecha_vencimiento'] ?? null);
        if ($manualExpiration && $manualExpiration < $dateDone) {
            throw ValidationException::withMessages(['fecha_vencimiento' => 'La fecha de vencimiento no puede ser menor a la fecha de realizacion.']);
        }

        $storedFile = null;
        try {
            DB::transaction(function () use ($attempt, $workerExam, $dateDone, $result, $score, $payload, $file, &$storedFile, $manualExpiration, $user): void {
                $lockedAssignment = PersonalMina::query()->whereKey($workerExam->personal_mina_id)->lockForUpdate()->firstOrFail();
                $lockedExam = PersonalMinaExamen::query()->whereKey($workerExam->id)->lockForUpdate()->firstOrFail();
                $lockedExam->setRelation('asignacion', $lockedAssignment);
                $this->assertExamOperational($lockedExam);
                $lockedAttempt = PersonalMinaExamenIntento::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                if ($lockedAttempt->resultado !== PersonalMinaExamenIntento::RESULTADO_PENDIENTE) {
                    throw ValidationException::withMessages(['intento' => 'Este examen programado ya tiene resultado registrado.']);
                }
                if (!$lockedAttempt->fecha_programacion || $lockedAttempt->fecha_programacion->toDateString() > $this->operationalToday()->toDateString()) {
                    throw ValidationException::withMessages(['fecha_programacion' => 'Esta programacion ya no permite registrar un resultado.']);
                }
                if (in_array($lockedExam->estado, [
                    PersonalMinaExamen::ESTADO_NO_APLICA,
                    PersonalMinaExamen::ESTADO_CONVALIDADO,
                    PersonalMinaExamen::ESTADO_VIGENTE,
                    PersonalMinaExamen::ESTADO_APROBADO,
                ], true)) {
                    throw ValidationException::withMessages(['examen' => 'Este examen ya fue resuelto.']);
                }

                $dateScheduled = $lockedAttempt->fecha_programacion->toDateString();
                $storedFile = $file ? $this->storeAttemptFile($lockedExam, $file) : null;
                $priceSnapshot = $this->resolveAttemptPriceSnapshot($lockedExam, $this->operationalToday()->toDateString(), $dateScheduled, $dateDone);
                $attemptPayload = [
                    'fecha_realizacion' => $dateDone,
                    'resultado' => $result,
                    'nota' => $score,
                    'precio_aplicado' => $priceSnapshot['precio'],
                    'moneda_aplicada' => $priceSnapshot['moneda'],
                    'fecha_precio_aplicado' => $priceSnapshot['fecha'],
                    'fuente_precio' => $priceSnapshot['fuente'],
                    'observacion' => mb_substr(PersonalNormalizer::text($payload['observacion'] ?? ''), 0, 5000) ?: null,
                    'usuario_registro_id' => $user->id,
                ];

                if ($storedFile) {
                    $attemptPayload = [
                        ...$attemptPayload,
                        'archivo_path' => $storedFile['path'] ?? null,
                        'archivo_nombre_original' => $storedFile['original_name'] ?? null,
                        'archivo_mime' => $storedFile['mime'] ?? null,
                        'archivo_size' => $storedFile['size'] ?? null,
                    ];
                }

                $lockedAttempt->forceFill($attemptPayload)->save();

                $this->applyAttemptToExam($lockedExam, $result, $dateScheduled, $dateDone, $score, $manualExpiration, $payload, $user);
                $this->refreshAssignmentStatus($lockedExam->asignacion, $user);
            });
        } catch (\Throwable $exception) {
            $this->deleteFailedAttemptFile($storedFile);
            throw $exception;
        }

        return $workerExam->fresh(['asignacion', 'intentos']);
    }

    private function registerEmoAttempt(PersonalMinaExamen $exam, array $payload, ?UploadedFile $file, Usuario $actor): PersonalMinaExamen
    {
        $pending = strtoupper(trim((string) ($payload['resultado'] ?? 'PENDIENTE'))) === 'PENDIENTE';
        $scheduled = PersonalNormalizer::isoDate($payload['fecha_programacion'] ?? ($pending ? null : ($payload['fecha_realizacion'] ?? null)));
        if (!$scheduled) {
            throw ValidationException::withMessages(['fecha_programacion' => 'Indique la fecha de programacion de la cita EMO.']);
        }
        $completion = $pending ? null : $this->completeEmoData($payload, $file);
        return DB::transaction(function () use ($exam, $payload, $file, $actor, $scheduled, $completion): PersonalMinaExamen {
            $appointment = app(EmoAppointmentService::class)->schedule($exam, new ScheduleEmoData(
                CarbonImmutable::parse($scheduled, config('app.display_timezone', 'America/Lima')),
                (string) ($payload['clinica_id'] ?? ''), $payload['observacion'] ?? null,
                EmoOrigin::fromInput($payload['origen'] ?? 'MANUAL'), $payload['personal_curso_registro_id'] ?? null,
            ), $actor);
            if ($completion) {
                app(EmoAppointmentService::class)->complete($appointment, $completion, $actor);
            } elseif ($file) {
                $attempt = $appointment->intentos()->where('personal_mina_examen_id', $exam->id)->lockForUpdate()->firstOrFail();
                if ($attempt->archivo_path) {
                    throw ValidationException::withMessages(['archivo' => 'La programacion ya conserva evidencia. Revise el archivo existente.']);
                }
                $storedFile = $this->storeAttemptFile($exam, $file);
                $path = $storedFile['path'];
                $connection = DB::connection()->getName();
                // Compensate actual rollback only: a failing afterCommit callback cannot undo MySQL's commit.
                foreach (app('db.transactions')->getPendingTransactions() as $transaction) {
                    if ($transaction->connection === $connection) {
                        $transaction->addCallbackForRollback(fn () => Storage::disk('local')->delete($path));
                    }
                }
                $attempt->forceFill([
                    'archivo_path' => $path, 'archivo_nombre_original' => $storedFile['original_name'],
                    'archivo_mime' => $storedFile['mime'], 'archivo_size' => $storedFile['size'],
                ])->save();
            }
            return $exam->fresh(['asignacion', 'intentos']);
        });
    }

    private function completeEmoData(array $payload, ?UploadedFile $file): CompleteEmoData
    {
        $performed = PersonalNormalizer::isoDate($payload['fecha_realizacion'] ?? null);
        if (!$performed) {
            throw ValidationException::withMessages(['fecha_realizacion' => 'La fecha de realizacion es obligatoria.']);
        }
        $expiration = PersonalNormalizer::isoDate($payload['fecha_vencimiento'] ?? null);
        return new CompleteEmoData(
            CarbonImmutable::parse($performed, config('app.display_timezone', 'America/Lima')),
            EmoOutcome::fromInput($payload['resultado'] ?? null),
            $payload['detalle_resultado_emo'] ?? $payload['observacion'] ?? null,
            isset($payload['nota']) && $payload['nota'] !== '' ? (string) $payload['nota'] : null,
            $expiration ? CarbonImmutable::parse($expiration, config('app.display_timezone', 'America/Lima')) : null,
            $payload['observacion'] ?? null, $file, EmoOrigin::fromInput($payload['origen'] ?? 'MANUAL'),
            $payload['personal_curso_registro_id'] ?? null,
        );
    }

    /** Internal domain bridge: EmoAppointmentService must already hold worker,
     * appointment, assignment, exam and attempt locks in that order. This only
     * projects the linked attempt using the existing validity/renewal rules.
     */
    public function applyEmoAppointmentAttempt(PersonalMinaExamen $exam, PersonalMinaExamenIntento $attempt, ?CarbonImmutable $manualExpiration, Usuario $actor): void
    {
        if (DB::transactionLevel() === 0 || !$attempt->emo_cita_id || $attempt->personal_mina_examen_id !== $exam->id) {
            throw new \LogicException('Una proyeccion EMO exige intento vinculado y transaccion del agregado.');
        }
        $this->assertExamOperational($exam);
        $cancelled = $attempt->resultado === PersonalMinaExamenIntento::RESULTADO_ANULADO;
        $this->applyAttemptToExam($exam, $cancelled ? 'PENDIENTE' : $attempt->resultado,
            $cancelled ? null : $attempt->fecha_programacion?->toDateString(),
            $attempt->fecha_realizacion?->toDateString(), $attempt->nota !== null ? (float) $attempt->nota : null,
            $manualExpiration?->toDateString(), ['observacion' => $attempt->observacion], $actor);
    }

    public function markExamNotApplicable(PersonalMinaExamen $workerExam, array $payload, Usuario $user): PersonalMinaExamen
    {
        $current = PersonalMinaExamen::query()->with('examen')->findOrFail($workerExam->id);
        if ($current->examen?->categoria_operativa === ExamenMinero::CATEGORIA_EMO) {
            return app(EmoAppointmentService::class)->markExamNotApplicable($current, $payload, $user);
        }

        return DB::transaction(function () use ($workerExam, $payload, $user): PersonalMinaExamen {
            $lockedAssignment = PersonalMina::query()->whereKey($workerExam->personal_mina_id)->lockForUpdate()->firstOrFail();
            $workerExam = PersonalMinaExamen::query()
                ->lockForUpdate()
                ->findOrFail($workerExam->id);
            $workerExam->setRelation('asignacion', $lockedAssignment);
            $this->assertExamOperational($workerExam);
            $attempts = $workerExam->intentos()->lockForUpdate()->get();

            if (!$workerExam->requisitoMina?->permite_no_aplica) {
                throw ValidationException::withMessages(['no_aplica' => 'Este examen no permite marcar no aplica.']);
            }

            $observation = mb_substr(PersonalNormalizer::text($payload['observacion'] ?? ''), 0, 5000) ?: null;

            foreach ($attempts->where('resultado', PersonalMinaExamenIntento::RESULTADO_PENDIENTE) as $attempt) {
                $attempt->forceFill(['resultado' => PersonalMinaExamenIntento::RESULTADO_ANULADO])->save();
            }

            $workerExam->forceFill([
                'estado' => PersonalMinaExamen::ESTADO_NO_APLICA,
                'resultado' => 'NO_APLICA',
                'observacion' => $observation,
                'usuario_actualizacion_id' => $user->id,
                'fecha_actualizacion' => now(),
                'intentos_ciclo_desde' => now(),
                'intentos_ciclo_numero_desde' => 1 + (int) $attempts->max('numero_intento'),
            ])->save();

            $this->refreshAssignmentStatus($workerExam->asignacion, $user);

            return $workerExam->fresh(['asignacion', 'intentos']);
        });
    }

    private function assertExamOperational(PersonalMinaExamen $workerExam): void
    {
        $workerExam->loadMissing(['asignacion', 'requisitoMina', 'examen']);
        if (!$workerExam->asignacion?->activo) {
            throw ValidationException::withMessages(['examen' => 'La asignacion esta inactiva. Asigna nuevamente al trabajador antes de continuar.']);
        }
        if (!Mina::query()->activeOperational()->whereKey($workerExam->asignacion->mina_id)->exists()) {
            throw ValidationException::withMessages(['examen' => 'La mina ya no esta disponible para habilitaciones.']);
        }
        if (!$workerExam->requisitoMina?->activo
            || $workerExam->requisitoMina->mina_id !== $workerExam->asignacion->mina_id
            || $workerExam->requisitoMina->examen_id !== $workerExam->examen_id) {
            throw ValidationException::withMessages(['examen' => 'Este requisito fue retirado o cambio en la mina. Actualiza los examenes antes de continuar.']);
        }
        if (!$workerExam->examen?->activo) {
            throw ValidationException::withMessages(['examen' => 'Este examen esta inactivo en el catalogo.']);
        }
    }

    public function convalidationOptions(PersonalMinaExamen $workerExam): array
    {
        $workerExam = PersonalMinaExamen::query()
            ->with(['asignacion.mina', 'asignacion.personal', 'requisitoMina', 'examen'])
            ->findOrFail($workerExam->id);

        try {
            $this->assertConvalidationTarget($workerExam);
        } catch (ValidationException $exception) {
            return [
                'available' => false,
                'message' => collect($exception->errors())->flatten()->first(),
                'options' => [],
            ];
        }

        $allowedOrigins = $workerExam->requisitoMina->minas_origen_convalidacion_json ?: [];
        $today = $this->operationalToday()->toDateString();
        $origins = PersonalMinaExamen::query()
            ->with('asignacion.mina')
            ->whereIn('examen_id', array_merge(app(MiningExamIdentityResolver::class)->groupIds($workerExam->examen_id), $workerExam->requisitoMina->examenes_equivalentes_json ?: []))
            ->where('id', '!=', $workerExam->id)
            ->whereHas('asignacion', fn ($query) => $query
                ->where('personal_id', $workerExam->asignacion->personal_id)
                ->when(!$workerExam->requisitoMina->convalidar_desde_otras_minas,
                    fn ($query) => $query->where('mina_id', $workerExam->asignacion->mina_id))
                ->when($allowedOrigins !== [], fn ($query) => $query->whereIn('mina_id', $allowedOrigins)))
            ->whereIn('estado', [
                PersonalMinaExamen::ESTADO_APROBADO,
                PersonalMinaExamen::ESTADO_VIGENTE,
                PersonalMinaExamen::ESTADO_POR_VENCER,
                PersonalMinaExamen::ESTADO_CONVALIDADO,
            ])
            ->where(function ($query) use ($today) {
                $query->whereNull('fecha_vencimiento')->orWhereDate('fecha_vencimiento', '>=', $today);
            })
            ->when($workerExam->requiere_nota_snapshot, fn ($query) => $query
                ->whereNotNull('nota_obtenida')
                ->where('nota_obtenida', '>=', $workerExam->nota_minima_snapshot ?? 0))
            ->orderByDesc('fecha_realizacion')
            ->limit(50)
            ->get()
            ->map(fn ($origin) => [
                'id' => $origin->id,
                'mina' => $origin->asignacion?->mina?->nombre,
                'examen' => $origin->nombre_snapshot,
                'nota' => $origin->nota_obtenida,
                'equivalente' => $origin->examen_id !== $workerExam->examen_id,
                'fecha_realizacion' => optional($origin->fecha_realizacion)->format('d/m/Y'),
                'fecha_vencimiento' => optional($origin->fecha_vencimiento)->format('d/m/Y'),
                'estado' => $origin->estado,
            ])->values()->all();

        return [
            'available' => $origins !== [],
            'message' => $origins === [] ? 'No hay resultados aprobados y vigentes del mismo examen o de sus equivalentes autorizados que cumplan la nota requerida.' : null,
            'options' => $origins,
            'destino' => [
                'trabajador' => $workerExam->asignacion?->personal?->nombre_completo,
                'mina' => $workerExam->asignacion?->mina?->nombre,
                'examen' => $workerExam->nombre_snapshot,
                'requiere_nota' => $workerExam->requiere_nota_snapshot,
                'nota_minima' => $workerExam->nota_minima_snapshot,
            ],
        ];
    }

    private function assertConvalidationTarget(PersonalMinaExamen $workerExam): void
    {
        if (!$workerExam->asignacion?->activo || !$workerExam->requisitoMina?->activo || !$workerExam->examen?->activo) {
            throw ValidationException::withMessages(['convalidacion' => 'El examen ya no esta activo en esta habilitacion minera.']);
        }

        if ($workerExam->asignacion?->estadoHabilitacionActual() !== PersonalMina::ESTADO_EN_PROCESO) {
            throw ValidationException::withMessages([
                'convalidacion' => 'Solo se puede convalidar mientras la habilitacion de destino esta en proceso.',
            ]);
        }

        if (in_array($workerExam->estado, [
            PersonalMinaExamen::ESTADO_APROBADO,
            PersonalMinaExamen::ESTADO_VIGENTE,
            PersonalMinaExamen::ESTADO_POR_VENCER,
            PersonalMinaExamen::ESTADO_CONVALIDADO,
            PersonalMinaExamen::ESTADO_NO_APLICA,
            PersonalMinaExamen::ESTADO_PROGRAMADO,
        ], true)) {
            throw ValidationException::withMessages(['convalidacion' => 'Este examen ya esta resuelto y no necesita convalidacion.']);
        }

        if (!$workerExam->requisitoMina?->permite_convalidacion_mina) {
            throw ValidationException::withMessages(['convalidacion' => 'Este examen no permite convalidacion para esta mina.']);
        }
        if (!$workerExam->examen?->permite_convalidacion) {
            throw ValidationException::withMessages(['convalidacion' => 'El curso o EMO no permite convalidacion en el catalogo.']);
        }

        $today = $this->operationalToday()->toDateString();
        $start = $workerExam->requisitoMina->fecha_inicio_convalidacion;
        $end = $workerExam->requisitoMina->fecha_fin_convalidacion;
        if (($start && $today < $start->toDateString()) || ($end && $today > $end->toDateString())) {
            throw ValidationException::withMessages(['convalidacion' => 'La convalidacion esta fuera del rango permitido.']);
        }
    }

    public function convalidateExam(PersonalMinaExamen $workerExam, string $originExamId, array $payload, Usuario $user): PersonalMinaExamen
    {
        return DB::transaction(
            fn (): PersonalMinaExamen => $this->convalidateExamLocked($workerExam, $originExamId, $payload, $user),
            3
        );
    }

    private function convalidateExamLocked(PersonalMinaExamen $workerExam, string $originExamId, array $payload, Usuario $user): PersonalMinaExamen
    {
        // Medical evidence is read before assignment/exam locks, matching the
        // appointment aggregate order and avoiding an inverted appointment lock.
        $workerId = PersonalMina::findOrFail($workerExam->personal_mina_id)->personal_id;
        Personal::whereKey($workerId)->lockForUpdate()->firstOrFail();
        $medicalAppointments = PersonalEmoCita::where('personal_id', $workerId)->orderBy('id')->sharedLock()->get()->keyBy('id');
        $assignmentIds = PersonalMinaExamen::query()->whereIn('id', [$workerExam->id, $originExamId])
            ->pluck('personal_mina_id')->unique();
        $assignments = PersonalMina::query()->whereIn('id', $assignmentIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $workerExam = PersonalMinaExamen::query()
            ->with(['asignacion.mina', 'requisitoMina', 'examen'])
            ->lockForUpdate()
            ->findOrFail($workerExam->id);
        $workerExam->setRelation('asignacion', $assignments->get($workerExam->personal_mina_id));

        $workerExam->setRelation('requisitoMina', MinaRequisito::query()
            ->lockForUpdate()->find($workerExam->mina_requisito_id));

        $this->assertConvalidationTarget($workerExam);
        $origin = PersonalMinaExamen::query()
            ->with('asignacion.mina')
            ->lockForUpdate()
            ->find($originExamId);
        if ($origin) {
            $origin->setRelation('asignacion', $assignments->get($origin->personal_mina_id));
        }
        if (!$origin || !in_array($origin->examen_id, array_merge(app(MiningExamIdentityResolver::class)->groupIds($workerExam->examen_id), $workerExam->requisitoMina->examenes_equivalentes_json ?: []), true)) {
            throw ValidationException::withMessages(['examen_origen' => 'Selecciona un examen origen valido.']);
        }
        if ($origin->id === $workerExam->id
            || $origin->asignacion?->personal_id !== $workerExam->asignacion?->personal_id) {
            throw ValidationException::withMessages(['examen_origen' => 'El examen origen debe pertenecer al mismo trabajador y a otro proceso.']);
        }
        if (!$this->isExamApprovedAndCurrent($origin)) {
            throw ValidationException::withMessages(['examen_origen' => 'El examen origen no esta aprobado o vigente.']);
        }
        if ($workerExam->requiere_nota_snapshot && ($origin->nota_obtenida === null
            || (float) $origin->nota_obtenida < (float) ($workerExam->nota_minima_snapshot ?? 0))) {
            throw ValidationException::withMessages(['nota' => 'El examen destino exige una nota registrada que alcance su nota minima.']);
        }

        $allowedOrigins = $workerExam->requisitoMina->minas_origen_convalidacion_json ?: [];
        if (!$workerExam->requisitoMina->convalidar_desde_otras_minas && $origin->asignacion?->mina_id !== $workerExam->asignacion?->mina_id) {
            throw ValidationException::withMessages(['convalidacion' => 'No se permite convalidar desde otra mina.']);
        }
        if (!empty($allowedOrigins) && !in_array($origin->asignacion?->mina_id, $allowedOrigins, true)) {
            throw ValidationException::withMessages(['convalidacion' => 'La mina origen no esta permitida para convalidacion.']);
        }

        $observation = collect([
            PersonalNormalizer::text($origin->observacion),
            PersonalNormalizer::text($payload['observacion'] ?? ''),
        ])->filter()->unique()->implode("\n");
        if (mb_strlen($observation) > 5000) {
            throw ValidationException::withMessages([
                'observacion' => 'La observacion completa supera el limite. Reduce el comentario adicional sin quitar las restricciones del examen origen.',
            ]);
        }

        $medicalContext = $origin->contexto_convalidacion_json ?? [];
        $medicalAttempt = $origin->intentos()->whereNotNull('emo_cita_id')->where('resultado', 'APROBADO')
            ->whereDate('fecha_realizacion', $origin->fecha_realizacion?->toDateString())
            ->orderByDesc('numero_intento')->lockForUpdate()->first();
        if ($medicalAttempt) {
            $medicalAppointment = $medicalAppointments->get($medicalAttempt->emo_cita_id);
            if ($medicalAppointment?->estado === PersonalEmoCita::ESTADO_COMPLETADA) {
                $medicalContext = [
                    'resultado_emo' => $medicalAppointment->resultado_emo,
                    'detalle_resultado_emo' => $medicalAppointment->detalle_resultado_emo,
                    'emo_cita_id' => $medicalAppointment->id, 'clinica_id' => $medicalAppointment->clinica_id,
                    'clinica_nombre_snapshot' => $medicalAppointment->clinica_nombre_snapshot,
                ];
            }
        }

        $workerExam->forceFill([
            'estado' => PersonalMinaExamen::ESTADO_CONVALIDADO,
            'resultado' => 'APROBADO',
            'nota_obtenida' => $origin->nota_obtenida,
            'contexto_convalidacion_json' => [
                'examen_origen_id' => $origin->examen_id,
                'examen_origen' => $origin->nombre_snapshot,
                'mina_origen_id' => $origin->asignacion?->mina_id,
                'mina_origen' => $origin->asignacion?->mina?->nombre,
                'examen_destino_id' => $workerExam->examen_id,
                'examen_destino' => $workerExam->nombre_snapshot,
                'mina_destino_id' => $workerExam->asignacion?->mina_id,
                'mina_destino' => $workerExam->asignacion?->mina?->nombre,
                'nota' => $origin->nota_obtenida,
                'observacion_origen' => $origin->observacion,
                'equivalente' => $origin->examen_id !== $workerExam->examen_id,
                ...\Illuminate\Support\Arr::only($medicalContext, [
                    'resultado_emo', 'detalle_resultado_emo', 'emo_cita_id', 'clinica_id', 'clinica_nombre_snapshot',
                ]),
            ],
            'fecha_realizacion' => $origin->fecha_realizacion,
            'fecha_vencimiento' => $origin->fecha_vencimiento,
            'es_convalidado' => true,
            'examen_origen_convalidado_id' => $origin->id,
            'mina_origen_convalidacion_id' => $origin->asignacion?->mina_id,
            'fecha_aprobacion_origen' => optional($origin->fecha_realizacion)->toDateString(),
            'fecha_convalidacion' => now(),
            'usuario_convalidacion_id' => $user->id,
            'observacion' => $observation ?: null,
            'usuario_actualizacion_id' => $user->id,
            'fecha_actualizacion' => now(),
        ])->save();

        $this->refreshAssignmentStatus($workerExam->asignacion, $user);

        return $workerExam->fresh(['asignacion', 'intentos']);
    }

    public function warningsFor(PersonalMina $relation): array
    {
        $personal = $relation->personal;
        if (!$personal) {
            return [];
        }

        $warnings = [];
        $laborState = strtoupper((string) $personal->estado);
        if ($laborState === 'CESADO') {
            $warnings[] = 'Trabajador cesado';
        }

        if (!$this->hasCurrentSignedContract($personal)) {
            $warnings[] = 'Sin contrato vigente firmado';
        }

        $current = $personal->contratoLaboralActual;
        if ($current && strtoupper((string) $current->estado) === PersonalContrato::ESTADO_PREPARACION) {
            $warnings[] = 'Contrato en preparacion';
        }
        if ($current && $current->fecha_fin && $current->fecha_fin->toDateString() < $this->operationalToday()->toDateString()) {
            $warnings[] = 'Contrato vencido';
        }

        return $warnings;
    }

    public function habilitationStateOptions(): array
    {
        return [
            PersonalMina::ESTADO_EN_PROCESO => 'En proceso',
            PersonalMina::ESTADO_HABILITADO => 'Habilitado',
            PersonalMina::ESTADO_NO_HABILITADO => 'No habilitado',
            PersonalMina::ESTADO_OBSERVADO => 'Observado',
            PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION => 'Finalizado por desaprobacion',
        ];
    }

    public function examStateOptions(): array
    {
        return [
            PersonalMinaExamen::ESTADO_PENDIENTE => 'Pendiente',
            PersonalMinaExamen::ESTADO_PROGRAMADO => 'Programado',
            PersonalMinaExamen::ESTADO_APROBADO => 'Aprobado',
            PersonalMinaExamen::ESTADO_DESAPROBADO => 'Desaprobado',
            PersonalMinaExamen::ESTADO_VIGENTE => 'Vigente',
            PersonalMinaExamen::ESTADO_POR_VENCER => 'Por vencer',
            PersonalMinaExamen::ESTADO_VENCIDO => 'Vencido',
            PersonalMinaExamen::ESTADO_NO_APLICA => 'No aplica',
            PersonalMinaExamen::ESTADO_OBSERVADO => 'Observado',
            PersonalMinaExamen::ESTADO_CONVALIDADO => 'Convalidado',
        ];
    }

    public function attemptResultOptions(): array
    {
        return [
            PersonalMinaExamenIntento::RESULTADO_PENDIENTE => 'Pendiente',
            PersonalMinaExamenIntento::RESULTADO_APROBADO => 'Aprobado',
            'APTO_CON_RESTRICCION' => 'Apto con restriccion',
            PersonalMinaExamenIntento::RESULTADO_DESAPROBADO => 'Desaprobado',
            PersonalMinaExamenIntento::RESULTADO_NO_ASISTIO => 'No asistio',
            PersonalMinaExamenIntento::RESULTADO_ANULADO => 'Anulado',
        ];
    }

    public function originCandidatesFor(PersonalMinaExamen $workerExam)
    {
        return PersonalMinaExamen::query()
            ->with(['asignacion.mina', 'asignacion.personal'])
            ->where('examen_id', $workerExam->examen_id)
            ->where('id', '!=', $workerExam->id)
            ->whereIn('estado', [
                PersonalMinaExamen::ESTADO_APROBADO,
                PersonalMinaExamen::ESTADO_VIGENTE,
                PersonalMinaExamen::ESTADO_POR_VENCER,
                PersonalMinaExamen::ESTADO_CONVALIDADO,
            ])
            ->orderByDesc('fecha_realizacion')
            ->limit(20)
            ->get()
            ->filter(fn (PersonalMinaExamen $item) => $this->isExamApprovedAndCurrent($item));
    }

    private function normalizeHabilitationState(mixed $value): string
    {
        $state = strtoupper(trim((string) $value));

        if (!array_key_exists($state, $this->habilitationStateOptions())) {
            throw ValidationException::withMessages(['estado_habilitacion' => 'Selecciona un estado de habilitacion valido.']);
        }

        return $state;
    }

    private function resolveExamForRequirement(array $payload, ?Usuario $user): ExamenMinero
    {
        $examId = trim((string) ($payload['examen_id'] ?? ''));
        if ($examId !== '') {
            $exam = ExamenMinero::query()->find($examId);
            if (!$exam || !$exam->activo) {
                throw ValidationException::withMessages(['examen_id' => 'Selecciona un examen minero valido.']);
            }

            return $exam;
        }

        $name = mb_substr(PersonalNormalizer::text($payload['nombre'] ?? ''), 0, 191);
        if ($name === '') {
            throw ValidationException::withMessages(['nombre' => 'El nombre del examen es obligatorio.']);
        }

        $duplicate = ExamenMinero::query()
            ->where('activo', true)
            ->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower(trim($name))])
            ->first();
        if ($duplicate) {
            return $duplicate;
        }

        $allowsRetry = filter_var($payload['permite_reintento'] ?? $payload['reprogramable'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $maxAttempts = $this->positiveIntegerOrNull($payload['max_intentos'] ?? null) ?: ($allowsRetry ? 2 : 1);
        $maxAttempts = min(2, max(1, $maxAttempts));
        if (!$allowsRetry) {
            $maxAttempts = 1;
        }
        if ($allowsRetry && $maxAttempts < 2) {
            $allowsRetry = false;
        }

        $requiresScore = filter_var($payload['requiere_nota'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $validityDays = $this->positiveIntegerOrNull($payload['vigencia_dias'] ?? null);
        $hasValidity = filter_var($payload['tiene_vigencia'] ?? ($validityDays !== null), FILTER_VALIDATE_BOOLEAN);
        if ($hasValidity && !$validityDays) {
            throw ValidationException::withMessages(['vigencia_dias' => 'La vigencia en dias es obligatoria cuando el examen tiene vencimiento.']);
        }
        if ($requiresScore && (($payload['nota_minima'] ?? null) === null || ($payload['nota_minima'] ?? '') === '')) {
            throw ValidationException::withMessages(['nota_minima' => 'La nota minima es obligatoria cuando el examen contiene nota minima.']);
        }
        $requiresPlace = filter_var($payload['requiere_lugar'] ?? (($payload['lugar'] ?? '') !== ''), FILTER_VALIDATE_BOOLEAN);
        if ($requiresPlace && trim((string) ($payload['lugar'] ?? '')) === '') {
            throw ValidationException::withMessages(['lugar' => 'El lugar es obligatorio cuando el examen se toma en un lugar especifico.']);
        }
        $companyPays = filter_var($payload['empresa_paga'] ?? (($payload['precio'] ?? '') !== ''), FILTER_VALIDATE_BOOLEAN);
        if ($companyPays && (($payload['precio'] ?? null) === null || ($payload['precio'] ?? '') === '')) {
            throw ValidationException::withMessages(['precio' => 'El precio es obligatorio cuando la empresa paga el examen.']);
        }
        if (($payload['precio'] ?? null) !== null && ($payload['precio'] ?? '') !== '' && (float) $payload['precio'] < 0) {
            throw ValidationException::withMessages(['precio' => 'El precio del examen no puede ser negativo.']);
        }

        return ExamenMinero::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => $name,
            'descripcion' => PersonalNormalizer::text($payload['descripcion'] ?? '') ?: null,
            'tipo' => mb_substr(PersonalNormalizer::text($payload['tipo'] ?? ''), 0, 80) ?: null,
            'requiere_lugar' => $requiresPlace,
            'lugar' => mb_substr(PersonalNormalizer::text($payload['lugar'] ?? ''), 0, 191) ?: null,
            'empresa_paga' => $companyPays,
            'precio' => ($payload['precio'] ?? null) !== null && ($payload['precio'] ?? '') !== '' ? (float) $payload['precio'] : null,
            'moneda' => mb_substr(strtoupper(PersonalNormalizer::text($payload['moneda'] ?? 'PEN')), 0, 10) ?: 'PEN',
            'precio_desde' => PersonalNormalizer::isoDate($payload['precio_desde'] ?? null),
            'tiene_vigencia' => $hasValidity,
            'vigencia_dias' => $validityDays,
            'permite_reintento' => $allowsRetry,
            'max_intentos' => $maxAttempts,
            'critico' => filter_var($payload['critico'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'desaprueba_finaliza_proceso' => filter_var($payload['desaprueba_finaliza_proceso'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'requiere_nota' => $requiresScore,
            'nota_minima' => $requiresScore && ($payload['nota_minima'] ?? null) !== null && ($payload['nota_minima'] ?? '') !== '' ? (float) $payload['nota_minima'] : null,
            'solo_resultado' => !$requiresScore,
            'permite_convalidacion' => filter_var($payload['permite_convalidacion'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'observacion' => PersonalNormalizer::text($payload['observacion'] ?? '') ?: null,
            'activo' => true,
            'orden' => $this->positiveIntegerOrNull($payload['orden'] ?? null) ?? 0,
            'created_by_usuario_id' => $user?->id,
            'updated_by_usuario_id' => $user?->id,
        ]);
    }

    private function normalizedExamPayload(array $payload): array
    {
        $allowsRetry = filter_var($payload['permite_reintento'] ?? $payload['reprogramable'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $maxAttempts = $this->positiveIntegerOrNull($payload['max_intentos'] ?? null) ?: ($allowsRetry ? 2 : 1);
        $maxAttempts = min(2, max(1, $maxAttempts));
        if (!$allowsRetry) {
            $maxAttempts = 1;
        }
        if ($allowsRetry && $maxAttempts < 2) {
            $allowsRetry = false;
        }

        $requiresScore = filter_var($payload['requiere_nota'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $validityDays = $this->positiveIntegerOrNull($payload['vigencia_dias'] ?? null);
        $hasValidity = filter_var($payload['tiene_vigencia'] ?? ($validityDays !== null), FILTER_VALIDATE_BOOLEAN);
        if ($hasValidity && !$validityDays) {
            throw ValidationException::withMessages(['vigencia_dias' => 'La vigencia en dias es obligatoria cuando el examen tiene vencimiento.']);
        }
        if ($requiresScore && (($payload['nota_minima'] ?? null) === null || ($payload['nota_minima'] ?? '') === '')) {
            throw ValidationException::withMessages(['nota_minima' => 'La nota minima es obligatoria cuando el examen contiene nota minima.']);
        }

        $requiresPlace = filter_var($payload['requiere_lugar'] ?? (($payload['lugar'] ?? '') !== ''), FILTER_VALIDATE_BOOLEAN);
        if ($requiresPlace && trim((string) ($payload['lugar'] ?? '')) === '') {
            throw ValidationException::withMessages(['lugar' => 'El lugar es obligatorio cuando el examen se toma en un lugar especifico.']);
        }

        $companyPays = filter_var($payload['empresa_paga'] ?? (($payload['precio'] ?? '') !== ''), FILTER_VALIDATE_BOOLEAN);
        if ($companyPays && (($payload['precio'] ?? null) === null || ($payload['precio'] ?? '') === '')) {
            throw ValidationException::withMessages(['precio' => 'El precio es obligatorio cuando la empresa paga el examen.']);
        }
        if (($payload['precio'] ?? null) !== null && ($payload['precio'] ?? '') !== '' && (float) $payload['precio'] < 0) {
            throw ValidationException::withMessages(['precio' => 'El precio del examen no puede ser negativo.']);
        }

        return [
            'nombre' => mb_substr(PersonalNormalizer::text($payload['nombre'] ?? ''), 0, 191),
            'descripcion' => PersonalNormalizer::text($payload['descripcion'] ?? '') ?: null,
            'tipo' => mb_substr(PersonalNormalizer::text($payload['tipo'] ?? ''), 0, 80) ?: null,
            'requiere_lugar' => $requiresPlace,
            'lugar' => mb_substr(PersonalNormalizer::text($payload['lugar'] ?? ''), 0, 191) ?: null,
            'empresa_paga' => $companyPays,
            'precio' => ($payload['precio'] ?? null) !== null && ($payload['precio'] ?? '') !== '' ? (float) $payload['precio'] : null,
            'moneda' => mb_substr(strtoupper(PersonalNormalizer::text($payload['moneda'] ?? 'PEN')), 0, 10) ?: 'PEN',
            'precio_desde' => PersonalNormalizer::isoDate($payload['precio_desde'] ?? null),
            'tiene_vigencia' => $hasValidity,
            'vigencia_dias' => $validityDays,
            'permite_reintento' => $allowsRetry,
            'max_intentos' => $maxAttempts,
            'critico' => filter_var($payload['critico'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'desaprueba_finaliza_proceso' => filter_var($payload['desaprueba_finaliza_proceso'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'requiere_nota' => $requiresScore,
            'nota_minima' => $requiresScore && ($payload['nota_minima'] ?? null) !== null && ($payload['nota_minima'] ?? '') !== '' ? (float) $payload['nota_minima'] : null,
            'permite_convalidacion' => filter_var($payload['permite_convalidacion'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'observacion' => PersonalNormalizer::text($payload['observacion'] ?? '') ?: null,
            'activo' => filter_var($payload['activo'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'orden' => $this->positiveIntegerOrNull($payload['orden'] ?? null) ?? 0,
        ];
    }

    private function examSnapshotPayload(PersonalMina $relation, MinaRequisito $requirement, ExamenMinero $exam, Usuario $user): array
    {
        $validityDays = $requirement->vigencia_dias_override ?: $requirement->vigencia_dias ?: $exam->vigencia_dias;
        $allowsRetry = (bool) $exam->permite_reintento;
        $maxAttempts = $allowsRetry ? min(2, max(1, (int) ($exam->max_intentos ?: 2))) : 1;

        return [
            'id' => (string) Str::uuid(),
            'personal_mina_id' => $relation->id,
            'mina_requisito_id' => $requirement->id,
            'examen_id' => $exam->id,
            'nombre_snapshot' => $exam->nombre,
            'lugar_snapshot' => $exam->lugar,
            'precio_snapshot' => $exam->precio,
            'tiene_vigencia_snapshot' => (bool) $exam->tiene_vigencia,
            'vigencia_dias_snapshot' => $validityDays,
            'obligatorio_snapshot' => (bool) $requirement->obligatorio,
            'critico_snapshot' => (bool) ($requirement->critico || $exam->critico || $exam->desaprueba_finaliza_proceso),
            'permite_reintento_snapshot' => $allowsRetry,
            'max_intentos_snapshot' => $maxAttempts,
            'requiere_nota_snapshot' => (bool) $exam->requiere_nota,
            'nota_minima_snapshot' => $exam->nota_minima,
            'estado' => PersonalMinaExamen::ESTADO_PENDIENTE,
            'usuario_actualizacion_id' => $user->id,
            'fecha_actualizacion' => now(),
        ];
    }

    public function preservesCurrentApproval(PersonalMinaExamen $workerExam, string $incomingResult): bool
    {
        return $incomingResult !== PersonalMinaExamenIntento::RESULTADO_APROBADO
            && $workerExam->resultado === PersonalMinaExamenIntento::RESULTADO_APROBADO
            && in_array($workerExam->estado, [
                PersonalMinaExamen::ESTADO_APROBADO,
                PersonalMinaExamen::ESTADO_VIGENTE,
                PersonalMinaExamen::ESTADO_POR_VENCER,
                PersonalMinaExamen::ESTADO_CONVALIDADO,
            ], true)
            && (!$workerExam->fecha_vencimiento
                || $workerExam->fecha_vencimiento->toDateString() >= $this->operationalToday()->toDateString());
    }

    public function clearPreviousConvalidation(PersonalMinaExamen $workerExam, Usuario $user): void
    {
        if (!$workerExam->es_convalidado && !$workerExam->contexto_convalidacion_json) {
            return;
        }

        $snapshot = \Illuminate\Support\Arr::only($workerExam->attributesToArray(), [
            'id', 'examen_id', 'nombre_snapshot', 'resultado', 'nota_obtenida',
            'fecha_realizacion', 'fecha_vencimiento', 'observacion', 'es_convalidado',
            'examen_origen_convalidado_id', 'mina_origen_convalidacion_id',
            'fecha_aprobacion_origen', 'fecha_convalidacion', 'usuario_convalidacion_id',
            'contexto_convalidacion_json',
        ]);
        $assignment = $workerExam->asignacion;
        $this->recordHistory($assignment, $assignment->estadoHabilitacionActual(), $assignment->estadoHabilitacionActual(),
            'Nueva realizacion: '.$workerExam->nombre_snapshot.'. Convalidacion anterior: '
                .json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $user);

        $workerExam->forceFill([
            'es_convalidado' => false,
            'examen_origen_convalidado_id' => null,
            'mina_origen_convalidacion_id' => null,
            'fecha_aprobacion_origen' => null,
            'fecha_convalidacion' => null,
            'usuario_convalidacion_id' => null,
            'contexto_convalidacion_json' => null,
        ])->save();
    }

    private function applyAttemptToExam(
        PersonalMinaExamen $workerExam,
        string $result,
        ?string $dateScheduled,
        ?string $dateDone,
        ?float $score,
        ?string $manualExpiration,
        array $payload,
        Usuario $user
    ): void {
        if ($this->preservesCurrentApproval($workerExam, $result)) {
            return;
        }

        $state = match ($result) {
            PersonalMinaExamenIntento::RESULTADO_APROBADO => PersonalMinaExamen::ESTADO_APROBADO,
            PersonalMinaExamenIntento::RESULTADO_DESAPROBADO => PersonalMinaExamen::ESTADO_DESAPROBADO,
            PersonalMinaExamenIntento::RESULTADO_NO_ASISTIO => PersonalMinaExamen::ESTADO_PROGRAMADO,
            default => $dateScheduled ? PersonalMinaExamen::ESTADO_PROGRAMADO : PersonalMinaExamen::ESTADO_PENDIENTE,
        };

        $expiration = $manualExpiration;
        if ($result === PersonalMinaExamenIntento::RESULTADO_APROBADO && !$expiration && $dateDone && $workerExam->tiene_vigencia_snapshot && $workerExam->vigencia_dias_snapshot) {
            $expiration = Carbon::parse($dateDone)->addDays((int) $workerExam->vigencia_dias_snapshot)->toDateString();
        }

        if ($result === PersonalMinaExamenIntento::RESULTADO_APROBADO) {
            $state = $this->stateForApprovedExam($expiration);
        }

        $expirationForExam = $expiration;
        if ($expirationForExam === null
            && $result !== PersonalMinaExamenIntento::RESULTADO_APROBADO
            && $this->expiredAttemptCycleCutoff($workerExam)
        ) {
            $expirationForExam = optional($workerExam->fecha_vencimiento)->toDateString();
        }

        $this->clearPreviousConvalidation($workerExam, $user);

        $workerExam->forceFill([
            'estado' => $state,
            'resultado' => $result,
            'nota_obtenida' => $score,
            'fecha_programacion' => $dateScheduled,
            'fecha_realizacion' => $dateDone,
            'fecha_vencimiento' => $expirationForExam,
            'observacion' => mb_substr(PersonalNormalizer::text($payload['observacion'] ?? ''), 0, 5000) ?: $workerExam->observacion,
            'usuario_actualizacion_id' => $user->id,
            'fecha_actualizacion' => now(),
        ])->save();
    }

    private function refreshAssignmentStatus(PersonalMina $relation, ?Usuario $user, bool $notify = true): bool
    {
        return DB::transaction(fn (): bool => $this->refreshAssignmentStatusLocked($relation, $user, $notify), 3);
    }

    private function refreshAssignmentStatusLocked(PersonalMina $relation, ?Usuario $user, bool $notify): bool
    {
        $relation = PersonalMina::query()->lockForUpdate()->find($relation->id);
        if (!$relation) {
            return false;
        }
        $relation->setRelation('examenes', $relation->examenes()->lockForUpdate()->get());

        $newState = $this->calculateAssignmentStatus($relation);
        $previous = $relation->estadoHabilitacionActual();

        if ($previous !== $newState) {
            $relation->forceFill([
                'estado' => $newState,
                'estado_habilitacion' => $newState,
                'fecha_habilitacion' => $newState === PersonalMina::ESTADO_HABILITADO ? $this->operationalToday()->toDateString() : null,
                'usuario_actualizacion_id' => $user?->id,
            ])->save();

            $this->recordHistory($relation, $previous, $newState, 'Calculo automatico por examenes mineros.', $user);
            if ($notify) {
                $this->operationalNotifications->habilitacionAsignacion(
                    $relation->fresh(['personal', 'mina']) ?: $relation,
                    $user,
                    $previous
                );
            }

            return true;
        }

        return false;
    }

    private function calculateAssignmentStatus(PersonalMina $relation, ?Collection $activeRequirements = null): string
    {
        $activeRequirements ??= MinaRequisito::query()
            ->where('mina_id', $relation->mina_id)
            ->where('activo', true)
            ->whereNotNull('examen_id')
            ->get(['id', 'examen_id', 'obligatorio']);
        if ($activeRequirements->isEmpty()) {
            return PersonalMina::ESTADO_EN_PROCESO;
        }

        $exams = $relation->examenes;
        if ($exams->isEmpty()) {
            return PersonalMina::ESTADO_EN_PROCESO;
        }

        foreach ($exams as $exam) {
            $this->refreshExamExpirationState($exam);
        }

        $mandatoryRequirements = $activeRequirements->where('obligatorio', true)->values();
        if ($mandatoryRequirements->isEmpty()) {
            return PersonalMina::ESTADO_HABILITADO;
        }

        $required = $relation->examenes->filter(function (PersonalMinaExamen $exam) use ($mandatoryRequirements): bool {
            return $mandatoryRequirements->contains(function (MinaRequisito $requirement) use ($exam): bool {
                return filled($exam->mina_requisito_id) && $exam->mina_requisito_id === $requirement->id
                    || filled($exam->examen_id) && $exam->examen_id === $requirement->examen_id;
            });
        });

        $hasMissingMandatoryExam = $mandatoryRequirements->contains(function (MinaRequisito $requirement) use ($required): bool {
            return !$required->contains(function (PersonalMinaExamen $exam) use ($requirement): bool {
                return filled($exam->mina_requisito_id) && $exam->mina_requisito_id === $requirement->id
                    || filled($exam->examen_id) && $exam->examen_id === $requirement->examen_id;
            });
        });
        if ($hasMissingMandatoryExam) {
            return PersonalMina::ESTADO_EN_PROCESO;
        }

        if ($required->contains(fn (PersonalMinaExamen $exam) => $exam->estado === PersonalMinaExamen::ESTADO_OBSERVADO)) {
            return PersonalMina::ESTADO_OBSERVADO;
        }

        foreach ($required as $exam) {
            if ($exam->estado === PersonalMinaExamen::ESTADO_DESAPROBADO) {
                if (!$this->hasAttemptsAvailable($exam)) {
                    return PersonalMina::ESTADO_NO_HABILITADO;
                }
            }
        }

        if ($required->contains(fn (PersonalMinaExamen $exam) => $exam->estado === PersonalMinaExamen::ESTADO_VENCIDO)) {
            return PersonalMina::ESTADO_EN_PROCESO;
        }

        $resolvedStates = [
            PersonalMinaExamen::ESTADO_APROBADO,
            PersonalMinaExamen::ESTADO_VIGENTE,
            PersonalMinaExamen::ESTADO_POR_VENCER,
            PersonalMinaExamen::ESTADO_CONVALIDADO,
            PersonalMinaExamen::ESTADO_NO_APLICA,
        ];

        return $required->every(fn (PersonalMinaExamen $exam) => in_array($exam->estado, $resolvedStates, true))
            ? PersonalMina::ESTADO_HABILITADO
            : PersonalMina::ESTADO_EN_PROCESO;
    }

    private function refreshExamExpirationState(PersonalMinaExamen $exam): void
    {
        if (!$exam->fecha_vencimiento || !in_array($exam->estado, [
            PersonalMinaExamen::ESTADO_APROBADO,
            PersonalMinaExamen::ESTADO_VIGENTE,
            PersonalMinaExamen::ESTADO_POR_VENCER,
            PersonalMinaExamen::ESTADO_CONVALIDADO,
        ], true)) {
            return;
        }

        $state = $this->stateForApprovedExam($exam->fecha_vencimiento->toDateString(), $exam->es_convalidado);
        if ($exam->estado !== $state) {
            $exam->forceFill(['estado' => $state])->save();
        }
    }

    private function stateForApprovedExam(?string $expiration, bool $convalidated = false): string
    {
        if (!$expiration) {
            return $convalidated ? PersonalMinaExamen::ESTADO_CONVALIDADO : PersonalMinaExamen::ESTADO_APROBADO;
        }

        $today = $this->operationalToday();
        $end = Carbon::parse($expiration, $today->getTimezone());
        if ($end->lt($today)) {
            return PersonalMinaExamen::ESTADO_VENCIDO;
        }
        if ($today->diffInDays($end, false) <= 30) {
            return PersonalMinaExamen::ESTADO_POR_VENCER;
        }

        return $convalidated ? PersonalMinaExamen::ESTADO_CONVALIDADO : PersonalMinaExamen::ESTADO_VIGENTE;
    }

    private function hasAttemptsAvailable(PersonalMinaExamen $exam): bool
    {
        return $this->consumedCurrentCycleAttempts($exam)->count() < $this->effectiveMaxAttempts($exam);
    }

    public function operationalAttemptCount(PersonalMinaExamen $exam): int
    {
        if (in_array($exam->estado, [
            PersonalMinaExamen::ESTADO_APROBADO,
            PersonalMinaExamen::ESTADO_VIGENTE,
            PersonalMinaExamen::ESTADO_POR_VENCER,
            PersonalMinaExamen::ESTADO_CONVALIDADO,
            PersonalMinaExamen::ESTADO_NO_APLICA,
        ], true)) {
            return 0;
        }

        return $this->consumedCurrentCycleAttempts($exam)->count();
    }

    public function operationalAttemptNumber(PersonalMinaExamenIntento $attempt): ?int
    {
        if (!in_array($attempt->resultado, [
            PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
            PersonalMinaExamenIntento::RESULTADO_NO_ASISTIO,
        ], true)) {
            return null;
        }

        $exam = $attempt->relationLoaded('examenTrabajador')
            ? $attempt->examenTrabajador
            : $attempt->examenTrabajador()->with('intentos')->first();
        if (!$exam) {
            return null;
        }

        $ids = $this->consumedCurrentCycleAttempts($exam)->pluck('id')->values();
        $index = $ids->search($attempt->id);

        return $index === false ? null : $index + 1;
    }

    private function consumedCurrentCycleAttempts(PersonalMinaExamen $exam): Collection
    {
        return $this->currentCycleAttempts($exam)
            ->whereIn('resultado', [
                PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
                PersonalMinaExamenIntento::RESULTADO_NO_ASISTIO,
            ])->values();
    }

    private function currentCycleAttempts(PersonalMinaExamen $exam)
    {
        $attempts = $exam->relationLoaded('intentos')
            ? $exam->intentos
            : PersonalMinaExamenIntento::query()
                ->where('personal_mina_examen_id', $exam->id)
                ->orderBy('numero_intento')
                ->get();

        $attempts = $attempts
            ->where('resultado', '!=', PersonalMinaExamenIntento::RESULTADO_ANULADO)
            ->values();

        if ($exam->intentos_ciclo_numero_desde !== null) {
            $attempts = $attempts
                ->where('numero_intento', '>=', (int) $exam->intentos_ciclo_numero_desde)
                ->values();
        }

        $cutoff = $this->attemptCycleCutoff($exam);
        if (!$cutoff) {
            return $attempts;
        }

        return $attempts
            ->filter(function (PersonalMinaExamenIntento $attempt) use ($cutoff): bool {
                $date = $this->attemptOperationalDate($attempt);

                return $date !== null && $date->gt($cutoff);
            })
            ->values();
    }

    private function nextStoredAttemptNumber(PersonalMinaExamen $exam): int
    {
        // Callers lock the assignment/exam; a locking read also sees commits newer than the transaction snapshot.
        return ((int) PersonalMinaExamenIntento::query()
            ->where('personal_mina_examen_id', $exam->id)
            ->orderByDesc('numero_intento')
            ->lockForUpdate()
            ->value('numero_intento')) + 1;
    }

    private function deleteFailedAttemptFile(?array $storedFile): void
    {
        if (filled($storedFile['path'] ?? null)) {
            Storage::disk('local')->delete($storedFile['path']);
        }
    }

    private function rethrowAttemptNumberConflict(\Throwable $exception): void
    {
        if ($exception instanceof QueryException
            && (int) ($exception->errorInfo[1] ?? 0) === 1062
            && str_contains($exception->getMessage(), 'uq_examen_intento_numero')) {
            throw ValidationException::withMessages([
                'examen' => 'Se registro otro intento para este examen. Actualiza la pagina y vuelve a revisar el historial.',
            ]);
        }
    }

    private function expiredAttemptCycleCutoff(PersonalMinaExamen $exam): ?Carbon
    {
        if (!$exam->fecha_vencimiento) {
            return null;
        }

        $today = $this->operationalToday();
        $expiration = Carbon::parse($exam->fecha_vencimiento->toDateString(), $today->getTimezone())->endOfDay();
        $isExpiredByDate = $expiration->lt($today);
        $isExpiredByState = strtoupper((string) $exam->estado) === PersonalMinaExamen::ESTADO_VENCIDO;

        return $isExpiredByDate || $isExpiredByState ? $expiration : null;
    }

    private function attemptCycleCutoff(PersonalMinaExamen $exam): ?Carbon
    {
        $expiration = $this->expiredAttemptCycleCutoff($exam);
        if ($exam->intentos_ciclo_numero_desde !== null) {
            return $expiration;
        }
        $explicit = $exam->intentos_ciclo_desde
            ? Carbon::parse($exam->intentos_ciclo_desde, config('app.display_timezone', 'America/Lima'))
            : null;

        if (!$expiration) {
            return $explicit;
        }
        if (!$explicit) {
            return $expiration;
        }

        return $explicit->gt($expiration) ? $explicit : $expiration;
    }

    private function attemptOperationalDate(PersonalMinaExamenIntento $attempt): ?Carbon
    {
        $date = $attempt->fecha_realizacion ?: $attempt->fecha_programacion;
        if ($date) {
            return Carbon::parse($date->toDateString(), config('app.display_timezone', 'America/Lima'));
        }

        return $attempt->created_at?->copy()->setTimezone(config('app.display_timezone', 'America/Lima'));
    }

    private function isExamApprovedAndCurrent(PersonalMinaExamen $exam): bool
    {
        $this->refreshExamExpirationState($exam);
        if (!in_array($exam->estado, [
            PersonalMinaExamen::ESTADO_APROBADO,
            PersonalMinaExamen::ESTADO_VIGENTE,
            PersonalMinaExamen::ESTADO_POR_VENCER,
            PersonalMinaExamen::ESTADO_CONVALIDADO,
        ], true)) {
            return false;
        }

        $state = $this->stateForApprovedExam(optional($exam->fecha_vencimiento)->toDateString(), (bool) $exam->es_convalidado);

        return in_array($state, [
            PersonalMinaExamen::ESTADO_APROBADO,
            PersonalMinaExamen::ESTADO_VIGENTE,
            PersonalMinaExamen::ESTADO_POR_VENCER,
            PersonalMinaExamen::ESTADO_CONVALIDADO,
        ], true);
    }

    private function effectiveMaxAttempts(PersonalMinaExamen $exam): int
    {
        if (!$exam->permite_reintento_snapshot) {
            return 1;
        }

        $configured = (int) ($exam->max_intentos_snapshot ?: 2);

        return min(2, max(1, $configured));
    }

    private function normalizeAttemptResult(mixed $value): string
    {
        $result = strtoupper(trim((string) $value));
        if (!array_key_exists($result, $this->attemptResultOptions())) {
            throw ValidationException::withMessages(['resultado' => 'Selecciona un resultado valido.']);
        }

        return $result;
    }

    private function validateRealizationDate(?string $dateDone, string $result): void
    {
        if ($result === PersonalMinaExamenIntento::RESULTADO_APROBADO && !$dateDone) {
            throw ValidationException::withMessages(['fecha_realizacion' => 'Indica la fecha en que se realizo el examen aprobado.']);
        }

        if ($dateDone && $dateDone > $this->operationalToday()->toDateString()) {
            throw ValidationException::withMessages(['fecha_realizacion' => 'La fecha de realizacion no puede ser futura.']);
        }
    }

    private function storeAttemptFile(PersonalMinaExamen $workerExam, UploadedFile $file): array
    {
        $path = $file->storeAs(
            'habilitacion-minera/' . $workerExam->personal_mina_id,
            Str::uuid() . '.' . ($file->getClientOriginalExtension() ?: 'bin'),
            'local',
        );
        if (!$path) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo guardar el archivo del examen. Intenta nuevamente.']);
        }

        return [
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    public function resolveAttemptPriceSnapshot(PersonalMinaExamen $workerExam, ?string $registrationDate = null, ?string $dateScheduled = null, ?string $dateDone = null, bool $currentRead = false): array
    {
        $date = $registrationDate ?: $dateScheduled ?: $dateDone ?: $this->operationalToday()->toDateString();
        $catalogExam = $workerExam->relationLoaded('examen')
            ? $workerExam->examen
            : ExamenMinero::query()->find($workerExam->examen_id);
        if ($catalogExam && !(bool) $catalogExam->empresa_paga) {
            return [
                'precio' => null,
                'moneda' => null,
                'fecha' => $date,
                'fuente' => 'empresa_no_paga',
            ];
        }

        $history = null;
        if (Schema::hasTable('examen_minero_precios')) {
            $history = ExamenMineroPrecio::query()
                ->where('examen_id', $workerExam->examen_id)
                ->where('fecha_inicio', '<=', $date)
                ->where(function ($query) use ($date): void {
                    $query->whereNull('fecha_fin')
                        ->orWhere('fecha_fin', '>=', $date);
                })
                ->orderByDesc('fecha_inicio')
                ->when($currentRead, fn ($query) => $query->sharedLock())
                ->first();
        }

        if ($history) {
            return [
                'precio' => $history->precio,
                'moneda' => $history->moneda,
                'fecha' => $date,
                'fuente' => 'historial_precio',
            ];
        }

        if ($workerExam->precio_snapshot !== null) {
            return [
                'precio' => $workerExam->precio_snapshot,
                'moneda' => 'PEN',
                'fecha' => $date,
                'fuente' => 'snapshot_examen',
            ];
        }

        return [
            'precio' => null,
            'moneda' => null,
            'fecha' => $date,
            'fuente' => null,
        ];
    }

    private function boardColorReason(string $state, array $summary = []): string
    {
        return match ($state) {
            PersonalMina::ESTADO_HABILITADO => 'Trabajador habilitado en esta mina.',
            PersonalMina::ESTADO_NO_HABILITADO => 'Tiene una desaprobacion que alcanzo el maximo de intentos.',
            PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION => 'Proceso finalizado por desaprobacion.',
            PersonalMina::ESTADO_OBSERVADO => 'Tiene examenes observados por revisar.',
            PersonalMina::ESTADO_EN_PROCESO => ($summary['vencidos'] ?? 0) > 0
                ? 'En regularizacion: tiene examenes vencidos por actualizar.'
                : (($summary['desaprobados'] ?? 0) > 0
                    ? 'En proceso: tiene examenes desaprobados y puede continuar.'
                    : 'Proceso iniciado; tiene requisitos pendientes.'),
            'ASIGNADO_PENDIENTE_INICIO' => 'Asignado - pendiente de iniciar examenes.',
            default => 'Proceso iniciado o requiere revision.',
        };
    }

    private function boardLabelForState(string $state): string
    {
        return match ($state) {
            'ASIGNADO_PENDIENTE_INICIO' => 'Asignado',
            'BLOQUEADA' => 'Bloqueada',
            'NEUTRO' => 'Disponible',
            default => $this->habilitationStateOptions()[$state] ?? $state,
        };
    }

    private function blockingReasonForMine(Personal $worker, Mina $mine): ?string
    {
        // A failed result keeps the mine non-enabled, but no longer prevents a
        // new assignment or another attempt. The configured maximum is a visible
        // operational reference, not a hard stop.
        return null;
    }

    private function normalizeMineOriginList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->values()
            ->all();
    }

    private function assertCeasedWorkerConfirmation(Personal $personal, string $state, array $payload): void
    {
        $isCeased = strtoupper((string) $personal->estado) === 'CESADO';
        $confirmed = filter_var($payload['confirmar_trabajador_cesado'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($isCeased && $state === PersonalMina::ESTADO_HABILITADO && !$confirmed) {
            throw ValidationException::withMessages([
                'confirmar_trabajador_cesado' => 'El trabajador esta cesado. Confirma antes de marcarlo como habilitado.',
            ]);
        }
    }

    private function recordHistory(PersonalMina $relation, ?string $previous, string $new, ?string $observation, ?Usuario $user): void
    {
        if (!Schema::hasTable('personal_mina_historial')) {
            return;
        }

        PersonalMinaHistorial::query()->create([
            'id' => (string) Str::uuid(),
            'personal_mina_id' => $relation->id,
            'estado_anterior' => $previous,
            'estado_nuevo' => $new,
            'observacion' => $observation,
            'usuario_id' => $user?->id,
            'fecha_cambio' => now(),
        ]);
    }

    private function hasCurrentSignedContract(Personal $personal): bool
    {
        if (!Schema::hasTable('personal_contratos')) {
            return false;
        }

        return PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->where('estado', PersonalContrato::ESTADO_ACTIVO)
            ->whereNotNull('signed_at')
            ->whereNotNull('signed_contract_path')
            ->where(function ($query): void {
                $query->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $this->operationalToday()->toDateString());
            })
            ->exists();
    }

    private function positiveIntegerOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }
}
