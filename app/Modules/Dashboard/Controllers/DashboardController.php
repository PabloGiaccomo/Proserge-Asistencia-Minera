<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Modules\Dashboard\Requests\DashboardFiltersRequest;
use App\Modules\Dashboard\Resources\DashboardResource;
use App\Modules\Dashboard\Services\DashboardService;
use App\Shared\Support\ApiResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $service)
    {
    }

    public function resumen(DashboardFiltersRequest $request)
    {
        return $this->respond($request->user(), $request->validated(), 'resumen', 'DASHBOARD_RESUMEN_OK');
    }

    public function rqMina(DashboardFiltersRequest $request)
    {
        return $this->respond($request->user(), $request->validated(), 'rqMina', 'DASHBOARD_RQ_MINA_OK');
    }

    public function rqProserge(DashboardFiltersRequest $request)
    {
        return $this->respond($request->user(), $request->validated(), 'rqProserge', 'DASHBOARD_RQ_PROSERGE_OK');
    }

    public function manPower(DashboardFiltersRequest $request)
    {
        return $this->respond($request->user(), $request->validated(), 'manPower', 'DASHBOARD_MANPOWER_OK');
    }

    public function asistencia(DashboardFiltersRequest $request)
    {
        return $this->respond($request->user(), $request->validated(), 'asistencia', 'DASHBOARD_ASISTENCIA_OK');
    }

    public function faltas(DashboardFiltersRequest $request)
    {
        return $this->respond($request->user(), $request->validated(), 'faltas', 'DASHBOARD_FALTAS_OK');
    }

    public function evaluaciones(DashboardFiltersRequest $request)
    {
        return $this->respond($request->user(), $request->validated(), 'evaluaciones', 'DASHBOARD_EVALUACIONES_OK');
    }

    public function alertas(DashboardFiltersRequest $request)
    {
        return $this->respond($request->user(), $request->validated(), 'alertas', 'DASHBOARD_ALERTAS_OK');
    }

    public function principal(DashboardFiltersRequest $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $filters = $request->validated();

        $resumen = $this->service->resumen($usuario, $filters);
        if ($resumen === null) {
            return ApiResponse::error('No autorizado para ver dashboard', 'DASHBOARD_FORBIDDEN', status: 403);
        }

        $data = [
            'resumen' => $resumen,
            'rq_mina' => $this->service->rqMina($usuario, $filters),
            'rq_proserge' => $this->service->rqProserge($usuario, $filters),
            'man_power' => $this->service->manPower($usuario, $filters),
            'asistencia' => $this->service->asistencia($usuario, $filters),
            'faltas' => $this->service->faltas($usuario, $filters),
            'evaluaciones' => $this->service->evaluaciones($usuario, $filters),
            'alertas' => $this->service->alertas($usuario, $filters),
        ];

        return ApiResponse::success(
            data: DashboardResource::make($data)->resolve(),
            message: 'Dashboard principal obtenido',
            code: 'DASHBOARD_PRINCIPAL_OK',
        );
    }

    private function respond(Usuario $usuario, array $filters, string $method, string $code)
    {
        $data = $this->service->{$method}($usuario, $filters);

        if ($data === null) {
            return ApiResponse::error('No autorizado para ver dashboard', 'DASHBOARD_FORBIDDEN', status: 403);
        }

        return ApiResponse::success(
            data: DashboardResource::make($data)->resolve(),
            message: 'Dashboard obtenido',
            code: $code,
        );
    }
}
