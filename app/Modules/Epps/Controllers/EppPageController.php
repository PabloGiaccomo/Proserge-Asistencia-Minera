<?php

namespace App\Modules\Epps\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\EppEntrega;
use App\Models\ParadaHerramientaCatalogo;
use App\Modules\Epps\Services\EppService;
use App\Modules\ParadaHerramientas\Services\ParadaHerramientaService;
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
    )
    {
    }

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
            'items' => $this->service->searchPersonal((string) $request->query('q', ''), $limit),
        ]);
    }

    public function ultimaEntrega(Request $request): JsonResponse
    {
        $this->requireAuthenticatedUser();

        return response()->json([
            'data' => $this->service->lastDeliverySummary(
                (string) $request->query('personal_id', ''),
                (string) $request->query('epp_id', '')
            ),
        ]);
    }

    public function kardexPersonal(Request $request): JsonResponse
    {
        $this->requireAuthenticatedUser();

        return response()->json([
            'data' => $this->service->personalKardex((string) $request->query('personal_id', '')),
        ]);
    }

    public function descargarKardex(Request $request): StreamedResponse
    {
        $this->requireAuthenticatedUser();

        $validated = $request->validate([
            'personal_id' => ['required', 'string', 'exists:personal,id'],
        ]);

        return $this->service->downloadPersonalKardex((string) $validated['personal_id']);
    }

    public function storeCatalog(Request $request): RedirectResponse
    {
        $this->requireAuthenticatedUser();

        try {
            $this->service->storeCatalog($request->validate($this->catalogRules()));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->to($this->catalogRedirectUrl($request))
            ->with('success', 'Item guardado correctamente.');
    }

    public function updateCatalog(Request $request, string $id): RedirectResponse
    {
        $this->requireAuthenticatedUser();

        try {
            $this->service->updateCatalog($id, $request->validate($this->catalogRules()));
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
        $usuario = $this->requireAuthenticatedUser();

        $validated = $request->validate([
            'personal_id' => ['required', 'string', 'exists:personal,id'],
            'epp_id' => ['required', 'string', 'exists:epp_registro,id'],
            'cantidad' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'talla' => ['nullable', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:120'],
            'atributos' => ['nullable', 'array'],
            'atributos.*.nombre' => ['nullable', 'string', 'max:100'],
            'atributos.*.valor' => ['nullable', 'string', 'max:200'],
            'fecha_entrega' => ['required', 'date'],
            'observacion' => ['nullable', 'string', 'max:1000'],
            '_epp_replaces_entrega_id' => ['nullable', 'string', 'exists:epp_entregas,id'],
            '_epp_replacement_fecha' => ['nullable', 'date'],
            '_epp_replacement_motivo' => ['nullable', 'string', 'max:120'],
            '_epp_replacement_observacion' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            if (filled($validated['_epp_replaces_entrega_id'] ?? null)) {
                $this->service->replaceEntrega(
                    (string) $validated['_epp_replaces_entrega_id'],
                    $validated,
                    [
                        'devuelto_at' => $validated['_epp_replacement_fecha'] ?? $validated['fecha_entrega'],
                        'motivo_cambio' => $validated['_epp_replacement_motivo'] ?? null,
                        'observacion' => $validated['_epp_replacement_observacion'] ?? null,
                    ],
                    $usuario
                );

                return back()->with('success', 'Cambio de EPP registrado correctamente.');
            }

            $this->service->deliver($validated, $usuario);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput($request->input() + ['_epp_open_modal' => 'eppDeliveryModal'])
                ->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Entrega de EPP registrada correctamente.');
    }

    public function closeEntrega(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();

        $validated = $request->validate([
            'estado' => ['required', 'string', 'in:'.implode(',', [
                EppEntrega::ESTADO_CAMBIADO,
                EppEntrega::ESTADO_DEVUELTO,
                EppEntrega::ESTADO_USO_INCORRECTO,
                EppEntrega::ESTADO_PERDIDA_OLVIDO,
            ])],
            'devuelto_at' => ['required', 'date'],
            'motivo_cambio' => ['nullable', 'string', 'max:120'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['estado'] === EppEntrega::ESTADO_CAMBIADO) {
            $entrega = EppEntrega::query()->with(['personal', 'epp'])->findOrFail($id);

            if ($entrega->estado !== EppEntrega::ESTADO_ENTREGADO) {
                return back()->with('error', 'Esta entrega ya fue cerrada y no puede cambiarse otra vez.');
            }

            $personal = $entrega->personal;
            $label = collect([
                $personal?->nombre_completo,
                $personal?->dni ?: $personal?->numero_documento,
                $personal?->puesto,
            ])->filter()->implode(' - ');

            return back()
                ->withInput([
                    '_epp_open_modal' => 'eppDeliveryModal',
                    '_epp_replaces_entrega_id' => $entrega->id,
                    '_epp_replacement_fecha' => $validated['devuelto_at'],
                    '_epp_replacement_motivo' => $validated['motivo_cambio'] ?? '',
                    '_epp_replacement_observacion' => $validated['observacion'] ?? '',
                    'personal_id' => $entrega->personal_id,
                    'personal_label' => $label,
                    'epp_id' => $entrega->epp_id,
                    'cantidad' => $entrega->cantidad,
                    'fecha_entrega' => $validated['devuelto_at'],
                    'talla' => $entrega->talla,
                    'color' => $entrega->color,
                    'atributos' => $entrega->atributos_json ?: [],
                ]);
        }

        $this->service->closeEntrega($id, $validated, $usuario);

        return back()->with('success', 'Movimiento de EPP cerrado correctamente.');
    }

    public function updateEntrega(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();

        $validated = $request->validate([
            'epp_id' => ['nullable', 'string', 'exists:epp_registro,id'],
            'fecha_entrega' => ['nullable', 'date'],
            'fecha_vencimiento_calendario' => ['nullable', 'date'],
            'cantidad' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'talla' => ['nullable', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:120'],
            'atributos' => ['nullable', 'array'],
            'atributos.*.nombre' => ['nullable', 'string', 'max:100'],
            'atributos.*.valor' => ['nullable', 'string', 'max:200'],
            'motivo_cambio' => ['nullable', 'string', 'max:120'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->service->updateEntrega($id, $validated, $usuario);

        return back()->with('success', 'Entrega de EPP actualizada correctamente.');
    }

    public function destroyEntrega(string $id): RedirectResponse
    {
        $this->requireAuthenticatedUser();

        $this->service->destroyEntrega($id);

        return back()->with('success', 'Entrega de EPP eliminada correctamente.');
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
                ->withInput($request->input() + ['catalog_edit_id' => 'herramienta-catalogo-' . $id])
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

    private function catalogRules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:191'],
            'vida_util_dias' => ['required', 'integer', 'min:1', 'max:3650'],
            'requiere_talla' => ['nullable', 'boolean'],
            'tallas' => ['nullable', 'string', 'max:1000'],
            'requiere_color' => ['nullable', 'boolean'],
            'colores' => ['nullable', 'string', 'max:1000'],
            'otros_atributos' => ['nullable', 'array'],
            'otros_atributos.*.nombre' => ['nullable', 'string', 'max:100'],
            'otros_atributos.*.valores' => ['nullable', 'string', 'max:1000'],
            'categoria' => ['nullable', 'string', 'in:EPP,HERRAMIENTA,CONSUMIBLE'],
            'estado' => ['nullable', 'string', 'max:20'],
            'catalog_edit_id' => ['nullable', 'string'],
        ];
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
