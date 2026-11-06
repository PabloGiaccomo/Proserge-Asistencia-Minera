<?php

namespace App\Modules\Logistica\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Personal;
use App\Models\RQMinaActividadTransporte;
use App\Models\RQMinaActividadTransporteEvento;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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
            $canViewServicios || $canViewHerramientas => $this->service->serviciosPageData($query, $usuario),
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

        if (!$request->filled('panel')) {
            $request->merge(['panel' => $request->has('capacidad_camion') ? 'entrega' : 'atencion']);
        }
        $validated = $request->validate([
            'panel' => ['required', 'in:atencion,cambio,entrega,recepcion'],
            'origen' => ['nullable', 'string', 'in:EMPRESA,ALQUILADO,OTRO'],
            'placas_asignadas' => ['nullable', 'string', 'max:2000'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date'],
            'estado_logistico' => ['required_if:panel,atencion,cambio', 'nullable', 'string', 'in:REQUERIDO,ASIGNADO,EN_USO,RETIRADO,REEMPLAZADO,DEVUELTO,INCIDENCIA'],
            'comentario_cambio' => ['nullable', 'string', 'max:2000'],
            'incidencia_operativa' => ['nullable', 'string', 'max:2000'],
            'recepcion_fecha' => ['nullable', 'date'],
            'recepcion_estado' => ['required_if:panel,recepcion', 'nullable', 'string', 'in:PENDIENTE,RECIBIDO,INCOMPLETO,NO_LLEGO,CON_OBSERVACION'],
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
            'documentos_retirar' => ['nullable', 'array'],
            'documentos_retirar.*' => ['string', 'regex:/^doc-[a-f0-9]{64}$/D', 'distinct'],
        ]);

        $data = $validated;
        unset($data['doc_vehiculo'], $data['doc_proserge'], $data['doc_mantenimiento'], $data['doc_checklist'], $data['documentos']);
        $uploadedDocs = [];

        try {
            if ($data['panel'] === 'entrega') {
                $types = [
                    'doc_vehiculo' => 'Documentación del vehículo',
                    'doc_proserge' => 'Documentación interna Proserge',
                    'doc_mantenimiento' => 'Plan de mantenimiento',
                    'doc_checklist' => 'Checklist de enseres',
                ];
                foreach ($types as $field => $label) {
                    foreach (array_values($validated[$field] ?? []) as $index => $file) {
                        $path = $this->storeTransportAttachment($id, $field, $label, $file, $uploadedDocs);
                        if ($index === 0) {
                            $data[$field.'_path'] = $path;
                        }
                    }
                }
                foreach ($validated['documentos'] ?? [] as $document) {
                    if (($document['archivo'] ?? null) instanceof UploadedFile) {
                        $this->storeTransportAttachment($id, 'adicional', trim($document['nombre']), $document['archivo'], $uploadedDocs);
                    }
                }
                $data['documentos_nuevos'] = $uploadedDocs;
            }
            $this->service->updateTransportRequirement($id, $data, $usuario);
        } catch (InvalidArgumentException $exception) {
            Storage::disk('local')->delete(array_column($uploadedDocs, 'path'));

            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->discardUnreferencedTransportAttachments($id, $uploadedDocs);
            throw $exception;
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

    private function storeTransportAttachment(string $id, string $type, string $label, UploadedFile $file, array &$uploaded): string
    {
        $name = $type.'_'.Str::uuid().'.'.($file->extension() ?: 'bin');
        $path = $file->storeAs('logistica/transportes/'.$id, $name, 'local');
        if (!$path) {
            throw new InvalidArgumentException('No se pudo guardar un documento. Selecciona los archivos e inténtalo de nuevo.');
        }
        $uploaded[] = [
            'tipo' => $type, 'nombre' => $label, 'path' => $path,
            'original_name' => $file->getClientOriginalName(), 'uploaded_at' => now()->toDateTimeString(),
        ];

        return $path;
    }

    private function discardUnreferencedTransportAttachments(string $id, array $uploaded): void
    {
        try {
            foreach (array_column($uploaded, 'path') as $path) {
                $recorded = RQMinaActividadTransporteEvento::query()->where('transporte_id', $id)
                    ->whereJsonContains('transporte_snapshot->documentos', ['path' => $path])->exists();
                $current = RQMinaActividadTransporte::query()->whereKey($id)->first();
                $currentPaths = array_merge(array_column($current?->documentos ?? [], 'path'), array_values($current?->only([
                    'doc_vehiculo_path', 'doc_proserge_path', 'doc_mantenimiento_path', 'doc_checklist_path',
                ]) ?? []));
                if (!$recorded && !in_array($path, $currentPaths, true)) {
                    Storage::disk('local')->delete($path);
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('logistica.documentos_temporales_pendientes', ['transporte_id' => $id, 'exception' => $exception::class]);
        }
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
