<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Personal\Services\PersonalIngresoBatchReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PersonalIngresoBatchReviewController extends WebPageController
{
    private const PRIVATE_HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(private readonly PersonalIngresoBatchReviewService $reviews) {}

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filas' => ['required', 'array', 'min:1', 'max:50'],
            'filas.*.ingreso_id' => ['required', 'uuid', 'distinct'],
            'filas.*.fecha_inicio_contrato' => ['nullable', 'date_format:Y-m-d'],
            'filas.*.fecha_fin_contrato' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filas.*.fecha_inicio_contrato'],
        ], [
            'filas.*.fecha_fin_contrato.after_or_equal' => 'La fecha de fin debe ser igual o posterior al inicio.',
        ]);

        return response()->json($this->reviews->preview($this->requireAuthenticatedUser(), $data['filas']), 201, self::PRIVATE_HEADERS);
    }

    public function show(Request $request, string $lote): Response
    {
        $batch = $this->reviews->results($this->requireAuthenticatedUser(), $lote);

        return $request->expectsJson()
            ? response()->json($batch, 200, self::PRIVATE_HEADERS)
            : response()->view('personal.fichas.ingresos-lote', ['batch' => $batch], 200, self::PRIVATE_HEADERS);
    }

    public function confirm(Request $request, string $lote): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'string', 'size:64'],
            'filas' => ['required', 'array', 'min:1', 'max:10'],
            'filas.*' => ['required', 'uuid', 'distinct'],
            'revisado' => ['required', 'accepted'],
        ]);

        return response()->json([
            'resultados' => $this->reviews->confirm($this->requireAuthenticatedUser(), $lote, $data['revision'], $data['filas']),
        ], 200, self::PRIVATE_HEADERS);
    }

    public function details(string $lote, string $fila): JsonResponse
    {
        return response()->json($this->reviews->reviewDetails($this->requireAuthenticatedUser(), $lote, $fila), 200, self::PRIVATE_HEADERS);
    }
}
