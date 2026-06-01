<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\Usuario;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PersonalContratoService
{
    public function listForPersonal(Personal $personal, ?Usuario $user = null)
    {
        if (!Schema::hasTable('personal_contratos')) {
            return collect();
        }

        $this->ensureHistoricalContractForCeased($personal, $user);

        return PersonalContrato::query()
            ->with(['activadoPor.personal', 'cerradoPor.personal'])
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
            ->with(['activadoPor.personal', 'cerradoPor.personal'])
            ->where('personal_id', $personal->id)
            ->find($contractId);
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
            ->where('estado', 'ACTIVO')
            ->latest('contrato_numero')
            ->first();

        if ($active) {
            return $active;
        }

        return PersonalContrato::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'contrato_numero' => $this->nextContractNumber($personal),
            'estado' => 'ACTIVO',
            'fecha_inicio' => $this->currentContractStartDate($personal),
            'fecha_fin' => $this->currentContractEndDate($personal),
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

            $contract->forceFill([
                'estado' => 'CERRADO',
                'fecha_inicio' => $contract->fecha_inicio ?: $this->currentContractStartDate($personal),
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

            return $contract->fresh(['activadoPor.personal', 'cerradoPor.personal']);
        });
    }

    public function activateNextContract(Personal $personal, string $fechaInicio, ?string $fechaFin, Usuario $user): PersonalContrato
    {
        return DB::transaction(function () use ($personal, $fechaInicio, $fechaFin, $user): PersonalContrato {
            $personal = Personal::query()->with(['fichaColaborador.link', 'minas'])->findOrFail($personal->id);
            $fechaInicio = PersonalNormalizer::isoDate($fechaInicio) ?: Carbon::today()->toDateString();
            $fechaFin = PersonalNormalizer::isoDate($fechaFin);

            $previous = $this->latestContract($personal);
            if (!$previous || $previous->estado !== 'CERRADO') {
                $motivo = trim((string) ($personal->motivo_cese ?? ''));
                $previous = $this->closeCurrentContract(
                    $personal,
                    $motivo !== '' ? $motivo : 'Termino de contrato',
                    $personal->cesadoPor ?: $user,
                    optional($personal->fecha_cese)->toDateString() ?: Carbon::today()->toDateString(),
                );
            }

            $personalData = [
                'estado' => 'ACTIVO',
                'fecha_ingreso' => $fechaInicio,
            ];

            foreach (['fecha_cese', 'motivo_cese', 'cesado_at', 'cesado_by_usuario_id'] as $column) {
                if (Schema::hasColumn('personal', $column)) {
                    $personalData[$column] = null;
                }
            }

            $personal->forceFill($personalData)->save();

            $ficha = $personal->fichaColaborador;
            if ($ficha) {
                $data = is_array($ficha->datos_json ?? null) ? $ficha->datos_json : [];
                $data['fecha_ingreso'] = $fechaInicio;
                $data['fecha_fin_contrato'] = $fechaFin ?: '';
                $data['fecha_cese'] = '';

                $ficha->forceFill([
                    'datos_json' => $data,
                    'datos_detectados_json' => array_merge(is_array($ficha->datos_detectados_json ?? null) ? $ficha->datos_detectados_json : [], $data),
                ])->save();
            }

            $personal->refresh();
            $personal->loadMissing(['fichaColaborador.link', 'minas']);

            $contract = PersonalContrato::query()->create([
                'id' => (string) Str::uuid(),
                'personal_id' => $personal->id,
                'contrato_numero' => $this->nextContractNumber($personal),
                'estado' => 'ACTIVO',
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'activado_at' => now(),
                'activado_by_usuario_id' => $user->id,
                'origen_contrato_id' => $previous?->id,
                'personal_ficha_id' => $personal->fichaColaborador?->id,
                'snapshot_inicial_json' => $this->buildSnapshot($personal, 'activacion_contrato', [
                    'origen_contrato_id' => $previous?->id,
                    'origen_contrato_numero' => $previous?->contrato_numero,
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                ]),
            ]);

            return $contract->fresh(['activadoPor.personal', 'cerradoPor.personal']);
        });
    }

    public function contractLabel(PersonalContrato $contract): string
    {
        $inicio = optional($contract->fecha_inicio)->format('d/m/Y') ?: 'sin inicio';
        $fin = optional($contract->fecha_fin)->format('d/m/Y') ?: 'vigente';

        return 'Contrato ' . $contract->contrato_numero . ': ' . $inicio . ' al ' . $fin;
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
