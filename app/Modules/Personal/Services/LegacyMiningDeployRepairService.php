<?php

namespace App\Modules\Personal\Services;

use App\Models\ExamenMinero;
use App\Models\ExamenMineroCategoriaHistorial;
use App\Models\Personal;
use App\Models\PersonalEmoCita;
use App\Models\PersonalEmoCitaHistorial;
use App\Models\PersonalMina;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaHistorial;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LegacyMiningDeployRepairService
{
    public const ORIGIN = 'SISTEMA_DESPLIEGUE_EMO_20261010';

    public function run(): void
    {
        $this->classifyExactMedicalCatalogs();
        if (! $this->hasColumns([
            'examenes_mineros' => ['id', 'nombre', 'categoria_operativa'],
            'personal' => ['id'],
            'personal_mina' => ['id', 'personal_id', 'mina_id', 'estado', 'estado_habilitacion', 'fecha_habilitacion', 'usuario_actualizacion_id', 'updated_at'],
            'personal_mina_examenes' => ['id', 'personal_mina_id', 'examen_id', 'mina_requisito_id', 'estado', 'resultado', 'observacion', 'fecha_vencimiento', 'fecha_actualizacion', 'usuario_actualizacion_id', 'intentos_ciclo_desde', 'intentos_ciclo_numero_desde', 'updated_at'],
            'personal_mina_examen_intentos' => ['id', 'personal_mina_examen_id', 'numero_intento', 'resultado', 'observacion', 'updated_at'],
            'mina_requisitos' => ['id', 'mina_id', 'examen_id', 'activo', 'obligatorio'],
            'personal_mina_historial' => ['id', 'personal_mina_id', 'estado_anterior', 'estado_nuevo', 'observacion', 'usuario_id', 'fecha_cambio', 'created_at', 'updated_at'],
        ])) {
            // Historical installations without this domain/audit schema remain untouched.
            return;
        }

        $exactIds = ExamenMinero::query()->where('categoria_operativa', 'EMO')->get(['id', 'nombre'])
            ->filter(fn ($catalog): bool => $this->isExactMedicalName($catalog->nombre))->pluck('id')->all();
        if ($exactIds !== []) {
            app(LegacyEmoOutcomeRepairService::class)->apply(null,
                self::ORIGIN.': resultado medico habilitante legado confirmado.', $exactIds);
        }

        PersonalMinaExamen::query()->where('estado', 'NO_APLICA')
            ->whereHas('intentos', fn ($query) => $query->where('resultado', 'PENDIENTE'))
            ->chunkById(100, function ($exams): void {
                foreach ($exams as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        $workerId = PersonalMina::whereKey($candidate->personal_mina_id)->value('personal_id');
                        Personal::whereKey($workerId)->lockForUpdate()->first();
                        if ($this->hasColumns(['personal_emo_citas' => ['id', 'personal_id']])) {
                            PersonalEmoCita::where('personal_id', $workerId)->orderBy('id')->lockForUpdate()->get();
                        }
                        $assignment = PersonalMina::whereKey($candidate->personal_mina_id)->lockForUpdate()->first();
                        $exam = PersonalMinaExamen::whereKey($candidate->id)->lockForUpdate()->first();
                        if (! $assignment || ! $exam) {
                            return;
                        }
                        $exam->setRelation('asignacion', $assignment);
                        $attempts = $exam->intentos()->lockForUpdate()->get();
                        $this->closeNotApplicablePendingLocked($exam, $attempts);
                    }, 3);
                }
            });
    }

    /** Caller holds worker/appointment (EMO), assignment, exam and attempt locks in a transaction. */
    public function closeNotApplicablePendingLocked(PersonalMinaExamen $exam, Collection $attempts,
        ?Usuario $actor = null, ?string $retainedAppointmentId = null): bool
    {
        if ($exam->estado !== PersonalMinaExamen::ESTADO_NO_APLICA) {
            return false;
        }
        $own = $attempts->where('personal_mina_examen_id', $exam->id);
        $pending = $own->where('resultado', 'PENDIENTE');
        if ($pending->isEmpty()) {
            return false;
        }
        if ($pending->pluck('emo_cita_id')->filter()->isNotEmpty() && ! $this->hasColumns([
            'personal_emo_citas' => ['id', 'personal_id', 'estado', 'revision', 'usuario_actualizacion_id', 'updated_at'],
            'personal_emo_cita_historial' => ['id', 'emo_cita_id', 'evento', 'origen', 'usuario_id', 'fecha_cambio', 'valores_anteriores_json', 'valores_nuevos_json', 'created_at', 'updated_at'],
        ])) {
            return false;
        }
        $before = ['estado' => $exam->estado, 'intentos_ciclo_desde' => $exam->intentos_ciclo_desde?->toIso8601String(),
            'intentos_ciclo_numero_desde' => $exam->intentos_ciclo_numero_desde,
            'pendientes' => $pending->map(fn ($attempt) => ['id' => $attempt->id, 'numero' => $attempt->numero_intento, 'resultado' => $attempt->resultado])->values()->all()];
        foreach ($pending as $attempt) {
            $attempt->forceFill(['resultado' => 'ANULADO'])->save();
        }
        foreach ($pending->pluck('emo_cita_id')->filter()->unique() as $id) {
            $appointment = PersonalEmoCita::whereKey($id)->lockForUpdate()->first();
            if (! $appointment) {
                continue;
            }
            $hasOtherPending = $appointment->intentos()->where('resultado', 'PENDIENTE')->exists();
            $previous = ['estado' => $appointment->estado, 'revision' => $appointment->revision];
            $appointment->forceFill([
                'estado' => $hasOtherPending || $retainedAppointmentId === $id ? $appointment->estado : 'ANULADA',
                'revision' => $appointment->revision + 1, 'usuario_actualizacion_id' => $actor?->id,
            ])->save();
            PersonalEmoCitaHistorial::create([
                'emo_cita_id' => $id, 'evento' => 'REPARACION_NO_APLICA', 'origen' => 'SISTEMA',
                'usuario_id' => $actor?->id, 'fecha_cambio' => now(), 'valores_anteriores_json' => $previous,
                'valores_nuevos_json' => ['estado' => $appointment->estado, 'revision' => $appointment->revision,
                    'personal_mina_examen_id' => $exam->id, 'origen' => self::ORIGIN,
                    'motivo' => 'Pendiente heredado incompatible con NO_APLICA.'],
            ]);
        }
        $exam->forceFill(['intentos_ciclo_desde' => now(), 'intentos_ciclo_numero_desde' => 1 + (int) $own->max('numero_intento')])->save();
        $assignmentState = $exam->asignacion->estadoHabilitacionActual();
        PersonalMinaHistorial::create([
            'id' => (string) Str::uuid(), 'personal_mina_id' => $exam->personal_mina_id,
            'estado_anterior' => $assignmentState, 'estado_nuevo' => $assignmentState,
            'usuario_id' => $actor?->id, 'fecha_cambio' => now(),
            'observacion' => json_encode(['origen' => self::ORIGIN,
                'motivo' => 'Anular pendientes heredados de NO_APLICA y cerrar ciclo sin borrar historial.',
                'examen_id' => $exam->id, 'antes' => $before,
                'despues' => ['estado' => $exam->estado, 'resultado_intentos' => 'ANULADO',
                    'intentos_ciclo_desde' => $exam->intentos_ciclo_desde?->toIso8601String(),
                    'intentos_ciclo_numero_desde' => $exam->intentos_ciclo_numero_desde]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return true;
    }

    private function classifyExactMedicalCatalogs(): void
    {
        if (! $this->hasColumns([
            'examenes_mineros' => ['id', 'nombre', 'categoria_operativa'],
            'examen_minero_categoria_historial' => ['id', 'examen_id', 'categoria_anterior', 'categoria_nueva', 'motivo', 'usuario_id', 'fecha_cambio', 'created_at', 'updated_at'],
        ])) {
            return;
        }
        DB::table('examenes_mineros')->where('categoria_operativa', 'OTRO')->orderBy('id')
            ->chunkById(100, function ($catalogs): void {
                foreach ($catalogs as $catalog) {
                    if (! $this->isExactMedicalName($catalog->nombre)) {
                        continue;
                    }
                    DB::transaction(function () use ($catalog): void {
                        $locked = DB::table('examenes_mineros')->where('id', $catalog->id)->lockForUpdate()->first();
                        if (! $locked || $locked->categoria_operativa !== 'OTRO' || ! $this->isExactMedicalName($locked->nombre)) {
                            return;
                        }
                        DB::table('examenes_mineros')->where('id', $locked->id)->update(['categoria_operativa' => 'EMO']);
                        ExamenMineroCategoriaHistorial::create([
                            'examen_id' => $locked->id, 'categoria_anterior' => 'OTRO', 'categoria_nueva' => 'EMO',
                            'usuario_id' => null, 'fecha_cambio' => now(),
                            'motivo' => self::ORIGIN.': reparar catalogo con nombre normalizado exacto EXAMEN MEDICO; antes=OTRO; despues=EMO.',
                        ]);
                    }, 3);
                }
            });
    }

    private function isExactMedicalName(string $name): bool
    {
        return preg_replace('/\s+/u', ' ', strtoupper(trim(Str::ascii($name)))) === 'EXAMEN MEDICO';
    }

    private function hasColumns(array $tables): bool
    {
        foreach ($tables as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                return false;
            }
        }

        return true;
    }
}
