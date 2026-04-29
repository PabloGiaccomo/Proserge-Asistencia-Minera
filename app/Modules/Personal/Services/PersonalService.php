<?php

namespace App\Modules\Personal\Services;

use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PersonalService
{
    public function list(array $filters): Collection
    {
        $query = $this->buildFilteredQuery($filters)->with(['minas']);

        if (Schema::hasTable('personal_fichas')) {
            $query->with('fichaColaborador.link');
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

        return $query->get();
    }

    public function buildFilteredQuery(array $filters): Builder
    {
        $query = Personal::query()->select('personal.*');

        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $needle = '%' . mb_strtolower($search) . '%';

            $query->where(function (Builder $sub) use ($needle): void {
                $sub->whereRaw('LOWER(personal.nombre_completo) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(personal.dni) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(personal.puesto) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(personal.contrato) LIKE ?', [$needle])
                    ->orWhereExists(function ($q) use ($needle): void {
                        $q->selectRaw('1')
                            ->from('personal_mina as pm')
                            ->join('minas as m', 'm.id', '=', 'pm.mina_id')
                            ->whereColumn('pm.personal_id', 'personal.id')
                            ->where(function ($mineMatch) use ($needle): void {
                                $mineMatch->whereRaw('LOWER(m.nombre) LIKE ?', [$needle])
                                    ->orWhereRaw('LOWER(m.unidad_minera) LIKE ?', [$needle]);
                            });
                    });
            });
        }

        $stateFilter = strtoupper((string) ($filters['estado'] ?? ''));
        $allowedStates = ['ACTIVO', 'INACTIVO', 'PENDIENTE_COMPLETAR_FICHA', 'FICHA_ENVIADA', 'LINK_VENCIDO', 'APROBADO', 'OBSERVADO', 'RECHAZADO'];
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

        if (Schema::hasTable('personal_fichas')) {
            $query->with('fichaColaborador.link');
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

        return $query->find($id);
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

            $data = [
                'id' => (string) Str::uuid(),
                'dni' => $legacyDni,
                'nombre_completo' => PersonalNormalizer::text($payload['nombre_completo'] ?? ''),
                'puesto' => PersonalNormalizer::text($payload['puesto'] ?? ''),
                'ocupacion' => PersonalNormalizer::text($payload['ocupacion'] ?? '') ?: null,
                'contrato' => PersonalNormalizer::contract($payload['contrato'] ?? null),
                'es_supervisor' => $this->resolveSupervisor($payload),
                'qr_code' => 'QR-' . $legacyDni . '-' . Str::upper(Str::random(8)),
                'fecha_ingreso' => PersonalNormalizer::isoDate($payload['fecha_ingreso'] ?? null),
                'estado' => $this->resolveState($payload['estado'] ?? 'ACTIVO'),
            ];

            if (Schema::hasColumn('personal', 'tipo_documento')) {
                $data['tipo_documento'] = $documentType;
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

            $data = [
                'dni' => $legacyDni,
                'nombre_completo' => PersonalNormalizer::text($payload['nombre_completo'] ?? ''),
                'puesto' => PersonalNormalizer::text($payload['puesto'] ?? ''),
                'ocupacion' => PersonalNormalizer::text($payload['ocupacion'] ?? '') ?: null,
                'contrato' => PersonalNormalizer::contract($payload['contrato'] ?? null),
                'es_supervisor' => $this->resolveSupervisor($payload),
                'fecha_ingreso' => PersonalNormalizer::isoDate($payload['fecha_ingreso'] ?? null),
                'estado' => $this->resolveState($payload['estado'] ?? 'ACTIVO'),
            ];

            if (Schema::hasColumn('personal', 'tipo_documento')) {
                $data['tipo_documento'] = $documentType;
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

    private function resolveSupervisor(array $payload): bool
    {
        if (array_key_exists('es_supervisor', $payload)) {
            return filter_var($payload['es_supervisor'], FILTER_VALIDATE_BOOLEAN);
        }

        return PersonalNormalizer::isSupervisorOccupation($payload['ocupacion'] ?? null);
    }

    private function resolveState(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'ACTIVO' : 'INACTIVO';
        }

        $state = strtoupper(trim((string) $value));

        $allowed = [
            'ACTIVO',
            'INACTIVO',
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
}
