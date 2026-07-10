<?php

namespace App\Modules\Logistica\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Personal;
use App\Modules\Epps\Services\EppService;
use App\Modules\Logistica\Services\LogisticaDashboardService;
use App\Modules\ParadaHerramientas\Services\ParadaHerramientaService;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LogisticaPageController extends WebPageController
{
    public function __construct(
        private readonly LogisticaDashboardService $service,
        private readonly EppService $eppService,
        private readonly ParadaHerramientaService $herramientaService
    )
    {
    }

    public function index(Request $request): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $permissions = session('user.permissions', []);
        $canAccessLogistica = PermissionMatrix::allows($permissions, 'logistica', 'ver');
        $canViewEpps = PermissionMatrix::allows($permissions, 'epps', 'ver');
        $canViewHerramientas = PermissionMatrix::allows($permissions, 'herramientas', 'ver');

        if (! $canAccessLogistica && ! $canViewEpps && ! $canViewHerramientas) {
            abort(403, 'No tienes permiso para ver Logistica.');
        }

        $query = $request->query();
        if (! $canViewEpps && $canViewHerramientas) {
            $query['tab'] = 'herramientas';
        }

        $data = $canViewEpps
            ? $this->service->pageData($query)
            : [
                'activeTab' => 'herramientas',
                'filters' => [],
                'options' => [],
                'metrics' => [],
            ];

        $data['canViewEpps'] = $canViewEpps;
        $data['canViewHerramientas'] = $canViewHerramientas || $canViewEpps;

        if ($canViewEpps) {
            $workerSearch = trim((string) $request->query('trabajador', ''));
            $data['eppModule'] = $this->eppService->pageData([
                'q' => $workerSearch !== '' ? $workerSearch : $request->query('q'),
                'estado' => $request->query('estado'),
                'mina_id' => $request->query('mina_id'),
                'epp_id' => $request->query('epp_id'),
                'tipo_movimiento' => $request->query('tipo_movimiento'),
                'fecha_desde' => $request->query('fecha_desde'),
                'fecha_hasta' => $request->query('fecha_hasta'),
                'per_page' => $request->query('per_page'),
            ]);
            $data['eppModule']['workerFilterChip'] = $this->workerFilterChip($workerSearch);
        }

        if ($data['canViewHerramientas']) {
            if ($canViewHerramientas) {
                $this->herramientaService->emitDeadlineReminders();
            }

            $toolFilters = [
                'q' => trim((string) $request->query('q', '')),
                'estado_lista' => trim((string) $request->query('estado_lista', '')),
            ];
            $toolItems = $this->herramientaService->listParadas($usuario, $toolFilters)->all();
            $data['herramientasModule'] = [
                'items' => $toolItems,
                'filters' => $toolFilters,
                'deadlineAlerts' => $this->herramientaService->deadlineAlerts($toolItems),
            ];
        }

        return view('logistica.index', $data);
    }

    public function updateTransport(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();

        $validated = $request->validate([
            'origen' => ['nullable', 'string', 'in:EMPRESA,ALQUILADO,OTRO'],
            'placas_asignadas' => ['nullable', 'string', 'max:2000'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date'],
            'estado_logistico' => ['required', 'string', 'in:REQUERIDO,ASIGNADO,EN_USO,RETIRADO,REEMPLAZADO,DEVUELTO,INCIDENCIA'],
            'comentario_cambio' => ['nullable', 'string', 'max:2000'],
            'incidencia_operativa' => ['nullable', 'string', 'max:2000'],
            'recepcion_fecha' => ['nullable', 'date'],
            'recepcion_estado' => ['required', 'string', 'in:PENDIENTE,RECIBIDO,INCOMPLETO,NO_LLEGO,CON_OBSERVACION'],
            'recepcion_observacion' => ['nullable', 'string', 'max:2000'],
            'capacidad_camion' => ['nullable', 'string', 'max:50'],
            'doc_vehiculo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'doc_proserge' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'doc_mantenimiento' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'doc_checklist' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
        ]);

        $data = $validated;

        foreach (['doc_vehiculo', 'doc_proserge', 'doc_mantenimiento', 'doc_checklist'] as $field) {
            if ($request->hasFile($field)) {
                $file = $request->file($field);
                $directory = 'logistica/transportes/' . $id;
                $filename = $field . '_' . Str::random(8) . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs($directory, $filename, 'local');
                $data[$field . '_path'] = $path;
            }
        }

        $this->service->updateTransportRequirement($id, $data, $usuario);

        return redirect()
            ->route('logistica.index', ['tab' => 'servicios'])
            ->with('success', 'Requerimiento de transporte actualizado.');
    }

    private function workerFilterChip(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        $worker = Personal::query()
            ->select(['id', 'nombre_completo', 'dni', 'numero_documento'])
            ->where('id', $value)
            ->orWhere('dni', $value)
            ->orWhere('numero_documento', $value)
            ->first();

        if (! $worker) {
            return [
                'value' => $value,
                'label' => $value,
            ];
        }

        return [
            'value' => $value,
            'label' => $worker->nombre_completo ?: ($worker->dni ?: ($worker->numero_documento ?: $value)),
        ];
    }
}
