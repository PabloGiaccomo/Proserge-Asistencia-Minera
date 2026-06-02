<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Personal\Services\PersonalContratoFormatoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonalContratoFormatoController extends Controller
{
    public function __construct(private readonly PersonalContratoFormatoService $service)
    {
    }

    public function templates(): JsonResponse
    {
        return response()->json([
            'templates' => $this->service->templates(),
        ]);
    }

    public function searchWorkers(Request $request): JsonResponse
    {
        return response()->json([
            'workers' => $this->service->searchWorkers((string) $request->query('q', '')),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'string'],
            'personal_ids' => ['array'],
            'personal_ids.*' => ['string'],
        ]);

        return response()->json(
            $this->service->preview(
                (string) $validated['template_id'],
                $validated['personal_ids'] ?? [],
            )
        );
    }

    public function download(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'string'],
            'personal_ids' => ['required', 'array', 'min:1'],
            'personal_ids.*' => ['string'],
        ]);

        return $this->service->download(
            (string) $validated['template_id'],
            $validated['personal_ids'],
        );
    }
}
