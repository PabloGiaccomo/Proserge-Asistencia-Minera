<?php

namespace App\Modules\Dashboard\Services;

use App\Models\Mina;
use App\Models\Oficina;
use App\Models\Personal;
use App\Models\PersonalBloqueo;
use App\Models\Taller;
use App\Models\Usuario;
use App\Modules\Asistencia\Policies\AsistenciaPolicy;
use App\Modules\Asistencia\Services\AsistenciaService;
use App\Modules\RQMina\Services\RQMinaService;
use App\Modules\RQProserge\Services\RQProsergeCoverageService;
use App\Modules\RQProserge\Services\RQProsergeService;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Collection;

class HomeSummaryDashboardService
{
    public function build(Usuario $user, string $module, array $filters): array
    {
        abort_unless(PermissionMatrix::userCanDirect($user, $module, 'dashboards'), 403, 'No tienes permiso para consultar este dashboard.');

        return match ($module) {
            'personal' => $this->personal(),
            'mi_asistencia' => $this->attendance($user, $filters),
            'rq_mina' => $this->mineRequirements($user, $filters),
            'rq_proserge' => $this->assignments($user, $filters),
            'bienestar' => $this->wellbeing($filters),
            'catalogos' => $this->catalogs(),
            default => abort(404),
        };
    }

    private function personal(): array
    {
        $states = Personal::query()->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado');
        $positions = Personal::query()->selectRaw('puesto, COUNT(*) as total')->groupBy('puesto')->orderByDesc('total')->limit(10)->get();

        return [
            'note' => 'Situacion actual del personal registrado. Los estados corresponden al modulo Personal.',
            'metrics' => $this->metrics([
                'Trabajadores registrados' => $states->sum(),
                'Activos' => (int) ($states['ACTIVO'] ?? 0),
                'Cesados' => (int) ($states['CESADO'] ?? 0),
                'Pendientes de regularizacion' => Personal::query()->where('pendiente_regularizacion', true)->count(),
            ]),
            'sections' => [
                ['title' => 'Estado laboral', 'rows' => $this->countRows($states)],
                ['title' => 'Cargos con mas personal', 'rows' => $positions->map(fn ($item) => ['label' => $item->puesto ?: 'Sin cargo registrado', 'value' => $item->total])->all()],
            ],
        ];
    }

    private function attendance(Usuario $user, array $filters): array
    {
        $groups = app(AsistenciaService::class)->listMiAsistenciaForDashboard($user, $filters);
        $missingResponsible = blank($user->personal_id) && ! app(AsistenciaPolicy::class)->canViewAllAttendances($user);

        return [
            'note' => $missingResponsible
                ? 'Esta cuenta no esta vinculada a un trabajador. Solicita al administrador completar la vinculacion en Usuarios para identificar tus grupos.'
                : 'Resumen de marcaciones registradas dentro de tu alcance durante el periodo seleccionado.',
            'metrics' => $this->metrics([
                'Grupos asignados' => $groups->count(),
                'Personal programado' => $groups->sum('total'),
                'Presentes' => $groups->sum('presentes'),
                'Pendientes de marcar' => $groups->sum('pendientes'),
            ]),
            'sections' => [
                ['title' => 'Estado de asistencia', 'rows' => $this->countRows($groups->countBy('estado_asistencia'))],
                ['title' => 'Grupos por turno', 'rows' => $this->countRows($groups->countBy('turno'))],
            ],
        ];
    }

    private function mineRequirements(Usuario $user, array $filters): array
    {
        $query = app(RQMinaService::class)->dashboardQueryForUser($user)
            ->whereDate('fecha_inicio', '<=', $filters['fecha_hasta'])
            ->whereDate('fecha_fin', '>=', $filters['fecha_desde'])
            ->when(! empty($filters['mina_id']), fn ($query) => $query->where('mina_id', $filters['mina_id']));
        $states = (clone $query)->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado');
        $recent = (clone $query)->with('mina:id,nombre')->orderBy('fecha_inicio')->limit(20)->get();
        $rows = $recent->map(function ($rq): array {
            $row = ['label' => ($rq->mina?->nombre ?: $rq->destino_nombre ?: 'Sin destino').' · '.$rq->area.' · '.$rq->fecha_inicio->format('d/m/Y'), 'value' => $this->label($rq->estado)];
            $row['url'] = route('inicio.dashboard.rq_mina.parada', $rq->id);

            return $row;
        })->all();

        return [
            'note' => 'Paradas que coinciden con el periodo seleccionado, dentro de tu alcance. Se muestran hasta 20 paradas en el detalle.',
            'metrics' => $this->metrics(['Requerimientos' => $states->sum(), 'Borradores' => (int) ($states['BORRADOR'] ?? 0), 'Enviados' => (int) ($states['ENVIADO'] ?? 0), 'Cerrados' => (int) ($states['CERRADO'] ?? 0)]),
            'sections' => [['title' => 'Estado de requerimientos', 'rows' => $this->countRows($states)], ['title' => 'Dashboards por parada', 'rows' => $rows]],
        ];
    }

    private function assignments(Usuario $user, array $filters): array
    {
        $items = app(RQProsergeService::class)->listForUser($user, $filters);
        $items->load('rqMina.detalle.asignaciones');
        $coverage = $items->unique('rq_mina_id')->map(fn ($rq): array => app(RQProsergeCoverageService::class)->calculateForRq($rq));
        $canView = PermissionMatrix::userCanDirect($user, 'rq_proserge', 'ver');
        $rows = $items->take(20)->map(function ($rq) use ($canView): array {
            $row = ['label' => ($rq->mina?->nombre ?: 'Sin mina').' · '.$rq->rqMina->fecha_inicio->format('d/m/Y'), 'value' => $this->label($rq->estado)];
            if ($canView) {
                $row['url'] = route('rq-proserge.show', $rq->id);
            }

            return $row;
        })->values()->all();

        return [
            'note' => 'Requerimientos de paradas que coinciden con el periodo. La cobertura conserva los criterios de titulares y respaldo del modulo. Se muestran hasta 20 requerimientos.',
            'metrics' => $this->metrics(['Requerimientos' => $items->count(), 'Personal requerido' => $coverage->sum('global.total_objetivo'), 'Titulares cubiertos' => $coverage->sum('global.titular_efectivo'), 'Brecha de titulares' => $coverage->sum('global.brecha_titular')]),
            'sections' => [['title' => 'Estado de requerimientos', 'rows' => $this->countRows($items->countBy('estado'))], ['title' => 'Requerimientos del periodo', 'rows' => $rows]],
        ];
    }

    private function wellbeing(array $filters): array
    {
        $blocks = PersonalBloqueo::query()->visiblesEnRango($filters['fecha_desde'], $filters['fecha_hasta'])->get(['tipo', 'personal_id']);
        $types = $blocks->groupBy('tipo')->map(fn ($items, $type) => ['label' => (new PersonalBloqueo(['tipo' => $type]))->tipoLabel(), 'value' => $items->count()])->values()->all();

        return [
            'note' => 'Bloqueos activos y visibles para planificacion que coinciden con el periodo seleccionado.',
            'metrics' => $this->metrics(['Bloqueos vigentes en el periodo' => $blocks->count(), 'Trabajadores no disponibles' => $blocks->pluck('personal_id')->unique()->count(), 'Vacaciones' => $blocks->where('tipo', 'vacaciones')->count(), 'Descansos medicos' => $blocks->where('tipo', 'descanso_medico')->count()]),
            'sections' => [['title' => 'Motivos de no disponibilidad', 'rows' => $types]],
        ];
    }

    private function catalogs(): array
    {
        $sections = [];
        $metrics = [];
        foreach (['Minas' => Mina::class, 'Talleres' => Taller::class, 'Oficinas' => Oficina::class] as $label => $model) {
            $states = $model::query()->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado');
            $metrics[$label.' registrados'] = $states->sum();
            $sections[] = ['title' => $label.' por estado', 'rows' => $this->countRows($states)];
        }

        return ['note' => 'Estado actual de los catalogos registrados. Los accesos a cada catalogo mantienen sus permisos propios.', 'metrics' => $this->metrics($metrics), 'sections' => $sections];
    }

    private function metrics(array $values): array
    {
        return collect($values)->map(fn ($value, $label) => compact('label', 'value'))->values()->all();
    }

    private function countRows(Collection $values): array
    {
        return $values->map(fn ($value, $key) => ['label' => $this->label((string) $key), 'value' => (int) $value])->values()->all();
    }

    private function label(string $value): string
    {
        return $value === '' ? 'Sin estado registrado' : ucfirst(mb_strtolower(str_replace('_', ' ', $value)));
    }
}
