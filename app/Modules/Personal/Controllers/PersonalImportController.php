<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Modules\Personal\Requests\ImportPersonalRequest;
use App\Modules\Personal\Services\ImportPersonalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PersonalImportController extends Controller
{
    public function __construct(
        private readonly ImportPersonalService $service,
        private readonly NotificationService $notificationService,
    )
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

            if (($result['omitidos'] ?? 0) > 0 || ($result['duplicados'] ?? 0) > 0) {
                $this->notificationService->emit('personal_datos_incompletos', [
                    'actor_user_id' => session('user.id'),
                    'entity_type' => 'personal_import',
                    'entity_id' => (string) now()->timestamp,
                    'title' => 'Importacion con observaciones',
                    'message' => sprintf('Importacion de personal con %d omitidos y %d duplicados.', $result['omitidos'] ?? 0, $result['duplicados'] ?? 0),
                    'dedupe_key' => 'personal_datos_incompletos:' . now()->format('YmdHi'),
                ]);
            }

            return back()->with('success', $message)->with('import_result', $result);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ImportPersonalError', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->notificationService->emit('import_personal_error', [
                'actor_user_id' => session('user.id'),
                'entity_type' => 'personal_import',
                'entity_id' => (string) now()->timestamp,
                'title' => 'Error en importacion de personal',
                'message' => 'La importacion de personal fallo y requiere revision de RRHH.',
                'payload' => ['error' => $e->getMessage()],
                'dedupe_key' => 'import_personal_error:' . now()->format('YmdHi'),
                'priority' => 'critical',
                'category' => 'sistema',
            ]);
            
            return back()->withErrors([
                'file' => 'Error al procesar importacion: ' . $e->getMessage(),
            ]);
        }
    }
}
