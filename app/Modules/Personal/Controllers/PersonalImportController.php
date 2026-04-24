<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Personal\Requests\ImportPersonalRequest;
use App\Modules\Personal\Services\ImportPersonalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PersonalImportController extends Controller
{
    public function __construct(private readonly ImportPersonalService $service)
    {
    }

    public function showImportForm(): View
    {
        return view('personal.import');
    }

    public function import(ImportPersonalRequest $request): RedirectResponse
    {
        try {
            $result = $this->service->import($request->file('file'));

            $message = sprintf(
                'Importacion completada: %d nuevos, %d actualizados, %d reactivados, %d inactivados, %d duplicados, %d omitidos. Minas: %d detectadas, %d creadas, %d reutilizadas, %d actualizadas. Relaciones: %d creadas, %d actualizadas, %d eliminadas.',
                $result['nuevos'] ?? 0,
                $result['actualizados'] ?? 0,
                $result['reactivados'] ?? 0,
                $result['inactivados'] ?? 0,
                $result['duplicados'] ?? 0,
                $result['omitidos'] ?? 0,
                $result['minasActivasDetectadas'] ?? 0,
                $result['minasCreadas'] ?? 0,
                $result['minasReutilizadas'] ?? 0,
                $result['minasActualizadas'] ?? 0,
                $result['relacionesMinaCreadas'] ?? 0,
                $result['relacionesMinaActualizadas'] ?? 0,
                $result['relacionesMinaEliminadas'] ?? 0,
            );

            \Illuminate\Support\Facades\Log::info('ImportPersonalSummary', $result);

            return back()->with('success', $message)->with('import_result', $result);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ImportPersonalError', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return back()->withErrors([
                'file' => 'Error al procesar importacion: ' . $e->getMessage(),
            ]);
        }
    }
}
