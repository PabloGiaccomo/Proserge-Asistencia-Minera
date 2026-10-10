<?php

namespace App\Modules\Personal\Services;

use App\Models\ExamenMinero;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Models\PersonalMinaHistorial;
use App\Models\Usuario;
use App\Modules\Personal\Support\EmoOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LegacyEmoOutcomeRepairService
{
    public function __construct(private readonly PersonalMinaHabilitacionService $habilitation) {}

    /** @return Collection<int, array{exam_id:string, assignment_id:string, outcome:string, outcome_enum:EmoOutcome, detail:?string, from:string, to:string}> */
    public function preview(?array $catalogIds = null): Collection
    {
        return PersonalMinaExamen::query()
            ->with(['examen', 'intentos' => fn ($query) => $query->reorder('numero_intento', 'desc')])
            ->where('estado', PersonalMinaExamen::ESTADO_OBSERVADO)
            ->where('resultado', PersonalMinaExamenIntento::RESULTADO_APROBADO)
            ->whereHas('examen', fn ($query) => $query->where('categoria_operativa', ExamenMinero::CATEGORIA_EMO))
            ->when($catalogIds !== null, fn ($query) => $query->whereIn('examen_id', $catalogIds))
            ->orderBy('id')->get()
            ->map(fn (PersonalMinaExamen $exam) => $this->candidate($exam))
            ->filter()->values();
    }

    /** @return array{reparados:int, omitidos:int} */
    public function apply(?Usuario $actor, string $reason, ?array $catalogIds = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['motivo' => 'Indique el motivo de la reparacion legacy.']);
        }

        $repaired = 0;
        $skipped = 0;
        foreach ($this->preview($catalogIds) as $preview) {
            $changed = DB::transaction(function () use ($preview, $actor, $reason): bool {
                $workerId = PersonalMina::whereKey($preview['assignment_id'])->value('personal_id');
                Personal::whereKey($workerId)->lockForUpdate()->first();
                $assignment = PersonalMina::query()->whereKey($preview['assignment_id'])->lockForUpdate()->first();
                $exam = PersonalMinaExamen::query()->with('examen')
                    ->whereKey($preview['exam_id'])->lockForUpdate()->first();
                if ($exam) {
                    $exam->setRelation('intentos', $exam->intentos()->reorder('numero_intento', 'desc')->lockForUpdate()->get());
                }
                $candidate = $exam ? $this->candidate($exam) : null;
                if (! $assignment || ! $candidate) {
                    return false;
                }

                $beforeExam = ['estado' => $exam->estado, 'resultado' => $exam->resultado, 'observacion' => $exam->observacion];
                $beforeAssignment = $assignment->estadoHabilitacionActual();
                $exam->forceFill([
                    'estado' => $candidate['to'],
                    'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
                    'observacion' => $candidate['outcome_enum']->legacyObservation($candidate['detail']),
                    'usuario_actualizacion_id' => $actor?->id,
                    'fecha_actualizacion' => now(),
                ])->save();
                $this->habilitation->refreshAssignmentFromExams($assignment, $actor, false);
                $afterAssignment = $assignment->fresh()->estadoHabilitacionActual();
                PersonalMinaHistorial::query()->create([
                    'id' => (string) Str::uuid(), 'personal_mina_id' => $assignment->id,
                    'estado_anterior' => $beforeAssignment, 'estado_nuevo' => $afterAssignment,
                    'observacion' => mb_substr('Reparacion EMO legacy: '.json_encode([
                        'motivo' => $reason, 'examen_id' => $exam->id, 'antes' => $beforeExam,
                        'despues' => ['estado' => $candidate['to'], 'resultado' => 'APROBADO',
                            'outcome' => $candidate['outcome'], 'detalle' => $candidate['detail']],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 5000),
                    'usuario_id' => $actor?->id, 'fecha_cambio' => now(),
                ]);

                return true;
            }, 3);
            $changed ? $repaired++ : $skipped++;
        }

        return ['reparados' => $repaired, 'omitidos' => $skipped];
    }

    /** @return array<string, mixed>|null */
    private function candidate(PersonalMinaExamen $exam): ?array
    {
        if ($exam->estado !== PersonalMinaExamen::ESTADO_OBSERVADO
            || $exam->resultado !== PersonalMinaExamenIntento::RESULTADO_APROBADO
            || $exam->examen?->categoria_operativa !== ExamenMinero::CATEGORIA_EMO) {
            return null;
        }
        $attempt = $exam->intentos->first(fn (PersonalMinaExamenIntento $attempt): bool => $attempt->resultado !== PersonalMinaExamenIntento::RESULTADO_ANULADO);
        if ($attempt?->resultado !== PersonalMinaExamenIntento::RESULTADO_APROBADO) {
            return null;
        }
        $legacy = $attempt ? EmoOutcome::fromLegacyText($attempt->observacion) : null;
        if (! $legacy || ! in_array($legacy['outcome'], [EmoOutcome::APTO_CON_RESTRICCION, EmoOutcome::APTO_CON_OBSERVACION], true)) {
            return null;
        }
        $existing = EmoOutcome::fromLegacyText($exam->observacion);
        if ($legacy['detail'] === null && $existing && $existing['outcome'] === $legacy['outcome']) {
            $legacy['detail'] = $existing['detail'];
        }

        return [
            'exam_id' => $exam->id, 'assignment_id' => $exam->personal_mina_id,
            'outcome' => $legacy['outcome']->value, 'outcome_enum' => $legacy['outcome'],
            'detail' => $legacy['detail'], 'from' => $exam->estado, 'to' => $this->approvedState($exam),
        ];
    }

    private function approvedState(PersonalMinaExamen $exam): string
    {
        if (! $exam->fecha_vencimiento) {
            return PersonalMinaExamen::ESTADO_APROBADO;
        }
        $today = CarbonImmutable::today(config('app.display_timezone', 'America/Lima'));
        $expiration = CarbonImmutable::parse($exam->fecha_vencimiento->toDateString(), $today->getTimezone());
        if ($expiration->lt($today)) {
            return PersonalMinaExamen::ESTADO_VENCIDO;
        }

        return $today->diffInDays($expiration, false) <= 30
            ? PersonalMinaExamen::ESTADO_POR_VENCER
            : PersonalMinaExamen::ESTADO_VIGENTE;
    }
}
