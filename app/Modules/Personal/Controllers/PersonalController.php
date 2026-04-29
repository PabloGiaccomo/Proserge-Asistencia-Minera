<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Personal;
use App\Modules\Personal\Requests\ImportPersonalRequest;
use App\Modules\Personal\Requests\StorePersonalRequest;
use App\Modules\Personal\Requests\UpdatePersonalRequest;
use App\Modules\Personal\Resources\PersonalResource;
use App\Modules\Personal\Services\ExportPersonalService;
use App\Modules\Personal\Services\ImportPersonalService;
use App\Modules\Personal\Services\PersonalService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class PersonalController extends Controller
{
    public function __construct(
        private readonly PersonalService $service,
        private readonly ImportPersonalService $importService,
        private readonly ExportPersonalService $exportService,
    ) {
    }

    public function index(Request $request)
    {
        $filters = $request->validate($this->filterRules());
        $items = $this->service->list($filters);

        return ApiResponse::success(
            data: PersonalResource::collection($items)->resolve(),
            message: 'Personal obtenido',
            code: 'PERSONAL_LIST_OK',
        );
    }

    public function store(StorePersonalRequest $request)
    {
        $created = $this->service->create($request->validated());

        return ApiResponse::success(
            data: PersonalResource::make($created)->resolve(),
            message: 'Trabajador registrado correctamente',
            code: 'PERSONAL_CREATE_OK',
            status: 201,
        );
    }

    public function update(UpdatePersonalRequest $request, string $id)
    {
        $personal = Personal::query()->find($id);
        if (!$personal) {
            return ApiResponse::error(
                message: 'Trabajador no encontrado',
                code: 'PERSONAL_NOT_FOUND',
                status: 404,
            );
        }

        $updated = $this->service->update($personal, $request->validated());

        return ApiResponse::success(
            data: PersonalResource::make($updated)->resolve(),
            message: 'Trabajador actualizado correctamente',
            code: 'PERSONAL_UPDATE_OK',
        );
    }

    public function importar(ImportPersonalRequest $request)
    {
        $result = $this->importService->import($request->file('file'));

        return ApiResponse::success(
            data: $result,
            message: 'Importacion de personal completada',
            code: 'PERSONAL_IMPORT_OK',
        );
    }

    public function exportar(Request $request)
    {
        $filters = $request->validate($this->filterRules());

        return $this->exportService->download($filters, 'personal_filtrado_' . now()->format('Ymd_His') . '.xlsx');
    }

    private function filterRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:191'],
            'q' => ['nullable', 'string', 'max:191'],
            'sort' => ['nullable', 'string', 'max:20'],
            'order' => ['nullable', 'string', 'max:10'],
            'estado' => ['nullable', 'string', 'max:40'],
            'tipo' => ['nullable', 'string', 'max:20'],
            'mina' => ['nullable', 'string', 'max:191'],
            'mina_estado' => ['nullable', 'string', 'max:30'],
            'contrato' => ['nullable', 'string', 'max:40'],
        ];
    }
}
