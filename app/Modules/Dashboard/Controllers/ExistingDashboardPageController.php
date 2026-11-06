<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Mina;
use App\Modules\Asistencia\Controllers\AsistenciaPageController;
use App\Modules\Evaluaciones\Policies\EvaluacionesPolicy;
use App\Modules\Evaluaciones\Services\EvaluacionesDashboardService;
use App\Modules\Faltas\Controllers\FaltasPageController;
use App\Modules\Logistica\Services\LogisticaDashboardService;
use App\Modules\ManPower\Controllers\ManPowerPageController;
use App\Modules\RQMina\Services\ParadaDashboardService;
use App\Modules\RQMina\Services\RQMinaService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExistingDashboardPageController extends WebPageController
{
    public function asistencias(Request $request, AsistenciaPageController $controller): View
    {
        $this->prepareAttendancePeriod($request);

        return $controller->index($request)->with('standaloneDashboard', true);
    }

    public function faltas(Request $request, FaltasPageController $controller): View
    {
        $this->prepareAttendancePeriod($request);

        return $controller->index($request)->with('standaloneDashboard', true);
    }

    public function man_power(Request $request, ManPowerPageController $controller): View
    {
        $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'rq_mina_id' => ['nullable', 'uuid'],
            'plan_id' => ['nullable', 'uuid'],
            'actividad_id' => ['nullable', 'uuid'],
            'turno' => ['nullable', 'in:DIA,NOCHE'],
            'dashboard_modo' => ['nullable', 'in:resumen,turnos,cargos,cobertura'],
            'q' => ['nullable', 'string', 'max:191'],
        ]);
        $request->query->set('vista', 'dashboard');

        return $controller->grupos($request)->with('standaloneDashboard', true);
    }

    public function evaluaciones(Request $request, EvaluacionesDashboardService $service, EvaluacionesPolicy $policy): View
    {
        $filters = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'mina' => ['nullable', 'string', 'max:36'],
            'cargo' => ['nullable', 'string', 'max:191'],
            'q' => ['nullable', 'string', 'max:191'],
            'evaluacion_tipo' => ['nullable', 'in:desempeno,supervisores,residentes'],
            'origen' => ['nullable', 'in:ASISTENCIA,DIRECTA,HISTORICA,ACTUAL,LEGADO'],
        ]);
        $today = CarbonImmutable::now(config('app.display_timezone', 'America/Lima'));
        $filters = array_replace(['desde' => $today->startOfMonth()->toDateString(), 'hasta' => $today->toDateString(), 'mina' => '', 'cargo' => '', 'q' => '', 'evaluacion_tipo' => '', 'origen' => ''], array_filter($filters, fn ($value) => $value !== null));
        if ($filters['hasta'] < $filters['desde'] || CarbonImmutable::parse($filters['desde'])->addYear()->lt($filters['hasta'])) {
            throw ValidationException::withMessages(['hasta' => 'Selecciona un periodo ordenado de hasta 12 meses.']);
        }
        $user = $this->requireAuthenticatedUser();
        $data = [
            'standaloneDashboard' => true,
            'dashboardFilters' => $filters,
            'dashboard' => $service->build($user, $filters),
            'mines' => Mina::query()->activeOperational()
                ->when(! $policy->isPrivileged($user), fn ($query) => $query->whereIn('id', $user->scopesMina()->pluck('mina_id')))
                ->orderBy('nombre')->get(['id', 'nombre']),
        ];

        return view($request->header('X-Evaluation-Dashboard') === '1' ? 'evaluaciones.partials.dashboard' : 'dashboard.evaluaciones', $data);
    }

    public function logistica(Request $request, LogisticaDashboardService $service): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:191'],
            'parada_id' => ['nullable', 'uuid'],
            'minas' => ['nullable', 'array', 'max:100'],
            'minas.*' => ['nullable', 'uuid'],
            'epps' => ['nullable', 'array', 'max:100'],
            'epps.*' => ['nullable', 'uuid'],
            'cargos' => ['nullable', 'array', 'max:100'],
            'cargos.*' => ['nullable', 'string', 'max:191'],
            'estados' => ['nullable', 'array', 'max:20'],
            'estados.*' => ['nullable', 'string', 'max:50'],
            'epp_estado' => ['nullable', 'array', 'max:20'],
            'epp_estado.*' => ['nullable', 'string', 'max:50'],
            'tallas' => ['nullable', 'array', 'max:100'],
            'tallas.*' => ['nullable', 'string', 'max:50'],
            'ficha' => ['nullable', 'in:completa,incompleta'],
            'talla_estado' => ['nullable', 'in:con_talla,sin_talla'],
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
        ]);

        // The standalone view cannot select operational tabs via query parameters.
        return view('dashboard.logistica', $service->pageData([...$filters, 'tab' => 'dashboard'], $this->requireAuthenticatedUser()));
    }

    public function parada(Request $request, string $rqMina, RQMinaService $requirements, ParadaDashboardService $service): View
    {
        $filters = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'plan_id' => ['nullable', 'uuid'],
            'turno' => ['nullable', 'in:DIA,NOCHE'],
        ]);
        $user = $this->requireAuthenticatedUser();
        $item = $requirements->dashboardQueryForUser($user)->findOrFail($rqMina);

        return view('rq-mina.dashboard', ['standaloneDashboard' => true, 'dashboard' => $service->build($user, $item, $filters)]);
    }

    private function prepareAttendancePeriod(Request $request): void
    {
        $filters = $request->validate([
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $today = CarbonImmutable::now(config('app.display_timezone', 'America/Lima'));
        $from = $filters['fecha_desde'] ?? $today->startOfMonth()->toDateString();
        $to = $filters['fecha_hasta'] ?? $today->toDateString();
        if ($to < $from || CarbonImmutable::parse($from)->addYear()->lt($to)) {
            throw ValidationException::withMessages(['fecha_hasta' => 'Selecciona un periodo ordenado de hasta 12 meses.']);
        }
        $request->query->set('fecha_desde', $from);
        $request->query->set('fecha_hasta', $to);
    }
}
