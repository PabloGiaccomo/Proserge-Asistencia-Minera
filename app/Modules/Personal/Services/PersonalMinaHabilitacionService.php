<?php

namespace App\Modules\Personal\Services;

use App\Models\ExamenMinero;
use App\Models\ExamenMineroPrecio;
use App\Models\Mina;
use App\Models\MinaRequisito;
use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalCursoRegistro;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Models\PersonalMina;
use App\Models\PersonalMinaHistorial;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Modules\Personal\Support\PersonalNormalizer;
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
                'examenes.intentos',
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
                    'examenes.intentos',
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

        $today = Carbon::today();
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
                $expiresAt = $first->fecha_vencimiento ? Carbon::parse($first->fecha_vencimiento) : null;

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

        $today = Carbon::today();
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
                $scheduledAt = $firstAttempt->fecha_programacion ? Carbon::parse($firstAttempt->fecha_programacion) : null;

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
                    'fecha_inicio' => $payload['precio_desde'] ?? Carbon::today()->toDateString(),
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
                    'fecha_inicio' => $normalized['precio_desde'] ?: Carbon::today()->toDateString(),
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
            $existing = PersonalMina::query()
                ->where('personal_id', $personal->id)
                ->where('mina_id', $mina->id)
                ->first();

            if ($existing && (bool) ($existing->activo ?? true)) {
                throw ValidationException::withMessages([
                    'mina_id' => 'El trabajador ya tiene una asignacion activa para esta mina.',
                ]);
            }

            $date = PersonalNormalizer::isoDate($payload['fecha_asignacion'] ?? null) ?: Carbon::today()->toDateString();
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
                    ? (PersonalNormalizer::isoDate($payload['fecha_habilitacion'] ?? null) ?: Carbon::today()->toDateString())
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
        $relation = PersonalMina::query()
            ->with('personal')
            ->findOrFail($relation->id);

        if (!(bool) ($relation->activo ?? true)) {
            throw ValidationException::withMessages(['asignacion' => 'La asignacion esta inactiva. Reactivala antes de cambiar su estado.']);
        }

        $state = $this->normalizeHabilitationState($payload['estado_habilitacion'] ?? $relation->estadoHabilitacionActual());
        $this->assertCeasedWorkerConfirmation($relation->personal, $state, $payload);
        $previousState = $relation->estadoHabilitacionActual();
        $observation = mb_substr(PersonalNormalizer::text($payload['observacion'] ?? $relation->observacion ?? ''), 0, 5000) ?: null;

        if ($state === PersonalMina::ESTADO_HABILITADO) {
            $calculated = $this->calculatedAssignmentStatus($relation);

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
                ? (PersonalNormalizer::isoDate($payload['fecha_habilitacion'] ?? null) ?: optional($relation->fecha_habilitacion)->toDateString() ?: Carbon::today()->toDateString())
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

        return $relation->fresh(['personal.contratoLaboralActual', 'mina', 'actualizadoPor.personal', 'historial.usuario.personal']);
    }

    public function deactivateAssignment(PersonalMina $relation, Usuario $user, ?string $observation = null): PersonalMina
    {
        $relation = PersonalMina::query()->findOrFail($relation->id);
        $previousState = $relation->estadoHabilitacionActual();

        $relation->forceFill([
            'activo' => false,
            'observacion' => mb_substr(PersonalNormalizer::text($observation ?? $relation->observacion ?? ''), 0, 5000) ?: $relation->observacion,
            'usuario_actualizacion_id' => $user->id,
        ])->save();

        $this->recordHistory($relation, $previousState, $previousState, 'Asignacion desactivada. ' . trim((string) $observation), $user);

        return $relation->fresh(['personal', 'mina']);
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

        $relation = PersonalMina::query()->with('mina')->findOrFail($relation->id);
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

            $exists = PersonalMinaExamen::query()
                ->where('personal_mina_id', $relation->id)
                ->where('examen_id', $exam->id)
                ->exists();
            if ($exists) {
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
        if (!Schema::hasColumn('personal_mina_examen_intentos', 'personal_curso_registro_id')) {
            throw ValidationException::withMessages([
                'archivo' => 'Falta instalar la migracion que vincula cursos con habilitacion minera.',
            ]);
        }

        $mineIds = MinaRequisito::query()
            ->where('examen_id', $record->examen_id)
            ->where('activo', true)
            ->when($record->mina_id, fn ($query) => $query->where('mina_id', $record->mina_id))
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
            ->with(['examenes.intentos', 'mina'])
            ->where('personal_id', $record->personal_id)
            ->where('activo', true)
            ->whereIn('mina_id', $mineIds)
            ->whereHas('mina', fn ($query) => $query->activeOperational())
            ->lockForUpdate()
            ->get();

        $updated = 0;
        $generated = 0;
        foreach ($assignments as $assignment) {
            $workerExam = $assignment->examenes->firstWhere('examen_id', $record->examen_id);
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
            || !Schema::hasColumn('personal_mina_examen_intentos', 'personal_curso_registro_id')) {
            return;
        }

        $examIds = PersonalMinaExamen::query()
            ->where('personal_mina_id', $assignment->id)
            ->pluck('examen_id')
            ->filter()
            ->unique();
        if ($examIds->isEmpty()) {
            return;
        }

        $records = PersonalCursoRegistro::query()
            ->where('personal_id', $assignment->personal_id)
            ->whereIn('examen_id', $examIds)
            ->when(Schema::hasColumn('personal_curso_registros', 'mina_id'),
                fn ($query) => $query->where(function ($mineQuery) use ($assignment): void {
                    $mineQuery->where('mina_id', $assignment->mina_id)
                        ->orWhereNull('mina_id');
                }))
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
            Carbon::today()->toDateString(),
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

    public function refreshAssignmentFromExams(PersonalMina $relation, Usuario $user, bool $notify = true): bool
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
                ->where('fecha_inicio', optional($exam->precio_desde)->toDateString() ?: Carbon::today()->toDateString())
                ->exists();
            if (!$exists) {
                $this->storeExamPrice($exam, [
                    'precio' => $exam->precio,
                    'moneda' => $exam->moneda ?: 'PEN',
                    'fecha_inicio' => optional($exam->precio_desde)->toDateString() ?: Carbon::today()->toDateString(),
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
                                    ? ($fresh->fecha_habilitacion ?: Carbon::today()->toDateString())
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
            ->with(['personal.contratoLaboralActual', 'mina', 'examenes.intentos', 'examenes.requisitoMina', 'examenes.examen'])
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
        $payload = \App\Modules\Personal\Support\CursoResultado::payload($payload);
        $workerExam = PersonalMinaExamen::query()
            ->with(['asignacion', 'intentos'])
            ->findOrFail($workerExam->id);

        $isExpired = $this->expiredAttemptCycleCutoff($workerExam) !== null;

        if (!$isExpired && in_array(strtoupper((string) $workerExam->estado), [
            PersonalMinaExamen::ESTADO_NO_APLICA,
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

        if ($result === PersonalMinaExamenIntento::RESULTADO_PENDIENTE && $dateScheduled) {
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
            DB::transaction(function () use ($workerExam, $dateScheduled, $dateDone, $result, $score, $payload, $file, &$storedFile, $manualExpiration, $user): void {
                $lockedExam = PersonalMinaExamen::query()->whereKey($workerExam->id)->lockForUpdate()->firstOrFail();
                $lockedExam->load(['asignacion', 'intentos']);
                if ($this->expiredAttemptCycleCutoff($lockedExam) === null && in_array($lockedExam->estado, [
                    PersonalMinaExamen::ESTADO_NO_APLICA,
                    PersonalMinaExamen::ESTADO_CONVALIDADO,
                    PersonalMinaExamen::ESTADO_VIGENTE,
                    PersonalMinaExamen::ESTADO_APROBADO,
                ], true)) {
                    throw ValidationException::withMessages(['examen' => 'Este examen ya fue resuelto.']);
                }
                if ($result === PersonalMinaExamenIntento::RESULTADO_PENDIENTE && $dateScheduled
                    && $this->currentCycleAttempts($lockedExam)
                        ->where('resultado', PersonalMinaExamenIntento::RESULTADO_PENDIENTE)
                        ->contains(fn (PersonalMinaExamenIntento $attempt): bool => filled($attempt->fecha_programacion))) {
                    throw ValidationException::withMessages(['fecha_programacion' => 'Este examen ya tiene una programacion pendiente.']);
                }

                $storedFile = $file ? $this->storeAttemptFile($lockedExam, $file) : null;
                $storedAttemptNumber = $this->nextStoredAttemptNumber($lockedExam);
                $priceSnapshot = $this->resolveAttemptPriceSnapshot($lockedExam, Carbon::today()->toDateString(), $dateScheduled, $dateDone);

                PersonalMinaExamenIntento::query()->create([
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
            });
        } catch (\Throwable $exception) {
            $this->deleteFailedAttemptFile($storedFile);
            $this->rethrowAttemptNumberConflict($exception);
            throw $exception;
        }

        $updated = $workerExam->fresh(['asignacion.personal', 'asignacion.mina', 'intentos']);
        if ($updated && $result === PersonalMinaExamenIntento::RESULTADO_PENDIENTE && $dateScheduled) {
            $this->operationalNotifications->examenProgramado($updated, $user);
        }

        return $updated;
    }

    public function completeScheduledAttempt(PersonalMinaExamenIntento $attempt, array $payload, ?UploadedFile $file, Usuario $user): PersonalMinaExamen
    {
        $payload = \App\Modules\Personal\Support\CursoResultado::payload($payload);
        $attempt = PersonalMinaExamenIntento::query()
            ->with('examenTrabajador.asignacion')
            ->findOrFail($attempt->id);
        $workerExam = PersonalMinaExamen::query()
            ->with(['asignacion', 'intentos'])
            ->findOrFail($attempt->personal_mina_examen_id);

        if ($attempt->resultado !== PersonalMinaExamenIntento::RESULTADO_PENDIENTE) {
            throw ValidationException::withMessages(['intento' => 'Este examen programado ya tiene resultado registrado.']);
        }

        if (!$attempt->fecha_programacion) {
            throw ValidationException::withMessages(['fecha_programacion' => 'Este intento no tiene fecha de programacion.']);
        }

        if ($attempt->fecha_programacion->gt(Carbon::today())) {
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
                $lockedExam = PersonalMinaExamen::query()->whereKey($workerExam->id)->lockForUpdate()->firstOrFail();
                $lockedExam->load('asignacion');
                $lockedAttempt = PersonalMinaExamenIntento::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                if ($lockedAttempt->resultado !== PersonalMinaExamenIntento::RESULTADO_PENDIENTE) {
                    throw ValidationException::withMessages(['intento' => 'Este examen programado ya tiene resultado registrado.']);
                }
                if (!$lockedAttempt->fecha_programacion || $lockedAttempt->fecha_programacion->gt(Carbon::today())) {
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
                $priceSnapshot = $this->resolveAttemptPriceSnapshot($lockedExam, Carbon::today()->toDateString(), $dateScheduled, $dateDone);
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

    public function markExamNotApplicable(PersonalMinaExamen $workerExam, array $payload, Usuario $user): PersonalMinaExamen
    {
        $workerExam = PersonalMinaExamen::query()
            ->with(['asignacion', 'requisitoMina'])
            ->findOrFail($workerExam->id);

        if (!$workerExam->requisitoMina?->permite_no_aplica) {
            throw ValidationException::withMessages(['no_aplica' => 'Este examen no permite marcar no aplica.']);
        }

        $observation = mb_substr(PersonalNormalizer::text($payload['observacion'] ?? ''), 0, 5000) ?: null;

        $workerExam->forceFill([
            'estado' => PersonalMinaExamen::ESTADO_NO_APLICA,
            'resultado' => 'NO_APLICA',
            'observacion' => $observation,
            'usuario_actualizacion_id' => $user->id,
            'fecha_actualizacion' => now(),
        ])->save();

        $this->refreshAssignmentStatus($workerExam->asignacion, $user);

        return $workerExam->fresh(['asignacion', 'intentos']);
    }

    public function convalidationOptions(PersonalMinaExamen $workerExam): array
    {
        $workerExam = PersonalMinaExamen::query()
            ->with(['asignacion', 'requisitoMina', 'examen'])
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
        $today = Carbon::today()->toDateString();
        $origins = PersonalMinaExamen::query()
            ->with('asignacion.mina')
            ->where('examen_id', $workerExam->examen_id)
            ->where('id', '!=', $workerExam->id)
            ->whereHas('asignacion', fn ($query) => $query
                ->where('personal_id', $workerExam->asignacion->personal_id)
                ->where('id', '!=', $workerExam->personal_mina_id)
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
            ->orderByDesc('fecha_realizacion')
            ->limit(50)
            ->get()
            ->map(fn ($origin) => [
                'id' => $origin->id,
                'mina' => $origin->asignacion?->mina?->nombre,
                'fecha_realizacion' => optional($origin->fecha_realizacion)->format('d/m/Y'),
                'fecha_vencimiento' => optional($origin->fecha_vencimiento)->format('d/m/Y'),
                'estado' => $origin->estado,
            ])->values()->all();

        return [
            'available' => $origins !== [],
            'message' => $origins === [] ? 'No hay un resultado aprobado y vigente del mismo examen para este trabajador.' : null,
            'options' => $origins,
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

        $today = Carbon::today();
        $start = $workerExam->requisitoMina->fecha_inicio_convalidacion;
        $end = $workerExam->requisitoMina->fecha_fin_convalidacion;
        if (($start && $today->lt($start)) || ($end && $today->gt($end))) {
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
        $workerExam = PersonalMinaExamen::query()
            ->with(['asignacion.mina', 'requisitoMina', 'examen'])
            ->lockForUpdate()
            ->findOrFail($workerExam->id);

        $this->assertConvalidationTarget($workerExam);
        $origin = PersonalMinaExamen::query()
            ->with('asignacion.mina')
            ->lockForUpdate()
            ->find($originExamId);
        if (!$origin || $origin->examen_id !== $workerExam->examen_id) {
            throw ValidationException::withMessages(['examen_origen' => 'Selecciona un examen origen valido.']);
        }
        if ($origin->id === $workerExam->id
            || $origin->asignacion?->personal_id !== $workerExam->asignacion?->personal_id) {
            throw ValidationException::withMessages(['examen_origen' => 'El examen origen debe pertenecer al mismo trabajador y a otro proceso.']);
        }
        if (!$this->isExamApprovedAndCurrent($origin)) {
            throw ValidationException::withMessages(['examen_origen' => 'El examen origen no esta aprobado o vigente.']);
        }

        $allowedOrigins = $workerExam->requisitoMina->minas_origen_convalidacion_json ?: [];
        if (!$workerExam->requisitoMina->convalidar_desde_otras_minas && $origin->asignacion?->mina_id !== $workerExam->asignacion?->mina_id) {
            throw ValidationException::withMessages(['convalidacion' => 'No se permite convalidar desde otra mina.']);
        }
        if (!empty($allowedOrigins) && !in_array($origin->asignacion?->mina_id, $allowedOrigins, true)) {
            throw ValidationException::withMessages(['convalidacion' => 'La mina origen no esta permitida para convalidacion.']);
        }

        $workerExam->forceFill([
            'estado' => PersonalMinaExamen::ESTADO_CONVALIDADO,
            'resultado' => 'APROBADO',
            'fecha_realizacion' => $origin->fecha_realizacion,
            'fecha_vencimiento' => $origin->fecha_vencimiento,
            'es_convalidado' => true,
            'examen_origen_convalidado_id' => $origin->id,
            'mina_origen_convalidacion_id' => $origin->asignacion?->mina_id,
            'fecha_aprobacion_origen' => optional($origin->fecha_realizacion)->toDateString(),
            'fecha_convalidacion' => now(),
            'usuario_convalidacion_id' => $user->id,
            'observacion' => mb_substr(PersonalNormalizer::text($payload['observacion'] ?? ''), 0, 5000) ?: null,
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
        if ($current && $current->fecha_fin && $current->fecha_fin->lt(Carbon::today())) {
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

    private function refreshAssignmentStatus(PersonalMina $relation, Usuario $user, bool $notify = true): bool
    {
        $relation = PersonalMina::query()->with('examenes')->find($relation->id);
        if (!$relation) {
            return false;
        }

        $newState = $this->calculateAssignmentStatus($relation);
        $previous = $relation->estadoHabilitacionActual();

        if ($previous !== $newState) {
            $relation->forceFill([
                'estado' => $newState,
                'estado_habilitacion' => $newState,
                'fecha_habilitacion' => $newState === PersonalMina::ESTADO_HABILITADO ? Carbon::today()->toDateString() : null,
                'usuario_actualizacion_id' => $user->id,
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

        $today = Carbon::today();
        $end = Carbon::parse($expiration);
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
        return $this->currentCycleAttempts($exam)->count() < $this->effectiveMaxAttempts($exam);
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

        $cutoff = $this->expiredAttemptCycleCutoff($exam);
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
        return ((int) PersonalMinaExamenIntento::query()
            ->where('personal_mina_examen_id', $exam->id)
            ->max('numero_intento')) + 1;
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

        $expiration = Carbon::parse($exam->fecha_vencimiento)->endOfDay();
        $isExpiredByDate = $expiration->lt(Carbon::today()->startOfDay());
        $isExpiredByState = strtoupper((string) $exam->estado) === PersonalMinaExamen::ESTADO_VENCIDO;

        return $isExpiredByDate || $isExpiredByState ? $expiration : null;
    }

    private function attemptOperationalDate(PersonalMinaExamenIntento $attempt): ?Carbon
    {
        $date = $attempt->fecha_realizacion ?: $attempt->fecha_programacion ?: $attempt->created_at;

        return $date ? Carbon::parse($date) : null;
    }

    private function isExamApprovedAndCurrent(PersonalMinaExamen $exam): bool
    {
        $this->refreshExamExpirationState($exam);
        $exam = $exam->fresh() ?: $exam;
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

        if ($dateDone && $dateDone > Carbon::today(config('app.display_timezone', 'America/Lima'))->toDateString()) {
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

    public function resolveAttemptPriceSnapshot(PersonalMinaExamen $workerExam, ?string $registrationDate = null, ?string $dateScheduled = null, ?string $dateDone = null): array
    {
        $date = $registrationDate ?: $dateScheduled ?: $dateDone ?: Carbon::today()->toDateString();
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
                    ->orWhereDate('fecha_fin', '>=', Carbon::today()->toDateString());
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
