<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Personal\Services\MiningExamCatalogOrganizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MiningExamCatalogPageController extends WebPageController
{
    public function __construct(private MiningExamCatalogOrganizationService $service) {}

    public function index(Request $request): View
    {
        $preview = $request->session()->get('mining_catalog_preview');
        if ($preview && ($preview['expires_at'] < now()->timestamp || $preview['actor_id'] !== $this->requireAuthenticatedUser()->id)) {
            $request->session()->forget('mining_catalog_preview');
            $preview = null;
        }

        return view('personal.habilitacion-minera.catalog-organization', ['report' => $this->service->report(), 'preview' => $preview]);
    }

    public function preview(Request $request): RedirectResponse
    {
        $input = $request->all();
        if ($request->has('aliases_text')) {
            $request->validate(['aliases_text' => ['nullable', 'string', 'max:20000'], 'alias_mina_id' => ['nullable', 'uuid']]);
            $input['aliases'] = collect(preg_split('/\R/u', $request->input('aliases_text', '')) ?: [])
                ->map(fn ($name) => trim($name))->filter()->unique()->map(fn ($name) => [
                    'nombre' => $name, 'mina_id' => $request->input('alias_mina_id') ?: null,
                ])->values()->all();
        }
        $preview = $this->service->preview($input, $this->requireAuthenticatedUser());
        $preview['token'] = (string) Str::uuid();
        $preview['expires_at'] = now()->addMinutes(15)->timestamp;
        $request->session()->put('mining_catalog_preview', $preview);

        return redirect()->route('personal.habilitacion-minera.catalogo.index', ['revision' => 1]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string'], 'confirmar' => ['required', 'accepted']]);
        $preview = $request->session()->get('mining_catalog_preview');
        if (! $preview || $preview['expires_at'] < now()->timestamp || ! hash_equals($preview['token'], $request->input('token'))) {
            throw ValidationException::withMessages(['catalogo' => 'La revision vencio o no corresponde a esta sesion. Revisa nuevamente.']);
        }
        $this->service->confirm($preview, $this->requireAuthenticatedUser());
        $request->session()->forget('mining_catalog_preview');

        return redirect()->route('personal.habilitacion-minera.catalogo.index')
            ->with('success', 'Organizacion guardada con historial. No se modificaron resultados ni habilitaciones.');
    }
}
