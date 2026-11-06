<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Dashboard\Services\HomeSummaryDashboardService;
use App\Modules\Dashboard\Support\HomeDashboardCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class HomeDashboardPageController extends WebPageController
{
    public function show(Request $request, string $module, HomeSummaryDashboardService $service): View
    {
        $definition = HomeDashboardCatalog::all()[$module];
        $hasPeriod = ! in_array($module, ['personal', 'catalogos'], true);
        $filters = $request->validate([
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
        ]);
        $today = CarbonImmutable::now(config('app.display_timezone', 'America/Lima'));
        $filters['fecha_desde'] = $filters['fecha_desde'] ?? $today->startOfMonth()->toDateString();
        $filters['fecha_hasta'] = $filters['fecha_hasta'] ?? $today->toDateString();
        if ($hasPeriod && ($filters['fecha_hasta'] < $filters['fecha_desde']
            || CarbonImmutable::parse($filters['fecha_desde'])->addYear()->lt($filters['fecha_hasta']))) {
            throw ValidationException::withMessages(['fecha_hasta' => 'Selecciona un periodo ordenado de hasta 12 meses.']);
        }

        return view('dashboard.module-summary', [
            'definition' => $definition,
            'module' => $module,
            'hasPeriod' => $hasPeriod,
            'filters' => $filters,
            'dashboard' => $service->build($this->requireAuthenticatedUser(), $module, $filters),
        ]);
    }
}
