<?php

namespace App\Modules\Personal\Services;

use App\Models\ExamenMinero;
use App\Models\ExamenMineroPrecio;
use App\Models\Mina;
use App\Models\MinaRequisito;
use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Models\PersonalMina;
use App\Models\PersonalMinaHistorial;
use App\Models\Usuario;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalMinaHabilitacionService
{
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
            ])
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

        return $query->paginate($perPage)->withQueryString();
    }

    public function listGroupedByWorker(array $filters): LengthAwarePaginator
    {
        $perPage = $this->perPageFromFilters($filters);
        if (!Schema::hasTable('personal_mina')) {
            return new LengthAwarePaginator([], 0, $perPage, 1);
        }

        $baseQuery = PersonalMina::query()->where('activo', true);
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
            $grouped = PersonalMina::query()
                ->with([
                    'personal.contratoLaboralActual',
                    'mina',
                    'actualizadoPor.personal',
                    'historial.usuario.personal',
                    'examenes.intentos',
                    'examenes.requisitoMina',
                ])
                ->where('activo', true)
                ->whereIn('personal_id', $personalIds)
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
            ->when($minaId, fn ($query) => $query->where('mina_id', $minaId))
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
            ->where('estado', 'ACTIVO')
            ->orderBy('nombre')
            ->get();
    }

    public function workerOptions(?string $search = null, int $limit = 80)
    {
        $query = Personal::query()->orderBy('nombre_completo')->limit($limit);
        $search = trim((string) $search);

        if ($search !== '') {
            $needle = '%' . mb_strtolower($search) . '%';
            $query->where(function ($personalQuery) use ($needle): void {
                $personalQuery->whereRaw('LOWER(nombre_completo) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(numero_documento) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(dni) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(puesto) LIKE ?', [$needle]);
            });
        }

        return $query->get(['id', 'nombre_completo', 'dni', 'numero_documento', 'puesto', 'estado']);
    }

    public function findWorker(?string $workerId): ?Personal
    {
        $workerId = trim((string) $workerId);
        if ($workerId === '') {
            return null;
        }

        return Personal::query()
            ->with('contratoLaboralActual')
            ->find($workerId);
    }

    public function storeRequirement(array $payload): MinaRequisito
    {
        $mina = Mina::query()->find($payload['mina_id'] ?? null);
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

        return MinaRequisito::query()->create([
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

    public function assignMine(array $payload, Usuario $user): PersonalMina
    {
        $personal = Personal::query()->find($payload['personal_id'] ?? null);
        if (!$personal) {
            throw ValidationException::withMessages(['personal_id' => 'Selecciona un trabajador valido.']);
        }

        $mina = Mina::query()->find($payload['mina_id'] ?? null);
        if (!$mina) {
            throw ValidationException::withMessages(['mina_id' => 'Selecciona una mina valida.']);
        }

        if ($blocked = $this->blockingReasonForMine($personal, $mina)) {
            throw ValidationException::withMessages(['mina_id' => $blocked]);
        }

        $state = $this->normalizeHabilitationState($payload['estado_habilitacion'] ?? PersonalMina::ESTADO_EN_PROCESO);
        $this->assertCeasedWorkerConfirmation($personal, $state, $payload);

        return DB::transaction(function () use ($personal, $mina, $state, $payload, $user): PersonalMina {
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
            $this->generateRequiredExams($relation, $user);
            $this->refreshAssignmentStatus($relation, $user);

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

    public function generateRequiredExams(PersonalMina $relation, Usuario $user): int
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

        $this->refreshAssignmentStatus($relation, $user);

        return $created;
    }

    public function calculatedAssignmentStatus(PersonalMina $relation): string
    {
        return $this->calculateAssignmentStatus(
            PersonalMina::query()->with('examenes')->findOrFail($relation->id)
        );
    }

    public function deactivateRequirement(MinaRequisito $requirement): MinaRequisito
    {
        $requirement->forceFill(['activo' => false])->save();

        return $requirement->fresh(['mina', 'examen']);
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

                        $generated += $this->generateRequiredExams($relation, $user);
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
            ->with('examenes')
            ->where('personal_id', $worker->id)
            ->where('activo', true)
            ->get()
            ->keyBy('mina_id');

        return $mines->map(function (Mina $mine) use ($worker, $relations): array {
            $relation = $relations->get($mine->id);
            if ($relation) {
                $state = $relation->estadoHabilitacionActual();

                return [
                    'mine' => $mine,
                    'assignment' => $relation,
                    'state' => $state,
                    'label' => $this->habilitationStateOptions()[$state] ?? $state,
                    'reason' => $this->boardColorReason($state),
                ];
            }

            $blocked = $this->blockingReasonForMine($worker, $mine);

            return [
                'mine' => $mine,
                'state' => $blocked ? 'BLOQUEADA' : 'NEUTRO',
                'label' => $blocked ? 'Bloqueada' : 'Disponible',
                'reason' => $blocked ?: 'Sin proceso iniciado.',
            ];
        })->all();
    }

    public function registerAttempt(PersonalMinaExamen $workerExam, array $payload, ?UploadedFile $file, Usuario $user): PersonalMinaExamen
    {
        $workerExam = PersonalMinaExamen::query()
            ->with(['asignacion', 'intentos'])
            ->findOrFail($workerExam->id);

        $isExpired = strtoupper((string) $workerExam->estado) === PersonalMinaExamen::ESTADO_VENCIDO;

        if (!$isExpired && in_array(strtoupper((string) $workerExam->estado), [
            PersonalMinaExamen::ESTADO_NO_APLICA,
            PersonalMinaExamen::ESTADO_CONVALIDADO,
            PersonalMinaExamen::ESTADO_VIGENTE,
            PersonalMinaExamen::ESTADO_APROBADO,
        ], true)) {
            throw ValidationException::withMessages(['examen' => 'Este examen ya fue resuelto.']);
        }

        $currentAttempts = $workerExam->intentos->where('resultado', '!=', PersonalMinaExamenIntento::RESULTADO_ANULADO)->count();
        $maxAttempts = max(1, (int) $workerExam->max_intentos_snapshot);

        if (!$isExpired) {
            $nextAttempt = $currentAttempts + 1;
            if ($nextAttempt > $maxAttempts) {
                throw ValidationException::withMessages(['intento' => 'No se permite registrar un intento adicional.']);
            }
            if ($nextAttempt > 1 && !$workerExam->permite_reintento_snapshot) {
                throw ValidationException::withMessages(['intento' => 'Este examen no permite reintento.']);
            }
        } else {
            $nextAttempt = $currentAttempts + 1;
        }

        $result = $this->normalizeAttemptResult($payload['resultado'] ?? PersonalMinaExamenIntento::RESULTADO_PENDIENTE);
        $dateDone = PersonalNormalizer::isoDate($payload['fecha_realizacion'] ?? null);
        $dateScheduled = PersonalNormalizer::isoDate($payload['fecha_programacion'] ?? null);
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
        if ($manualExpiration && $dateDone && $manualExpiration < $dateDone) {
            throw ValidationException::withMessages(['fecha_vencimiento' => 'La fecha de vencimiento no puede ser menor a la fecha de realizacion.']);
        }

        $storedFile = $file ? $this->storeAttemptFile($workerExam, $file) : null;

        DB::transaction(function () use ($workerExam, $nextAttempt, $dateScheduled, $dateDone, $result, $score, $payload, $storedFile, $manualExpiration, $user): void {
            $priceSnapshot = $this->priceSnapshotForAttempt($workerExam, $dateScheduled, $dateDone);

            PersonalMinaExamenIntento::query()->create([
                'id' => (string) Str::uuid(),
                'personal_mina_examen_id' => $workerExam->id,
                'numero_intento' => $nextAttempt,
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

            $this->applyAttemptToExam($workerExam, $result, $dateScheduled, $dateDone, $score, $manualExpiration, $payload, $user);
            $this->refreshAssignmentStatus($workerExam->asignacion, $user);
        });

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

        $observation = mb_substr(PersonalNormalizer::text($payload['observacion'] ?? ''), 0, 5000);
        if ($observation === '') {
            throw ValidationException::withMessages(['observacion' => 'La observacion es obligatoria para marcar no aplica.']);
        }

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

    public function convalidateExam(PersonalMinaExamen $workerExam, string $originExamId, array $payload, Usuario $user): PersonalMinaExamen
    {
        $workerExam = PersonalMinaExamen::query()
            ->with(['asignacion.mina', 'requisitoMina'])
            ->findOrFail($workerExam->id);

        if (!$workerExam->requisitoMina?->permite_convalidacion_mina) {
            throw ValidationException::withMessages(['convalidacion' => 'Este examen no permite convalidacion para esta mina.']);
        }

        $origin = PersonalMinaExamen::query()
            ->with('asignacion.mina')
            ->find($originExamId);
        if (!$origin || $origin->examen_id !== $workerExam->examen_id) {
            throw ValidationException::withMessages(['examen_origen' => 'Selecciona un examen origen valido.']);
        }

        if (!$this->isExamApprovedAndCurrent($origin)) {
            throw ValidationException::withMessages(['examen_origen' => 'El examen origen no esta aprobado o vigente.']);
        }

        $today = Carbon::today();
        $start = $workerExam->requisitoMina->fecha_inicio_convalidacion;
        $end = $workerExam->requisitoMina->fecha_fin_convalidacion;
        if (($start && $today->lt($start)) || ($end && $today->gt($end))) {
            throw ValidationException::withMessages(['convalidacion' => 'La convalidacion esta fuera del rango permitido.']);
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

        $workerExam->forceFill([
            'estado' => $state,
            'resultado' => $result,
            'nota_obtenida' => $score,
            'fecha_programacion' => $dateScheduled,
            'fecha_realizacion' => $dateDone,
            'fecha_vencimiento' => $expiration,
            'observacion' => mb_substr(PersonalNormalizer::text($payload['observacion'] ?? ''), 0, 5000) ?: $workerExam->observacion,
            'usuario_actualizacion_id' => $user->id,
            'fecha_actualizacion' => now(),
        ])->save();
    }

    private function refreshAssignmentStatus(PersonalMina $relation, Usuario $user): bool
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

            return true;
        }

        return false;
    }

    private function calculateAssignmentStatus(PersonalMina $relation): string
    {
        $exams = $relation->examenes;
        if ($exams->isEmpty()) {
            return PersonalMina::ESTADO_EN_PROCESO;
        }

        foreach ($exams as $exam) {
            $this->refreshExamExpirationState($exam);
        }

        $required = $relation->examenes->where('obligatorio_snapshot', true);
        if ($required->isEmpty()) {
            return PersonalMina::ESTADO_HABILITADO;
        }

        if ($required->contains(fn (PersonalMinaExamen $exam) => $exam->estado === PersonalMinaExamen::ESTADO_OBSERVADO)) {
            return PersonalMina::ESTADO_OBSERVADO;
        }

        if ($required->contains(fn (PersonalMinaExamen $exam) => $exam->estado === PersonalMinaExamen::ESTADO_VENCIDO)) {
            return PersonalMina::ESTADO_NO_HABILITADO;
        }

        foreach ($required as $exam) {
            if ($exam->estado === PersonalMinaExamen::ESTADO_DESAPROBADO && !$this->hasAttemptsAvailable($exam)) {
                return $exam->critico_snapshot
                    ? PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION
                    : PersonalMina::ESTADO_NO_HABILITADO;
            }
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
        $attempts = $exam->relationLoaded('intentos')
            ? $exam->intentos->where('resultado', '!=', PersonalMinaExamenIntento::RESULTADO_ANULADO)->count()
            : PersonalMinaExamenIntento::query()
                ->where('personal_mina_examen_id', $exam->id)
                ->where('resultado', '!=', PersonalMinaExamenIntento::RESULTADO_ANULADO)
                ->count();

        return $attempts < max(1, (int) $exam->max_intentos_snapshot);
    }

    private function isExamApprovedAndCurrent(PersonalMinaExamen $exam): bool
    {
        $state = $this->stateForApprovedExam(optional($exam->fecha_vencimiento)->toDateString(), (bool) $exam->es_convalidado);

        return in_array($state, [
            PersonalMinaExamen::ESTADO_APROBADO,
            PersonalMinaExamen::ESTADO_VIGENTE,
            PersonalMinaExamen::ESTADO_POR_VENCER,
            PersonalMinaExamen::ESTADO_CONVALIDADO,
        ], true);
    }

    private function normalizeAttemptResult(mixed $value): string
    {
        $result = strtoupper(trim((string) $value));
        if (!array_key_exists($result, $this->attemptResultOptions())) {
            throw ValidationException::withMessages(['resultado' => 'Selecciona un resultado valido.']);
        }

        return $result;
    }

    private function storeAttemptFile(PersonalMinaExamen $workerExam, UploadedFile $file): array
    {
        $path = $file->storeAs(
            'habilitacion-minera/' . $workerExam->personal_mina_id,
            Str::uuid() . '.' . ($file->getClientOriginalExtension() ?: 'bin'),
            'local',
        );

        return [
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function priceSnapshotForAttempt(PersonalMinaExamen $workerExam, ?string $dateScheduled, ?string $dateDone): array
    {
        $date = $dateDone ?: $dateScheduled ?: Carbon::today()->toDateString();
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

    private function boardColorReason(string $state): string
    {
        return match ($state) {
            PersonalMina::ESTADO_HABILITADO => 'Trabajador habilitado en esta mina.',
            PersonalMina::ESTADO_NO_HABILITADO => 'Tiene examenes vencidos o desaprobados sin intentos disponibles.',
            PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION => 'Proceso finalizado por desaprobacion.',
            PersonalMina::ESTADO_OBSERVADO => 'Tiene examenes observados por revisar.',
            default => 'Proceso iniciado o requiere revision.',
        };
    }

    private function blockingReasonForMine(Personal $worker, Mina $mine): ?string
    {
        $requiredExamIds = MinaRequisito::query()
            ->where('mina_id', $mine->id)
            ->where('activo', true)
            ->whereNotNull('examen_id')
            ->pluck('examen_id')
            ->all();

        if (empty($requiredExamIds)) {
            return null;
        }

        $blocked = PersonalMinaExamen::query()
            ->whereIn('examen_id', $requiredExamIds)
            ->whereIn('estado', [PersonalMinaExamen::ESTADO_DESAPROBADO])
            ->whereHas('asignacion', fn ($query) => $query->where('personal_id', $worker->id))
            ->get()
            ->first(fn (PersonalMinaExamen $exam) => !$this->hasAttemptsAvailable($exam));

        return $blocked
            ? 'No puede asignarse porque desaprobo un examen requerido.'
            : null;
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

    private function recordHistory(PersonalMina $relation, ?string $previous, string $new, ?string $observation, Usuario $user): void
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
            'usuario_id' => $user->id,
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
