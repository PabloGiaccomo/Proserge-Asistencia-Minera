<?php

namespace App\Modules\Personal\Services;

use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalBloqueo;
use App\Models\PersonalContrato;
use App\Models\PersonalContratoDato;
use App\Models\PersonalFicha;
use App\Models\PersonalFichaFamiliar;
use App\Models\PersonalFichaLink;
use App\Models\PersonalMina;
use App\Models\PersonalPuesto;
use App\Models\Usuario;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalService
{
    public function list(array $filters): Collection
    {
        $query = $this->buildFilteredQuery($filters)->with(['minas']);

        if (Schema::hasTable('personal_puestos') && Schema::hasColumn('personal', 'puesto_id')) {
            $query->with('puestoCatalogo');
        }

        if (Schema::hasTable('personal_fichas')) {
            $query->with('fichaColaborador.link');
        }

        if (Schema::hasTable('personal_contratos')) {
            $query->with(['contratosLaborales.activadoPor.personal', 'contratosLaborales.cerradoPor.personal']);
        }
        if (Schema::hasTable('personal_contrato_datos')) {
            $query->with('contratoDatos');
        }

        if (Schema::hasColumn('personal', 'cesado_by_usuario_id')) {
            $query->with('cesadoPor.personal');
        }

        if (Schema::hasColumn('personal', 'lista_negra_by_usuario_id')) {
            $query->with('listaNegraPor.personal');
        }

        if (Schema::hasTable('personal_bloqueo')) {
            $query->with([
                'bloqueos' => function ($q): void {
                    $q->where('estado', 'ACTIVO')
                        ->where('visible_para_planner', true)
                        ->orderBy('fecha_inicio')
                        ->orderBy('fecha_fin');
                },
            ]);
        }

        if (Schema::hasTable('rq_proserge_detalle') && Schema::hasTable('rq_proserge')) {
            $today = Carbon::today()->toDateString();
            $query->with([
                'rqProsergeDetalles' => function ($q) use ($today): void {
                    $q->whereDate('fecha_inicio', '<=', $today)
                        ->whereDate('fecha_fin', '>=', $today)
                        ->whereHas('rqProserge', function ($rq): void {
                            $rq->whereNotIn('estado', ['CANCELADO', 'CERRADO']);
                        });
                },
            ]);
        }

        return $query->get();
    }

    public function listForIndex(array $filters): Collection
    {
        $query = $this->buildFilteredQuery($filters)->with(['minas:minas.id,minas.nombre']);

        $this->applyEagerForIndex($query);

        return $query->get();
    }

    public function paginatedForIndex(array $filters, int $perPage = 10): LengthAwarePaginator
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(10, min(100, $perPage));

        $query = $this->buildFilteredQuery($filters)->with(['minas:minas.id,minas.nombre']);

        $this->applyEagerForIndex($query);

        return $query->paginate($perPage, ['personal.*'], 'page', $page);
    }

    private function applyEagerForIndex(Builder $query): void
    {
        if (Schema::hasTable('personal_puestos') && Schema::hasColumn('personal', 'puesto_id')) {
            $query->with('puestoCatalogo:id,nombre,funciones');
        }

        if (Schema::hasTable('personal_fichas')) {
            $query->with([
                'fichaColaborador' => function ($q): void {
                    $q->select(
                        'personal_fichas.id',
                        'personal_fichas.personal_id',
                        'personal_fichas.estado',
                        'personal_fichas.submitted_at',
                        'personal_fichas.datos_json'
                    );
                },
            ]);
        }

        if (Schema::hasTable('personal_contratos')) {
            $query->with('contratosLaborales');
        }

        if (Schema::hasTable('personal_contrato_datos')) {
            $query->with('contratoDatos:id,personal_id,downloaded_at,signed_at,fecha_firma,signed_contract_original_name,signed_contract_path,sueldo_num,sueldo_hora_paradas');
        }

        if (Schema::hasColumn('personal', 'cesado_by_usuario_id')) {
            $query->with('cesadoPor.personal:id,nombre_completo');
        }

        if (Schema::hasColumn('personal', 'lista_negra_by_usuario_id')) {
            $query->with('listaNegraPor.personal:id,nombre_completo');
        }

        if (Schema::hasTable('personal_bloqueo')) {
            $query->with([
                'bloqueos' => function ($q): void {
                    $q->where('estado', 'ACTIVO')
                        ->where('visible_para_planner', true)
                        ->select('id', 'personal_id', 'tipo', 'motivo', 'detalle', 'fecha_inicio', 'fecha_fin')
                        ->orderBy('fecha_inicio')
                        ->orderBy('fecha_fin');
                },
            ]);
        }

        if (Schema::hasTable('rq_proserge_detalle') && Schema::hasTable('rq_proserge')) {
            $today = Carbon::today()->toDateString();
            $query->with([
                'rqProsergeDetalles' => function ($q) use ($today): void {
                    $q->whereDate('fecha_inicio', '<=', $today)
                        ->whereDate('fecha_fin', '>=', $today)
                        ->whereHas('rqProserge', function ($rq): void {
                            $rq->whereNotIn('estado', ['CANCELADO', 'CERRADO']);
                        })
                        ->select('id', 'personal_id')
                        ->limit(1);
                },
            ]);
        }
    }

    public function buildFilteredQuery(array $filters): Builder
    {
        $query = Personal::query()->select('personal.*');

        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $this->applySearch($query, $search);
        }

        if (!empty($filters['ids']) && is_array($filters['ids'])) {
            $query->whereIn('personal.id', collect($filters['ids'])->map(fn ($id) => (string) $id)->filter()->values()->all());
        }

        if (!empty($filters['exclude_ids']) && is_array($filters['exclude_ids'])) {
            $query->whereNotIn('personal.id', collect($filters['exclude_ids'])->map(fn ($id) => (string) $id)->filter()->values()->all());
        }

        if (array_key_exists('es_supervisor', $filters)) {
            $query->where('personal.es_supervisor', filter_var($filters['es_supervisor'], FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($filters['solo_activos'])) {
            $query->whereIn('personal.estado', ['ACTIVO', 'APROBADO', 'FALTA_CONTRATO']);
        }

        if (!empty($filters['with_minas'])) {
            $query->with('minas');
        }

        if (!empty($filters['limit'])) {
            $query->limit(max(1, min(50, (int) $filters['limit'])));
        }

        $stateFilter = strtoupper((string) ($filters['estado'] ?? ''));
        $allowedStates = ['ACTIVO', 'FALTA_CONTRATO', 'NO_FIRMO_CONTRATO', 'INACTIVO', 'PENDIENTE_COMPLETAR_FICHA', 'FICHA_ENVIADA', 'LINK_VENCIDO', 'APROBADO', 'OBSERVADO', 'RECHAZADO'];
        if (in_array($stateFilter, $allowedStates, true)) {
            $query->where('personal.estado', $stateFilter);
        }

        $typeFilter = strtolower((string) ($filters['tipo'] ?? ''));
        if ($typeFilter === 'supervisor' || $typeFilter === 'supervisores') {
            $query->where('personal.es_supervisor', true);
        }
        if ($typeFilter === 'trabajador' || $typeFilter === 'trabajadores') {
            $query->where('personal.es_supervisor', false);
        }

        $contractFilter = PersonalNormalizer::contract($filters['contrato'] ?? null);
        if (!empty($filters['contrato'])) {
            $query->where('personal.contrato', $contractFilter);
        }

        $mineFilter = trim((string) ($filters['mina'] ?? ''));
        $mineIds = $mineFilter !== '' ? $this->resolveMineIds($mineFilter) : [];
        $mineState = PersonalNormalizer::mineStatusFromInput($filters['mina_estado'] ?? '');

        if ($mineFilter !== '' || !empty($filters['mina_estado'])) {
            if ($mineFilter !== '' && count($mineIds) === 0) {
                $query->whereRaw('1=0');
            } else {
                $query->whereExists(function ($q) use ($mineIds, $mineState, $mineFilter): void {
                    $q->selectRaw('1')
                        ->from('personal_mina as pm')
                        ->whereColumn('pm.personal_id', 'personal.id');

                    if ($mineFilter !== '') {
                        $q->whereIn('pm.mina_id', $mineIds);
                    }

                    if ($mineState !== '') {
                        $q->where('pm.estado', $mineState);
                    }
                });
            }
        }

        $sortColumn = match (strtolower((string) ($filters['sort'] ?? 'nombre'))) {
            'dni' => 'personal.dni',
            'puesto' => 'personal.puesto',
            'contrato' => 'personal.contrato',
            'estado' => 'personal.estado',
            'nombre' => 'personal.nombre_completo',
            default => 'personal.nombre_completo',
        };

        $sortDirection = strtolower((string) ($filters['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($sortColumn, $sortDirection)->orderBy('personal.nombre_completo');
    }

    public function find(string $id): ?Personal
    {
        $query = Personal::query()->with('minas');

        if (Schema::hasTable('personal_puestos') && Schema::hasColumn('personal', 'puesto_id')) {
            $query->with('puestoCatalogo');
        }

        if (Schema::hasTable('personal_fichas')) {
            $query->with('fichaColaborador.link');
        }

        if (Schema::hasTable('personal_contratos')) {
            $query->with(['contratosLaborales.activadoPor.personal', 'contratosLaborales.cerradoPor.personal']);
        }
        if (Schema::hasTable('personal_contrato_datos')) {
            $query->with('contratoDatos');
        }

        if (Schema::hasColumn('personal', 'cesado_by_usuario_id')) {
            $query->with('cesadoPor.personal');
        }

        if (Schema::hasColumn('personal', 'lista_negra_by_usuario_id')) {
            $query->with('listaNegraPor.personal');
        }

        if (Schema::hasTable('personal_bloqueo')) {
            $query->with([
                'bloqueos' => function ($q): void {
                    $q->where('estado', 'ACTIVO')
                        ->where('visible_para_planner', true)
                        ->orderBy('fecha_inicio')
                        ->orderBy('fecha_fin');
                },
            ]);
        }

        if (Schema::hasTable('rq_proserge_detalle') && Schema::hasTable('rq_proserge')) {
            $today = Carbon::today()->toDateString();
            $query->with([
                'rqProsergeDetalles' => function ($q) use ($today): void {
                    $q->whereDate('fecha_inicio', '<=', $today)
                        ->whereDate('fecha_fin', '>=', $today)
                        ->whereHas('rqProserge', function ($rq): void {
                            $rq->whereNotIn('estado', ['CANCELADO', 'CERRADO']);
                        });
                },
            ]);
        }

        return $query->find($id);
    }

    public function syncExpiredContractClosures(?Usuario $usuario = null): void
    {
        if (!Schema::hasTable('personal_contratos')) {
            return;
        }

        app(PersonalContratoService::class)->syncExpiredActiveContracts($usuario);

        if (!Schema::hasTable('personal_fichas')) {
            return;
        }

        $today = Carbon::today()->toDateString();

        Personal::query()
            ->where('estado', '!=', 'CESADO')
            ->with(['fichaColaborador', 'contratoDatos', 'contratoLaboralActual'])
            ->chunk(100, function (Collection $workers) use ($today, $usuario): void {
                foreach ($workers as $worker) {
                    $data = is_array($worker->fichaColaborador?->datos_json ?? null)
                        ? $worker->fichaColaborador->datos_json
                        : [];
                    $fechaFin = PersonalNormalizer::isoDate($data['fecha_fin_contrato'] ?? null);

                    if ($fechaFin && $fechaFin < $today && !$this->hasSignedContract($worker)) {
                        $this->markCeased($worker, 'Termino de contrato', $usuario, $fechaFin);
                    }
                }
            });
    }

    public function searchSelector(string $search, bool $supervisorsOnly = false, int $limit = 12): Collection
    {
        $filters = [
            'search' => $search,
            'with_minas' => true,
            'limit' => $limit,
        ];

        if ($supervisorsOnly) {
            $filters['es_supervisor'] = true;
        }

        return $this->buildFilteredQuery($filters)->get();
    }

    public function create(array $payload): Personal
    {
        return DB::transaction(function () use ($payload): Personal {
            $phoneRaw = PersonalNormalizer::combinePhones(
                PersonalNormalizer::text($payload['telefono_1'] ?? ''),
                PersonalNormalizer::text($payload['telefono_2'] ?? '')
            ) ?? ($payload['telefono'] ?? null);
            $phoneData = PersonalNormalizer::normalizePhonePayload($phoneRaw);
            $documentNumber = PersonalNormalizer::documentNumber($payload['numero_documento'] ?? $payload['dni'] ?? '');
            $documentType = PersonalNormalizer::documentType($payload['tipo_documento'] ?? 'DNI', $documentNumber);
            $legacyDni = $documentType === 'DNI' ? PersonalNormalizer::dni($documentNumber) : $documentNumber;

            $puestoText = PersonalNormalizer::text($payload['puesto'] ?? '');
            $puestoCatalogo = $this->resolvePuestoCatalogo($puestoText);

            $data = [
                'id' => (string) Str::uuid(),
                'dni' => $legacyDni,
                'nombre_completo' => PersonalNormalizer::text($payload['nombre_completo'] ?? ''),
                'puesto' => $puestoCatalogo?->nombre ?: $puestoText,
                'ocupacion' => PersonalNormalizer::text($payload['ocupacion'] ?? '') ?: null,
                'contrato' => PersonalNormalizer::contract($payload['contrato'] ?? null),
                'es_supervisor' => $this->resolveSupervisor($payload),
                'qr_code' => 'QR-' . $legacyDni . '-' . Str::upper(Str::random(8)),
                'fecha_ingreso' => PersonalNormalizer::isoDate($payload['fecha_ingreso'] ?? null),
                'estado' => $this->resolveInitialState($payload['estado'] ?? PersonalFicha::ESTADO_PENDIENTE),
            ];

            if (Schema::hasColumn('personal', 'tipo_documento')) {
                $data['tipo_documento'] = $documentType;
            }

            if (Schema::hasColumn('personal', 'puesto_id')) {
                $data['puesto_id'] = $puestoCatalogo?->id;
            }

            if (Schema::hasColumn('personal', 'numero_documento')) {
                $data['numero_documento'] = $documentNumber;
            }

            if (Schema::hasColumn('personal', 'telefono')) {
                $data['telefono'] = PersonalNormalizer::combinePhones(
                    $phoneData['telefono_1'],
                    $phoneData['telefono_2']
                );
            }

            if (Schema::hasColumn('personal', 'telefono_1')) {
                $data['telefono_1'] = $phoneData['telefono_1'];
            }

            if (Schema::hasColumn('personal', 'telefono_2')) {
                $data['telefono_2'] = $phoneData['telefono_2'];
            }

            if (Schema::hasColumn('personal', 'correo')) {
                $data['correo'] = PersonalNormalizer::text($payload['correo'] ?? '') ?: null;
            }

            if (Schema::hasColumn('personal', 'origen_registro')) {
                $data['origen_registro'] = $this->resolveRecordOrigin($payload['origen_registro'] ?? 'NUEVO');
            }

            if (Schema::hasColumn('personal', 'observacion_historica')) {
                $data['observacion_historica'] = PersonalNormalizer::text($payload['observacion_historica'] ?? '') ?: null;
            }

            if (Schema::hasColumn('personal', 'pendiente_regularizacion')) {
                $data['pendiente_regularizacion'] = filter_var($payload['pendiente_regularizacion'] ?? false, FILTER_VALIDATE_BOOLEAN);
            }

            if (Schema::hasColumn('personal', 'pendiente_contrato_firmado')) {
                $data['pendiente_contrato_firmado'] = filter_var($payload['pendiente_contrato_firmado'] ?? false, FILTER_VALIDATE_BOOLEAN);
            }

            $personal = Personal::query()->create($data);

            $this->syncMineRelations($personal, $payload['minas'] ?? []);

            return $personal->load('minas');
        });
    }

    public function update(Personal $personal, array $payload): Personal
    {
        return DB::transaction(function () use ($personal, $payload): Personal {
            $phoneRaw = PersonalNormalizer::combinePhones(
                PersonalNormalizer::text($payload['telefono_1'] ?? ''),
                PersonalNormalizer::text($payload['telefono_2'] ?? '')
            ) ?? ($payload['telefono'] ?? null);
            $phoneData = PersonalNormalizer::normalizePhonePayload($phoneRaw);
            $documentNumber = PersonalNormalizer::documentNumber($payload['numero_documento'] ?? $payload['dni'] ?? $personal->numero_documento ?? $personal->dni);
            $documentType = PersonalNormalizer::documentType($payload['tipo_documento'] ?? $personal->tipo_documento ?? 'DNI', $documentNumber);
            $legacyDni = $documentType === 'DNI' ? PersonalNormalizer::dni($documentNumber) : $documentNumber;

            $puestoText = PersonalNormalizer::text($payload['puesto'] ?? '');
            $puestoCatalogo = $this->resolvePuestoCatalogo($puestoText);

            $data = [
                'dni' => $legacyDni,
                'nombre_completo' => PersonalNormalizer::text($payload['nombre_completo'] ?? ''),
                'puesto' => $puestoCatalogo?->nombre ?: $puestoText,
                'ocupacion' => PersonalNormalizer::text($payload['ocupacion'] ?? '') ?: null,
                'contrato' => PersonalNormalizer::contract($payload['contrato'] ?? null),
                'es_supervisor' => $this->resolveSupervisor($payload),
                'fecha_ingreso' => PersonalNormalizer::isoDate($payload['fecha_ingreso'] ?? null),
                'estado' => $this->resolveWorkflowStateForUpdate($personal, $payload['estado'] ?? $personal->estado ?? PersonalFicha::ESTADO_PENDIENTE),
            ];

            if (Schema::hasColumn('personal', 'tipo_documento')) {
                $data['tipo_documento'] = $documentType;
            }

            if (Schema::hasColumn('personal', 'puesto_id')) {
                $data['puesto_id'] = $puestoCatalogo?->id;
            }

            if (Schema::hasColumn('personal', 'numero_documento')) {
                $data['numero_documento'] = $documentNumber;
            }

            if (Schema::hasColumn('personal', 'telefono')) {
                $data['telefono'] = PersonalNormalizer::combinePhones(
                    $phoneData['telefono_1'],
                    $phoneData['telefono_2']
                );
            }

            if (Schema::hasColumn('personal', 'telefono_1')) {
                $data['telefono_1'] = $phoneData['telefono_1'];
            }

            if (Schema::hasColumn('personal', 'telefono_2')) {
                $data['telefono_2'] = $phoneData['telefono_2'];
            }

            if (Schema::hasColumn('personal', 'correo')) {
                $data['correo'] = PersonalNormalizer::text($payload['correo'] ?? '') ?: null;
            }

            if (Schema::hasColumn('personal', 'origen_registro') && array_key_exists('origen_registro', $payload)) {
                $data['origen_registro'] = $this->resolveRecordOrigin($payload['origen_registro']);
            }

            if (Schema::hasColumn('personal', 'observacion_historica') && array_key_exists('observacion_historica', $payload)) {
                $data['observacion_historica'] = PersonalNormalizer::text($payload['observacion_historica'] ?? '') ?: null;
            }

            if (Schema::hasColumn('personal', 'pendiente_regularizacion') && array_key_exists('pendiente_regularizacion', $payload)) {
                $data['pendiente_regularizacion'] = filter_var($payload['pendiente_regularizacion'] ?? false, FILTER_VALIDATE_BOOLEAN);
            }

            if (Schema::hasColumn('personal', 'pendiente_contrato_firmado') && array_key_exists('pendiente_contrato_firmado', $payload)) {
                $data['pendiente_contrato_firmado'] = filter_var($payload['pendiente_contrato_firmado'] ?? false, FILTER_VALIDATE_BOOLEAN);
            }

            $personal->fill($data);
            $personal->save();

            $this->syncMineRelations($personal, $payload['minas'] ?? []);

            return $personal->load('minas');
        });
    }

    public function syncMineRelations(Personal $personal, array $mineItems): void
    {
        $allowedStates = ['HABILITADO', 'EN_PROCESO', 'NO_HABILITADO'];
        $desired = [];

        foreach ($mineItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $mineId = $item['mina_id'] ?? null;
            if (!$mineId && !empty($item['mina_nombre'])) {
                $resolved = $this->resolveMineIds((string) $item['mina_nombre']);
                $mineId = $resolved[0] ?? null;
            }

            if (!$mineId) {
                continue;
            }

            $status = PersonalNormalizer::mineStatusFromInput($item['estado'] ?? null);
            if (!in_array($status, $allowedStates, true)) {
                continue;
            }

            $desired[$mineId] = $status;
        }

        if (count($desired) === 0) {
            PersonalMina::query()->where('personal_id', $personal->id)->delete();

            return;
        }

        PersonalMina::query()
            ->where('personal_id', $personal->id)
            ->whereNotIn('mina_id', array_keys($desired))
            ->delete();

        foreach ($desired as $mineId => $state) {
            $relation = PersonalMina::query()
                ->where('personal_id', $personal->id)
                ->where('mina_id', $mineId)
                ->first();

            if ($relation) {
                $relation->estado = $state;
                $relation->save();
                continue;
            }

            PersonalMina::query()->create([
                'id' => (string) Str::uuid(),
                'personal_id' => $personal->id,
                'mina_id' => $mineId,
                'estado' => $state,
            ]);
        }
    }

    public function normalizeStateInput(mixed $value): string
    {
        return $this->resolveState($value);
    }

    public function hasSignedContract(Personal $personal): bool
    {
        if (!Schema::hasTable('personal_contratos')) {
            if (!Schema::hasTable('personal_contrato_datos')) {
                return false;
            }

            $legacyRecord = $personal->relationLoaded('contratoDatos')
                ? $personal->contratoDatos
                : PersonalContratoDato::query()
                    ->where('personal_id', $personal->id)
                    ->whereNotNull('signed_at')
                    ->whereNotNull('signed_contract_path')
                    ->first();

            return $legacyRecord !== null
                && $legacyRecord->signed_at !== null
                && trim((string) $legacyRecord->signed_contract_path) !== '';
        }

        $signedActiveContract = PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->where('estado', PersonalContrato::ESTADO_ACTIVO)
            ->whereNotNull('signed_at')
            ->whereNotNull('signed_contract_path')
            ->where(function (Builder $query): void {
                $query->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', Carbon::today()->toDateString());
            })
            ->latest('contrato_numero')
            ->first();

        if ($signedActiveContract && trim((string) ($signedActiveContract->signed_contract_path ?? '')) !== '') {
            return true;
        }

        $activeContract = $personal->relationLoaded('contratoLaboralActual')
            ? $personal->contratoLaboralActual
            : PersonalContrato::query()
                ->where('personal_id', $personal->id)
                ->whereIn('estado', [PersonalContrato::ESTADO_PREPARACION, PersonalContrato::ESTADO_ACTIVO])
                ->latest('contrato_numero')
                ->first();

        $activeContractEnd = optional($activeContract?->fecha_fin)->toDateString();
        if ($activeContract && strtoupper((string) $activeContract->estado) === PersonalContrato::ESTADO_ACTIVO
            && $activeContractEnd && $activeContractEnd < Carbon::today()->toDateString()) {
            return false;
        }

        if (!Schema::hasTable('personal_contrato_datos')) {
            return false;
        }

        $record = $personal->relationLoaded('contratoDatos')
            ? $personal->contratoDatos
            : PersonalContratoDato::query()
                ->where('personal_id', $personal->id)
                ->whereNotNull('signed_at')
                ->whereNotNull('signed_contract_path')
                ->first();

        if (!$record || $record->signed_at === null || trim((string) $record->signed_contract_path) === '') {
            return false;
        }

        if (!$activeContract?->activado_at) {
            return true;
        }

        return $record->signed_at->greaterThanOrEqualTo($activeContract->activado_at);
    }

    public function resolveActiveIntentState(?Personal $personal): string
    {
        if (!$personal) {
            return PersonalFicha::ESTADO_PENDIENTE;
        }

        $current = strtoupper((string) $personal->estado);
        if ($current === 'CESADO') {
            return 'CESADO';
        }

        if ($this->hasSignedContract($personal)) {
            return 'ACTIVO';
        }

        $ficha = $personal->relationLoaded('fichaColaborador') ? $personal->fichaColaborador : $personal->fichaColaborador()->first();
        $hasApprovedFicha = strtoupper((string) ($ficha?->estado ?? '')) === PersonalFicha::ESTADO_APROBADO;

        return $hasApprovedFicha || in_array($current, [PersonalContratoDatoService::PENDING_STATE, PersonalFicha::ESTADO_APROBADO], true)
            ? PersonalContratoDatoService::PENDING_STATE
            : PersonalFicha::ESTADO_PENDIENTE;
    }

    public function markCeased(Personal $personal, string $motivo, ?Usuario $usuario = null, ?string $fechaCese = null): Personal
    {
        $motivo = trim($motivo);
        $fechaCese = PersonalNormalizer::isoDate($fechaCese) ?: Carbon::today()->toDateString();

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'motivo_cese' => 'El motivo de cese es obligatorio.',
            ]);
        }

        $data = ['estado' => 'CESADO'];

        if (Schema::hasColumn('personal', 'motivo_cese')) {
            $data['motivo_cese'] = $motivo;
        }

        if (Schema::hasColumn('personal', 'fecha_cese')) {
            $data['fecha_cese'] = $fechaCese;
        }

        if (Schema::hasColumn('personal', 'cesado_at')) {
            $data['cesado_at'] = now();
        }

        if (Schema::hasColumn('personal', 'cesado_by_usuario_id') && $usuario?->id && Usuario::query()->whereKey($usuario->id)->exists()) {
            $data['cesado_by_usuario_id'] = $usuario->id;
        }

        DB::transaction(function () use ($personal, $data, $motivo, $usuario, $fechaCese): void {
            $personal->forceFill($data)->save();

            app(PersonalContratoService::class)->closeCurrentContract(
                $personal->fresh(['fichaColaborador', 'minas', 'cesadoPor.personal']) ?: $personal,
                $motivo,
                $usuario,
                $fechaCese
            );
        });

        $relations = ['minas', 'fichaColaborador.link'];
        if (Schema::hasTable('personal_contratos')) {
            $relations[] = 'contratosLaborales.activadoPor.personal';
            $relations[] = 'contratosLaborales.cerradoPor.personal';
        }
        if (Schema::hasTable('personal_contrato_datos')) {
            $relations[] = 'contratoDatos';
        }

        return $personal->fresh($relations);
    }

    public function addToListaNegra(Personal $personal, string $motivo, ?Usuario $usuario = null): Personal
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'motivo_lista_negra' => 'El motivo de lista negra es obligatorio.',
            ]);
        }

        if (!Schema::hasColumn('personal', 'en_lista_negra')) {
            throw ValidationException::withMessages([
                'lista_negra' => 'La base de datos aun no tiene habilitada la lista negra de personal.',
            ]);
        }

        $data = [
            'en_lista_negra' => true,
        ];

        if (Schema::hasColumn('personal', 'lista_negra_motivo')) {
            $data['lista_negra_motivo'] = $motivo;
        }

        if (Schema::hasColumn('personal', 'lista_negra_at')) {
            $data['lista_negra_at'] = now();
        }

        if (Schema::hasColumn('personal', 'lista_negra_by_usuario_id') && $usuario?->id && Usuario::query()->whereKey($usuario->id)->exists()) {
            $data['lista_negra_by_usuario_id'] = $usuario->id;
        }

        $personal->forceFill($data)->save();

        $relations = ['minas', 'fichaColaborador'];
        if (Schema::hasColumn('personal', 'lista_negra_by_usuario_id')) {
            $relations[] = 'listaNegraPor.personal';
        }

        return $personal->fresh($relations) ?: $personal;
    }

    public function removeFromListaNegra(Personal $personal): Personal
    {
        if (!Schema::hasColumn('personal', 'en_lista_negra')) {
            throw ValidationException::withMessages([
                'lista_negra' => 'La base de datos aun no tiene habilitada la lista negra de personal.',
            ]);
        }

        $data = [
            'en_lista_negra' => false,
        ];

        foreach (['lista_negra_motivo', 'lista_negra_at', 'lista_negra_by_usuario_id'] as $column) {
            if (Schema::hasColumn('personal', $column)) {
                $data[$column] = null;
            }
        }

        $personal->forceFill($data)->save();

        return $personal->fresh(['minas', 'fichaColaborador']) ?: $personal;
    }

    public function deleteCompletely(Personal $personal): void
    {
        $blockers = $this->deletionBlockers($personal);
        if (!empty($blockers)) {
            throw ValidationException::withMessages([
                'personal' => 'No se puede eliminar porque tiene registros vinculados en: ' . implode(', ', $blockers) . '.',
            ]);
        }

        DB::transaction(function () use ($personal): void {
            $personal->loadMissing(['fichas.archivos', 'fichas.familiares', 'fichas.link', 'bloqueos', 'relacionesMina']);

            foreach ($personal->fichas as $ficha) {
                foreach ($ficha->archivos as $archivo) {
                    if ($archivo->path && Storage::disk('local')->exists($archivo->path)) {
                        Storage::disk('local')->delete($archivo->path);
                    }
                    $archivo->delete();
                }

                if ($ficha->huella_path && Storage::disk('local')->exists($ficha->huella_path)) {
                    Storage::disk('local')->delete($ficha->huella_path);
                }

                PersonalFichaFamiliar::query()->where('personal_ficha_id', $ficha->id)->delete();
                PersonalFichaLink::query()->where('personal_ficha_id', $ficha->id)->delete();
                $ficha->delete();
            }

            $usuarios = Usuario::query()
                ->where('personal_id', $personal->id)
                ->get();

            foreach ($usuarios as $usuario) {
                if (Schema::hasTable('usuario_roles')) {
                    DB::table('usuario_roles')->where('usuario_id', $usuario->id)->delete();
                }
                if (Schema::hasTable('usuario_mina_scope')) {
                    DB::table('usuario_mina_scope')->where('usuario_id', $usuario->id)->delete();
                }
                if (Schema::hasTable('notification_recipients')) {
                    DB::table('notification_recipients')->where('usuario_id', $usuario->id)->delete();
                }
                if (Schema::hasTable('notification_events') && Schema::hasColumn('notification_events', 'actor_usuario_id')) {
                    DB::table('notification_events')->where('actor_usuario_id', $usuario->id)->update([
                        'actor_usuario_id' => null,
                    ]);
                }

                $usuario->delete();
            }

            PersonalBloqueo::query()->where('personal_id', $personal->id)->delete();
            PersonalMina::query()->where('personal_id', $personal->id)->delete();
            $personal->delete();
        });
    }

    private function resolveSupervisor(array $payload): bool
    {
        if (array_key_exists('es_supervisor', $payload)) {
            return filter_var($payload['es_supervisor'], FILTER_VALIDATE_BOOLEAN);
        }

        return PersonalNormalizer::isSupervisorOccupation($payload['ocupacion'] ?? null);
    }

    private function resolveRecordOrigin(mixed $value): string
    {
        $origin = strtoupper(trim((string) $value));

        return in_array($origin, ['NUEVO', 'ANTIGUO', 'IMPORTADO', 'HISTORICO'], true)
            ? $origin
            : 'NUEVO';
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

    private function resolveState(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'ACTIVO' : 'INACTIVO';
        }

        $state = strtoupper(trim((string) $value));

        $allowed = [
            'ACTIVO',
            'FALTA_CONTRATO',
            'NO_FIRMO_CONTRATO',
            'INACTIVO',
            'CESADO',
            'PENDIENTE_COMPLETAR_FICHA',
            'FICHA_ENVIADA',
            'LINK_VENCIDO',
            'APROBADO',
            'OBSERVADO',
            'RECHAZADO',
        ];

        if (in_array($state, $allowed, true)) {
            return $state;
        }

        return in_array($state, ['1', 'ACTIVE'], true) ? 'ACTIVO' : 'INACTIVO';
    }

    private function resolveInitialState(mixed $value): string
    {
        $state = $this->resolveState($value);

        return $state === 'ACTIVO' ? PersonalFicha::ESTADO_PENDIENTE : $state;
    }

    private function resolveWorkflowStateForUpdate(Personal $personal, mixed $value): string
    {
        $state = $this->resolveState($value);

        if ($state !== 'ACTIVO') {
            return $state;
        }

        $current = strtoupper((string) $personal->estado);
        if ($current === 'ACTIVO' || $this->hasSignedContract($personal)) {
            return 'ACTIVO';
        }

        return $this->resolveActiveIntentState($personal);
    }

    private function applySearch(Builder $query, string $search): void
    {
        $searchableColumns = collect([
            'nombre_completo',
            'dni',
            'numero_documento',
            'puesto',
            'ocupacion',
            'contrato',
            'correo',
            'telefono',
            'telefono_1',
            'telefono_2',
        ])
            ->filter(fn (string $column): bool => Schema::hasColumn('personal', $column))
            ->values();

        $tokens = collect(preg_split('/\s+/u', mb_strtolower(trim($search))) ?: [])
            ->map(fn (string $token): string => trim($token))
            ->filter()
            ->values();

        foreach ($tokens as $token) {
            $variants = collect([
                $token,
                PersonalNormalizer::normalizeKey($token),
            ])
                ->filter()
                ->unique()
                ->values();

            $query->where(function (Builder $sub) use ($variants, $searchableColumns): void {
                foreach ($variants as $variant) {
                    $needle = '%' . $variant . '%';

                    foreach ($searchableColumns as $column) {
                        $sub->orWhereRaw("LOWER(COALESCE(personal.{$column}, '')) LIKE ?", [$needle]);
                    }

                    $sub->orWhereExists(function ($q) use ($needle): void {
                        $q->selectRaw('1')
                            ->from('personal_mina as pm')
                            ->join('minas as m', 'm.id', '=', 'pm.mina_id')
                            ->whereColumn('pm.personal_id', 'personal.id')
                            ->where(function ($mineMatch) use ($needle): void {
                                $mineMatch->whereRaw("LOWER(COALESCE(m.nombre, '')) LIKE ?", [$needle])
                                    ->orWhereRaw("LOWER(COALESCE(m.unidad_minera, '')) LIKE ?", [$needle]);
                            });
                    });
                }
            });
        }
    }

    private function resolveMineIds(string $mineFilter): array
    {
        $needle = PersonalNormalizer::normalizeKey($mineFilter);
        if ($needle === '') {
            return [];
        }

        return Mina::query()
            ->get(['id', 'nombre', 'unidad_minera'])
            ->filter(function (Mina $mine) use ($needle): bool {
                return in_array($needle, [
                    PersonalNormalizer::normalizeKey($mine->id),
                    PersonalNormalizer::normalizeKey((string) $mine->nombre),
                    PersonalNormalizer::normalizeKey((string) $mine->unidad_minera),
                ], true);
            })
            ->pluck('id')
            ->values()
            ->all();
    }

    private function deletionBlockers(Personal $personal): array
    {
        $checks = [
            ['table' => 'asistencia_detalles', 'column' => 'trabajador_id', 'label' => 'asistencias'],
            ['table' => 'faltas', 'column' => 'trabajador_id', 'label' => 'faltas'],
            ['table' => 'grupo_trabajo_detalles', 'column' => 'personal_id', 'label' => 'grupos de trabajo'],
            ['table' => 'rq_proserge_detalles', 'column' => 'personal_id', 'label' => 'RQ Proserge'],
            ['table' => 'evaluaciones_desempeno', 'column' => 'trabajador_id', 'label' => 'evaluaciones de desempeno'],
            ['table' => 'evaluacion_desempenos', 'column' => 'trabajador_id', 'label' => 'evaluaciones de desempeno'],
            ['table' => 'evaluacion_supervisors', 'column' => 'evaluado_id', 'label' => 'evaluaciones de supervisor'],
            ['table' => 'evaluacion_supervisores', 'column' => 'evaluado_id', 'label' => 'evaluaciones de supervisor'],
        ];

        return collect($checks)
            ->filter(function (array $check) use ($personal): bool {
                return Schema::hasTable($check['table'])
                    && Schema::hasColumn($check['table'], $check['column'])
                    && DB::table($check['table'])->where($check['column'], $personal->id)->exists();
            })
            ->pluck('label')
            ->unique()
            ->values()
            ->all();
    }
}
