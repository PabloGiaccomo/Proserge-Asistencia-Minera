<?php

namespace App\Modules\Epps\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppCatalogReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EppCatalogReturnController extends WebPageController
{
    public function create(Request $request, EppCatalogReturnService $service)
    {
        $input = $request->validate(['personal_id' => ['nullable', 'uuid', 'exists:personal,id']]);
        $worker = ! empty($input['personal_id']) ? Personal::query()->findOrFail($input['personal_id']) : null;

        return response()->view('epps.catalog-return', ['worker' => $worker, 'balances' => $worker ? $service->balances($worker, $this->actor()) : collect(), 'submissionUuid' => (string) Str::uuid()])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, EppCatalogReturnService $service)
    {
        $input = $request->validate(['personal_id' => ['required', 'uuid', 'exists:personal,id'], 'submission_uuid' => ['required', 'uuid'], 'devuelto_at' => ['required', 'date_format:Y-m-d'], 'observacion' => ['nullable', 'string', 'max:1000'], 'items' => ['required', 'array', 'min:1', 'max:50'], 'items.*' => ['array:entrega_id,cantidad'], 'items.*.entrega_id' => ['required', 'uuid', 'distinct'], 'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000000']]);
        try {
            $operation = $service->prepare($input, $this->actor());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['items' => $exception->getMessage()]);
        }

        return redirect()->route('epps.delivery-signature.review', $operation);
    }

    private function actor(): Usuario
    {
        return Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO')->findOrFail(session('user_id') ?: session('user.id'));
    }
}
