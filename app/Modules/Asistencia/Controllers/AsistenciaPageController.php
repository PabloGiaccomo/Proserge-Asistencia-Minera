<?php

namespace App\Modules\Asistencia\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Asistencia\Resources\AsistenciaGrupoResource;
use App\Modules\Asistencia\Services\AsistenciaDashboardService;
use App\Modules\Asistencia\Services\AsistenciaService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AsistenciaPageController extends WebPageController
{
    public function __construct(
        private readonly AsistenciaService $service,
        private readonly AsistenciaDashboardService $dashboardService,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = $this->dashboardFilters($request);
        $dashboard = $this->dashboardService->build($this->requireAuthenticatedUser(), $filters);

        return view('asistencia.index', compact('dashboard'));
    }

    public function grupos(Request $request): View
    {
        return $this->index($request);
    }

    public function show(string $grupoId): View
    {
        $grupo = $this->groupResource($grupoId);

        return view('asistencia.show', compact('grupo'));
    }

    public function marcar(string $grupoId): View
    {
        $grupo = $this->groupResource($grupoId);

        return view('asistencia.marcar', compact('grupo'));
    }

    public function masivo(string $grupoId): View
    {
        $grupo = $this->groupResource($grupoId);

        return view('asistencia.masivo', compact('grupo'));
    }

    public function resumen(Request $request): View
    {
        return $this->index($request);
    }

    public function mina(Request $request): View
    {
        return $this->index($request);
    }

    public function parada(Request $request): View
    {
        return $this->index($request);
    }

    public function supervisor(Request $request): View
    {
        return $this->index($request);
    }

    public function personal(Request $request): View
    {
        return $this->index($request);
    }

    public function alertas(Request $request): View
    {
        return $this->index($request);
    }

    public function marcarPost(Request $request, string $grupoId): RedirectResponse
    {
        $payload = $request->validate($this->markRules());
        $result = $this->service->marcarAsistencia($this->requireAuthenticatedUser(), $grupoId, $payload);

        return $this->attendanceResult($result, $grupoId);
    }

    public function marcarMasivoPost(Request $request, string $grupoId): RedirectResponse
    {
        $payload = $request->validate([
            'grupo_trabajo_detalle_ids' => ['required', 'array', 'min:1'],
            'grupo_trabajo_detalle_ids.*' => ['string', 'size:36', 'exists:grupo_trabajo_detalle,id'],
            'estado' => ['required', 'string', 'in:PRESENTE,AUSENTE,TARDANZA,JUSTIFICADO,NO_CORRESPONDE'],
            'hora_marcado' => ['nullable', 'date_format:H:i'],
            'motivo_estado' => ['nullable', 'string', 'required_if:estado,JUSTIFICADO,NO_CORRESPONDE'],
            'observaciones' => ['nullable', 'string'],
        ]);
        $result = $this->service->marcarMasivoPorGrupoId($this->requireAuthenticatedUser(), $grupoId, $payload);

        return $this->attendanceResult($result, $grupoId);
    }

    public function cerrar(string $grupoId): RedirectResponse
    {
        $result = $this->service->cerrarAsistencia($this->requireAuthenticatedUser(), $grupoId);

        return $this->attendanceResult($result, $grupoId);
    }

    public function reabrir(string $grupoId): RedirectResponse
    {
        $result = $this->service->reabrirAsistencia($this->requireAuthenticatedUser(), $grupoId);

        return $this->attendanceResult($result, $grupoId);
    }

    private function dashboardFilters(Request $request): array
    {
        $filters = $request->validate([
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
            'turno' => ['nullable', 'string', 'in:DIA,NOCHE'],
            'mina_id' => ['nullable', 'string', 'size:36'],
            'supervisor_id' => ['nullable', 'string', 'size:36'],
            'estado_asistencia' => ['nullable', 'string', 'in:PENDIENTE,REGISTRADO,CERRADO'],
            'buscar' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $filters['fecha_desde'] ??= Carbon::today()->startOfMonth()->toDateString();
        $filters['fecha_hasta'] ??= Carbon::today()->toDateString();

        if (Carbon::parse($filters['fecha_desde'])->diffInDays(Carbon::parse($filters['fecha_hasta'])) > 366) {
            throw ValidationException::withMessages([
                'fecha_hasta' => 'Selecciona un periodo máximo de 12 meses para mantener el dashboard ágil.',
            ]);
        }

        return $filters;
    }

    private function groupResource(string $grupoId): ?array
    {
        $group = $this->service->getGrupo($this->requireAuthenticatedUser(), $grupoId);

        return $group ? AsistenciaGrupoResource::make($group)->resolve(request()) : null;
    }

    private function markRules(): array
    {
        return [
            'grupo_trabajo_detalle_id' => ['required', 'string', 'size:36', 'exists:grupo_trabajo_detalle,id'],
            'estado' => ['required', 'string', 'in:PRESENTE,AUSENTE,TARDANZA,JUSTIFICADO,NO_CORRESPONDE'],
            'hora_marcado' => ['nullable', 'date_format:H:i'],
            'motivo_estado' => ['nullable', 'string', 'required_if:estado,JUSTIFICADO,NO_CORRESPONDE'],
            'observaciones' => ['nullable', 'string'],
        ];
    }

    private function attendanceResult(array $result, string $grupoId): RedirectResponse
    {
        if ($result['success'] ?? false) {
            return redirect()->route('asistencia.show', $grupoId)->with('success', $result['message']);
        }

        return back()->withInput()->with('error', $result['message'] ?? 'No se pudo completar la accion.');
    }
}
