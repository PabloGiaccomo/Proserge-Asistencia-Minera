<?php

namespace App\Modules\Epps\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\EppEntrega;
use App\Models\ParadaHerramientaCatalogo;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Epps\Requests\DownloadEppKardexRequest;
use App\Modules\Epps\Requests\SaveEppCatalogRequest;
use App\Modules\Epps\Services\EppDeliverySignatureService;
use App\Modules\Epps\Services\EppKardexExcelService;
use App\Modules\Epps\Services\EppScopeService;
use App\Modules\Epps\Services\EppService;
use App\Modules\ParadaHerramientas\Services\ParadaHerramientaService;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EppPageController extends WebPageController
{
    public function __construct(
        private readonly EppService $service,
        private readonly ParadaHerramientaService $toolCatalogService
    ) {}

    public function index(Request $request): RedirectResponse
    {
        $this->requireAuthenticatedUser();

        $query = array_filter([
            'tab' => 'entregas',
            'q' => $request->query('q'),
            'estado' => $request->query('estado'),
            'mina_id' => $request->query('mina_id'),
            'epp_id' => $request->query('epp_id'),
            'tipo_movimiento' => $request->query('tipo_movimiento'),
            'fecha_desde' => $request->query('fecha_desde'),
            'fecha_hasta' => $request->query('fecha_hasta'),
            'per_page' => $request->query('per_page'),
        ], static fn ($value): bool => $value !== null && $value !== '');

        return redirect()->to(url('/logistica').'?'.http_build_query($query));
    }

    public function buscarPersonal(Request $request): JsonResponse
    {
        $this->requireAuthenticatedUser();

        $limit = max(5, min(25, (int) $request->query('limit', 15)));

        return response()->json([
            'items' => $this->service->searchPersonal((string) $request->query('q', ''), $limit, $this->eppActor()),
        ]);
    }

    public function ultimaEntrega(Request $request): JsonResponse
    {
        $this->requireAuthenticatedUser();

        return response()->json([
            'data' => $this->service->lastDeliverySummary(
                (string) $request->query('personal_id', ''),
                (string) $request->query('epp_id', ''), $this->eppActor()
            ),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function kardexPersonal(Request $request): JsonResponse
    {
        $pagination = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'in:20,50,100'],
        ]);
        $actor = Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO')->findOrFail(session('user_id') ?: session('user.id'));
        $worker = Personal::query()->findOrFail((string) $request->query('personal_id', ''));
        app(EppScopeService::class)->assertPersonalAccess($actor, $worker);
        $data = $this->service->personalKardex($worker->id, $actor, [
            'page' => (int) ($pagination['page'] ?? 1), 'per_page' => (int) ($pagination['per_page'] ?? 20),
        ]);

        return response()->json([
            'data' => $data,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function descargarKardex(DownloadEppKardexRequest $request): StreamedResponse
    {
        $this->requireAuthenticatedUser();

        $validated = $request->validated();

        $actor = Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO')->findOrFail(session('user_id') ?: session('user.id'));
        app(EppScopeService::class)->assertPersonalAccess($actor, Personal::query()->findOrFail($validated['personal_id']));

        return app(EppKardexExcelService::class)->download(Personal::query()->findOrFail($validated['personal_id']), $actor, $validated['fecha_desde'], $validated['fecha_hasta']);
    }

    public function storeCatalog(SaveEppCatalogRequest $request): RedirectResponse
    {
        $this->authorizeCatalog();

        try {
            $this->service->storeCatalog($request->validated());
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->to($this->catalogRedirectUrl($request))
            ->with('success', 'Item guardado correctamente.');
    }

    public function updateCatalog(SaveEppCatalogRequest $request, string $id): RedirectResponse
    {
        $this->authorizeCatalog();

        try {
            $this->service->updateCatalog($id, $request->validated());
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput($request->input() + ['catalog_edit_id' => $id])
                ->with('error', $exception->getMessage());
        }

        return redirect()->to($this->catalogRedirectUrl($request))
            ->with('success', 'Item actualizado correctamente.');
    }

    public function storeEntrega(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'personal_id' => ['required', 'uuid', 'exists:personal,id'],
            'epp_id' => ['required_without:items', 'nullable', 'uuid', 'exists:epp_registro,id'],
            'cantidad' => ['required_without:items', 'nullable', 'integer', 'min:1', 'max:1000'],
            'talla' => ['nullable', 'string', 'max:80'], 'color' => ['nullable', 'string', 'max:120'],
            'atributos' => ['nullable', 'array'], 'atributos.*.nombre' => ['nullable', 'string', 'max:100'], 'atributos.*.valor' => ['nullable', 'string', 'max:200'],
            'fecha_entrega' => ['required', 'date'], 'observacion' => ['nullable', 'string', 'max:1000'],
            'submission_uuid' => ['nullable', 'uuid'],
            '_epp_add_another' => ['nullable', 'boolean'],
            '_epp_replaces_entrega_id' => ['nullable', 'uuid', 'exists:epp_entregas,id'],
            '_epp_replacement_fecha' => ['nullable', 'date'], '_epp_replacement_motivo' => ['nullable', 'string', 'max:120'], '_epp_replacement_observacion' => ['nullable', 'string', 'max:1000'],
            'items' => ['sometimes', 'array', 'min:1', 'max:50'],
            'items.*' => ['array:epp_id,cantidad,talla,color,atributos,observacion'],
            'items.*.epp_id' => ['required', 'uuid', 'exists:epp_registro,id'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.talla' => ['nullable', 'string', 'max:80'],
            'items.*.color' => ['nullable', 'string', 'max:120'],
            'items.*.observacion' => ['nullable', 'string', 'max:1000'],
            'items.*.atributos' => ['nullable', 'array'],
            'items.*.atributos.*.nombre' => ['nullable', 'string', 'max:100'],
            'items.*.atributos.*.valor' => ['nullable', 'string', 'max:200'],
        ]);
        try {
            $operation = app(EppDeliverySignatureService::class)->prepare($data, $this->eppActor());
        } catch (InvalidArgumentException $exception) {
            return back()->withInput($request->input() + ['_epp_open_modal' => 'eppDeliveryModal'])->with('error', $exception->getMessage());
        }

        return redirect()->route('epps.delivery-signature.review', $operation);
    }

    private function eppActor(): Usuario
    {
        return Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO')->findOrFail(session('user_id') ?: session('user.id'));
    }

    public function closeEntrega(Request $request, string $id): RedirectResponse
    {
        $actor = $this->eppActor();
        $delivery = EppEntrega::query()->with('personal', 'operacion')->findOrFail($id);
        app(EppScopeService::class)->assertPersonalAccess($actor, $delivery->personal);
        abort_if($delivery->operacion_id && ! $delivery->operacion?->isCatalogWorkflow(), 409, 'Esta entrega usa inventario por almacen. Conserva su flujo de devolucion original.');
        $data = $request->validate(['estado' => ['required', 'in:CAMBIADO,DEVUELTO,USO_INCORRECTO,PERDIDA_OLVIDO'], 'devuelto_at' => ['required', 'date'], 'motivo_cambio' => ['nullable', 'string', 'max:120'], 'observacion' => ['nullable', 'string', 'max:1000'], 'submission_uuid' => ['nullable', 'uuid']]);
        if ($delivery->estado !== 'ENTREGADO') {
            return back()->with('error', 'Esta entrega ya fue cerrada.');
        }
        if ($data['estado'] === 'CAMBIADO') {
            return back()->withInput(['_epp_open_modal' => 'eppDeliveryModal', '_epp_replaces_entrega_id' => $delivery->id, '_epp_replacement_fecha' => $data['devuelto_at'], '_epp_replacement_motivo' => $data['motivo_cambio'] ?? '', '_epp_replacement_observacion' => $data['observacion'] ?? '', 'personal_id' => $delivery->personal_id, 'personal_label' => $delivery->personal->nombre_completo.' - '.$delivery->personal->dni, 'epp_id' => $delivery->epp_id, 'cantidad' => $delivery->cantidad, 'fecha_entrega' => $data['devuelto_at'], 'talla' => $delivery->talla, 'color' => $delivery->color, 'atributos' => $delivery->atributos_json ?: []]);
        }
        try {
            $operation = app(EppDeliverySignatureService::class)->prepare($data, $actor, $delivery);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('epps.delivery-signature.review', $operation);
    }

    public function updateEntrega(Request $request, string $id): RedirectResponse
    {
        abort(409, 'El historial EPP no se edita directamente. Usa una correccion compensatoria.');
    }

    public function destroyEntrega(string $id): RedirectResponse
    {
        abort(409, 'El historial EPP no se elimina. Usa una anulacion compensatoria.');
    }

    public function destroyCatalog(Request $request, string $id): RedirectResponse
    {
        $this->requireAuthenticatedUser();

        $this->service->destroyCatalog($id);

        return redirect()->to($this->catalogRedirectUrl($request))
            ->with('success', 'Item enviado al basurero del catalogo correctamente.');
    }

    public function storeToolCatalog(Request $request): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $validated = $request->validate($this->toolCatalogRules());
        $data = $this->normalizeToolCatalogPayload($validated);

        $catalog = ParadaHerramientaCatalogo::query()->firstOrNew([
            'categoria' => $data['categoria'],
            'descripcion_normalizada' => $data['descripcion_normalizada'],
            'unidad_normalizada' => $data['unidad_normalizada'],
        ]);

        if (! $catalog->exists) {
            $catalog->id = (string) Str::uuid();
            $catalog->created_by_usuario_id = $usuario?->id;
        }

        $catalog->forceFill([
            'descripcion' => $data['descripcion'],
            'unidad' => $data['unidad'],
            'activo' => $data['activo'],
            'updated_by_usuario_id' => $usuario?->id,
        ])->save();

        return redirect()->to($this->catalogRedirectUrl($request))
            ->with('success', 'Item guardado correctamente.');
    }

    public function updateToolCatalog(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $validated = $request->validate($this->toolCatalogRules());
        $data = $this->normalizeToolCatalogPayload($validated);

        $duplicado = ParadaHerramientaCatalogo::query()
            ->where('categoria', $data['categoria'])
            ->where('descripcion_normalizada', $data['descripcion_normalizada'])
            ->where('unidad_normalizada', $data['unidad_normalizada'])
            ->where('id', '!=', $id)
            ->exists();

        if ($duplicado) {
            return back()
                ->withInput($request->input() + ['catalog_edit_id' => 'herramienta-catalogo-'.$id])
                ->with('error', 'Ya existe otro item con ese nombre.');
        }

        ParadaHerramientaCatalogo::query()
            ->findOrFail($id)
            ->forceFill([
                'categoria' => $data['categoria'],
                'descripcion' => $data['descripcion'],
                'descripcion_normalizada' => $data['descripcion_normalizada'],
                'unidad' => $data['unidad'],
                'unidad_normalizada' => $data['unidad_normalizada'],
                'activo' => $data['activo'],
                'updated_by_usuario_id' => $usuario?->id,
            ])
            ->save();

        return redirect()->to($this->catalogRedirectUrl($request))
            ->with('success', 'Item actualizado correctamente.');
    }

    public function destroyToolCatalog(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();

        ParadaHerramientaCatalogo::query()
            ->findOrFail($id)
            ->forceFill([
                'activo' => false,
                'updated_by_usuario_id' => $usuario?->id,
            ])
            ->save();

        return redirect()->to($this->catalogRedirectUrl($request))
            ->with('success', 'Item enviado al basurero del catalogo correctamente.');
    }

    public function importToolCatalog(Request $request): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();

        $validated = $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,xlsm', 'max:20480'],
            'ident_categoria' => ['nullable', 'string', 'in:EPP,HERRAMIENTA,CONSUMIBLE'],
        ]);

        $result = $this->toolCatalogService->importarCatalogo($usuario, $request->file('archivo'));
        $categoria = strtoupper((string) ($validated['ident_categoria'] ?? 'HERRAMIENTA'));

        if (! in_array($categoria, ['HERRAMIENTA', 'CONSUMIBLE'], true)) {
            $categoria = 'HERRAMIENTA';
        }

        return redirect()
            ->to(url('/logistica').'?'.http_build_query([
                'tab' => 'identificacion',
                'ident_categoria' => $categoria,
            ]))
            ->with(($result['ok'] ?? false) ? 'success' : 'error', $result['message'] ?? 'No se pudo actualizar el catalogo.');
    }

    private function catalogRedirectUrl(Request $request): string
    {
        $categoria = strtoupper(trim((string) ($request->input('categoria') ?: $request->input('ident_categoria') ?: 'EPP')));

        if (! in_array($categoria, ['EPP', 'HERRAMIENTA', 'CONSUMIBLE'], true)) {
            $categoria = 'EPP';
        }

        return url('/logistica').'?'.http_build_query([
            'tab' => 'identificacion',
            'ident_categoria' => $categoria,
        ]);
    }

    private function authorizeCatalog(): void
    {
        $actor = Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO')->findOrFail(session('user_id') ?: session('user.id'));
        abort_unless(PermissionMatrix::userCanDirect($actor, 'epps', 'gestionar_inventario'), 403);
    }

    private function toolCatalogRules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:300'],
            'unidad' => ['nullable', 'string', 'max:40'],
            'categoria' => ['required', 'string', 'in:HERRAMIENTA,CONSUMIBLE'],
            'ident_categoria' => ['nullable', 'string', 'in:HERRAMIENTA,CONSUMIBLE'],
            'estado' => ['nullable', 'string', 'in:ACTIVO,INACTIVO'],
            'catalog_edit_id' => ['nullable', 'string'],
        ];
    }

    private function normalizeToolCatalogPayload(array $payload): array
    {
        $description = mb_strtoupper(trim((string) ($payload['nombre'] ?? '')), 'UTF-8');
        $unit = mb_strtoupper(trim((string) ($payload['unidad'] ?? '')), 'UTF-8');
        $category = strtoupper(trim((string) ($payload['categoria'] ?? $payload['ident_categoria'] ?? 'HERRAMIENTA')));

        if (! in_array($category, ['HERRAMIENTA', 'CONSUMIBLE'], true)) {
            $category = 'HERRAMIENTA';
        }

        return [
            'categoria' => $category,
            'descripcion' => mb_substr($description, 0, 300),
            'descripcion_normalizada' => mb_substr($this->normalizeToolCatalogText($description), 0, 320),
            'unidad' => $unit !== '' ? mb_substr($unit, 0, 40) : null,
            'unidad_normalizada' => mb_substr($this->normalizeToolCatalogText($unit), 0, 40),
            'activo' => strtoupper((string) ($payload['estado'] ?? 'ACTIVO')) !== 'INACTIVO',
        ];
    }

    private function normalizeToolCatalogText(string $value): string
    {
        return preg_replace('/\s+/', ' ', Str::of($value)->ascii()->upper()->toString()) ?: '';
    }
}
