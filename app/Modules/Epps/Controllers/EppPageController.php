<?php

namespace App\Modules\Epps\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\ParadaHerramientaCatalogo;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Epps\Requests\SaveEppCatalogRequest;
use App\Modules\Epps\Services\EppKardexService;
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
        $actor = Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO')->findOrFail(session('user_id') ?: session('user.id'));
        $worker = Personal::query()->findOrFail((string) $request->query('personal_id', ''));
        app(EppScopeService::class)->assertPersonalAccess($actor, $worker);
        $data = $this->service->personalKardex($worker->id, $actor);
        if (PermissionMatrix::userCanDirect($actor, 'epps', 'ver_evidencia')) {
            $data['operations'] = app(EppKardexService::class)->forWorker($worker, $actor)['operations'];
        }

        return response()->json([
            'data' => $data,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function descargarKardex(Request $request): StreamedResponse
    {
        $this->requireAuthenticatedUser();

        $validated = $request->validate([
            'personal_id' => ['required', 'string', 'exists:personal,id'],
        ]);

        $actor = Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO')->findOrFail(session('user_id') ?: session('user.id'));
        app(EppScopeService::class)->assertPersonalAccess($actor, Personal::query()->findOrFail($validated['personal_id']));

        return $this->service->downloadPersonalKardex((string) $validated['personal_id'], $actor);
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
        abort(409, 'El registro individual fue retirado. Usa Nueva entrega multiple con firma.');
    }

    private function eppActor(): Usuario
    {
        return Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO')->findOrFail(session('user_id') ?: session('user.id'));
    }

    public function closeEntrega(Request $request, string $id): RedirectResponse
    {
        abort(409, 'Usa la devolucion multiple o una correccion compensatoria para conservar el historial.');
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
