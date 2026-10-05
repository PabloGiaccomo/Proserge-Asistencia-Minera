<?php

namespace App\Modules\Personal\Services;

use App\Models\ExamenMinero;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MiningExamCatalogAuditService
{
    public function report(): array
    {
        $exams = ExamenMinero::query()
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'activo', 'tipo', 'vigencia_dias', 'tiene_vigencia', 'max_intentos', 'permite_reintento', 'requiere_nota', 'nota_minima', 'permite_convalidacion', 'precio', 'moneda']);

        $requirements = DB::table('mina_requisitos')
            ->whereNotNull('examen_id')
            ->orderBy('id')->get()
            ->groupBy('examen_id');
        $workerExams = DB::table('personal_mina_examenes')
            ->select('examen_id')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('examen_id')
            ->pluck('total', 'examen_id');
        $attempts = DB::table('personal_mina_examen_intentos as i')
            ->join('personal_mina_examenes as e', 'e.id', '=', 'i.personal_mina_examen_id')
            ->select('e.examen_id')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('e.examen_id')
            ->pluck('total', 'examen_id');
        $prices = DB::table('examen_minero_precios')
            ->select('examen_id')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('examen_id')
            ->pluck('total', 'examen_id');
        $courses = Schema::hasTable('personal_curso_registros')
            ? DB::table('personal_curso_registros')->select('examen_id')->selectRaw('COUNT(*) AS total')->groupBy('examen_id')->pluck('total', 'examen_id')
            : collect();
        $mines = DB::table('minas')->pluck('nombre', 'id');

        $rows = $exams->map(function (ExamenMinero $exam) use ($requirements, $workerExams, $attempts, $prices, $courses, $mines): array {
            $mineRequirements = $requirements->get($exam->id, collect());

            return [
                'id' => $exam->id,
                'nombre' => $exam->nombre,
                'activo' => (bool) $exam->activo,
                'tipo' => $exam->tipo,
                'vigencia_dias' => $exam->vigencia_dias,
                'max_intentos' => $exam->max_intentos,
                'nota_minima' => $exam->nota_minima,
                'permite_convalidacion' => (bool) $exam->permite_convalidacion,
                'requiere_nota' => (bool) $exam->requiere_nota,
                'tiene_vigencia' => (bool) $exam->tiene_vigencia,
                'permite_reintento' => (bool) $exam->permite_reintento,
                'precio' => $exam->precio,
                'moneda' => $exam->moneda,
                'minas_nombres' => $mineRequirements->where('activo', true)->pluck('mina_id')->unique()->map(fn ($id) => $mines[$id] ?? 'Mina historica')->values()->all(),
                'requisitos' => $mineRequirements->values()->all(),
                'requisitos_activos' => $mineRequirements->where('activo', true)->count(),
                'requisitos_historicos' => $mineRequirements->where('activo', false)->count(),
                'minas_activas' => $mineRequirements->where('activo', true)->pluck('mina_id')->unique()->values()->all(),
                'examenes_trabajador' => (int) ($workerExams[$exam->id] ?? 0),
                'intentos' => (int) ($attempts[$exam->id] ?? 0),
                'cursos_globales' => (int) ($courses[$exam->id] ?? 0),
                'precios' => (int) ($prices[$exam->id] ?? 0),
            ];
        })->all();

        $suggestions = [];
        foreach ($rows as $firstIndex => $first) {
            $firstName = $this->normalizedName($first['nombre']);
            foreach (array_slice($rows, $firstIndex + 1) as $second) {
                $secondName = $this->normalizedName($second['nombre']);
                if (abs(strlen($firstName) - strlen($secondName)) > 2) {
                    continue;
                }
                $distance = levenshtein($firstName, $secondName);
                if ($distance > 2) {
                    continue;
                }

                $rules = ['tipo', 'tiene_vigencia', 'vigencia_dias', 'permite_reintento', 'max_intentos', 'requiere_nota', 'nota_minima', 'permite_convalidacion', 'precio', 'moneda'];
                $suggestions[] = [
                    'primero_id' => $first['id'],
                    'segundo_id' => $second['id'],
                    'tipo' => $distance === 0 ? 'nombre_normalizado' : 'nombre_parecido',
                    'reglas_distintas' => array_values(array_filter($rules, fn (string $rule): bool => $first[$rule] != $second[$rule])),
                ];
            }
        }

        return ['examenes' => $rows, 'sugerencias' => $suggestions];
    }

    private function normalizedName(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($name))));
    }
}
