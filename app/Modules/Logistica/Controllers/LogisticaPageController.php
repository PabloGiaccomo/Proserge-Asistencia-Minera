<?php

namespace App\Modules\Logistica\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Personal;
use App\Models\RQMinaActividadTransporte;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppService;
use App\Modules\Logistica\Services\LogisticaDashboardService;
use App\Modules\ParadaHerramientas\Services\ParadaHerramientaService;
use App\Support\Rbac\MinaScopeAccess;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LogisticaPageController extends WebPageController
{
    public function __construct(
        private readonly LogisticaDashboardService $service,
        private readonly EppService $eppService,
        private readonly ParadaHerramientaService $herramientaService
    ) {}

    public function index(Request $request): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $permissions = session('user.permissions', []);
        $canAccessLogistica = PermissionMatrix::allows($permissions, 'logistica', 'ver');
        $canViewEpps = PermissionMatrix::allows($permissions, 'epps', 'ver');
        $canViewHerramientas = PermissionMatrix::allows($permissions, 'herramientas', 'ver');
        $canViewServicios = PermissionMatrix::allowsDirect($permissions, 'logistica', 'ver_logistica_servicios');
        $eppTabActions = array_diff_key(PermissionCatalog::logisticsTabActions(), array_flip(['herramientas', 'servicios']));
        $needsEppContext = $canViewEpps || PermissionMatrix::allowsDirectAny($permissions, 'logistica', array_values($eppTabActions));
        $canViewEppWorkspace = $canViewEpps || PermissionMatrix::allowsDirect($permissions, 'logistica', 'ver_logistica_entregas');
        $eppActor = $needsEppContext ? $usuario : null;

        if (! $canAccessLogistica && ! $canViewEpps && ! $canViewHerramientas) {
            abort(403, 'No tienes permiso para ver Logistica.');
        }

        $query = $request->query();
        if (! $needsEppContext && $canViewHerramientas) {
            $query['tab'] = 'herramientas';
        }

        // Servicios y alquileres no depende del permiso de EPP: se arma un
        // contexto minimo con paradas y requerimientos de transporte.
        $data = match (true) {
            $needsEppContext => $this->service->pageData($query, $eppActor),
            $canViewServicios || $canViewHerramientas => $this->service->serviciosPageData($query),
            default => [
                'activeTab' => 'herramientas',
                'filters' => [],
                'options' => [],
                'metrics' => [],
            ],
        };

        $data['canViewEpps'] = $canViewEpps;
        $data['canViewHerramientas'] = $canViewHerramientas || $canViewEpps;

        if ($canViewEppWorkspace) {
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
            ], $eppActor);
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
        $transporte = RQMinaActividadTransporte::query()->with('grupo.rqMina:id,mina_id')->findOrFail($id);
        $this->assertTransportAccess($usuario, $transporte);

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
            'doc_vehiculo' => ['nullable', 'array'],
            'doc_vehiculo.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'doc_proserge' => ['nullable', 'array'],
            'doc_proserge.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'doc_mantenimiento' => ['nullable', 'array'],
            'doc_mantenimiento.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'doc_checklist' => ['nullable', 'array'],
            'doc_checklist.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'documentos' => ['nullable', 'array'],
            'documentos.*.nombre' => ['required_with:documentos.*.archivo', 'string', 'max:200'],
            'documentos.*.archivo' => ['required_with:documentos.*.nombre', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'documentos_eliminar' => ['nullable', 'array'],
            'documentos_eliminar.*' => ['integer', 'min:0'],
        ]);

        $data = $validated;
        unset($data['doc_vehiculo'], $data['doc_proserge'], $data['doc_mantenimiento'], $data['doc_checklist'], $data['documentos']);
        $directory = 'logistica/transportes/'.$id;
        $uploadedDocs = [];
        $documentTypes = [
            'doc_vehiculo' => 'Documentacion del vehiculo',
            'doc_proserge' => 'Documentacion interna Proserge',
            'doc_mantenimiento' => 'Plan de mantenimiento',
            'doc_checklist' => 'Checklist de enseres',
        ];

        foreach ($documentTypes as $field => $label) {
            $files = $request->file($field, []);
            if (! is_array($files)) {
                $files = [$files];
            }

            foreach ($files as $index => $file) {
                if (! $file || ! $file->isValid()) {
                    continue;
                }

                $filename = $field.'_'.Str::random(8).'.'.$file->getClientOriginalExtension();
                $path = $file->storeAs($directory, $filename, 'local');

                if ($index === 0) {
                    $data[$field.'_path'] = $path;
                }

                $uploadedDocs[] = [
                    'tipo' => $field,
                    'nombre' => $label,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'uploaded_at' => now()->toDateTimeString(),
                ];
            }
        }

        // Procesar documentos dinámicos (múltiples archivos con nombre)
        $additionalFiles = $request->file('documentos', []);
        if (is_array($additionalFiles)) {
            foreach ($additionalFiles as $index => $docFiles) {
                $file = is_array($docFiles) ? ($docFiles['archivo'] ?? null) : $docFiles;
                $nombre = trim((string) ($request->input('documentos.'.$index.'.nombre', '')));

                if (! $file || ! $file->isValid() || $nombre === '') {
                    continue;
                }

                $filename = 'doc_'.Str::slug($nombre).'_'.Str::random(6).'.'.$file->getClientOriginalExtension();
                $path = $file->storeAs($directory, $filename, 'local');
                $uploadedDocs[] = [
                    'tipo' => 'adicional',
                    'nombre' => $nombre,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'uploaded_at' => now()->toDateTimeString(),
                ];
            }
        }

        if ($uploadedDocs !== []) {
            $data['documentos_nuevos'] = $uploadedDocs;
        }

        try {
            $this->service->updateTransportRequirement($id, $data, $usuario);
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        $mensaje = (string) $request->input('panel', '') === 'recepcion'
            ? 'Recepcion del transporte registrada.'
            : 'Requerimiento de transporte actualizado.';

        return redirect()
            ->route('logistica.index', [
                'tab' => 'servicios',
                'servicio_parada_id' => (string) ($transporte->grupo?->rq_mina_id ?? ''),
            ])
            ->with('success', $mensaje);
    }

    /**
     * Descarga un documento del transporte atendido. Los slots legacy-* apuntan a
     * los campos historicos y adicional-N a los documentos cargados con nombre.
     */
    public function downloadTransportDocument(Request $request, string $id, string $slot): BinaryFileResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $transporte = RQMinaActividadTransporte::query()->with('grupo.rqMina:id,mina_id')->find($id);

        if (! $transporte) {
            abort(404, 'Requerimiento de transporte no encontrado.');
        }

        $this->assertTransportAccess($usuario, $transporte);
        $document = $this->service->transportDocumentSlot($transporte, $slot);

        if ($document === null || ! Storage::disk('local')->exists($document['path'])) {
            abort(404, 'El documento de transporte solicitado no esta disponible.');
        }

        return Storage::disk('local')->download($document['path'], $document['name']);
    }

    private function assertTransportAccess(Usuario $usuario, RQMinaActividadTransporte $transporte): void
    {
        $minaId = (string) ($transporte->grupo?->rqMina?->mina_id ?? '');

        if (MinaScopeAccess::allows($usuario, $minaId)) {
            return;
        }

        abort(403, 'No tienes acceso a la mina de este requerimiento de transporte.');
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
