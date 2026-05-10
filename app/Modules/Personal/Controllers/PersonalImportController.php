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
            $summaryLines = $this->buildSummaryLines($result);

            $message = sprintf(
                'Importacion completada: %d nuevos, %d actualizados, %d reactivados, %d inactivados y %d campos modificados.',
                $result['nuevos'] ?? 0,
                $result['actualizados'] ?? 0,
                $result['reactivados'] ?? 0,
                $result['inactivados'] ?? 0,
                $result['camposActualizados'] ?? 0,
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

            return back()
                ->with('success', $message)
                ->with('import_result', $result)
                ->with('import_summary_lines', $summaryLines);
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

    private function buildSummaryLines(array $result): array
    {
        $lines = [];

        $nuevos = (int) ($result['nuevos'] ?? 0);
        $actualizados = (int) ($result['actualizados'] ?? 0);
        $camposActualizados = (int) ($result['camposActualizados'] ?? 0);
        $reactivados = (int) ($result['reactivados'] ?? 0);
        $inactivados = (int) ($result['inactivados'] ?? 0);
        $duplicados = (int) ($result['duplicados'] ?? 0);
        $omitidos = (int) ($result['omitidos'] ?? 0);
        $relacionesCreadas = (int) ($result['relacionesMinaCreadas'] ?? 0);
        $relacionesActualizadas = (int) ($result['relacionesMinaActualizadas'] ?? 0);
        $relacionesEliminadas = (int) ($result['relacionesMinaEliminadas'] ?? 0);
        $telefonosDetectados = (int) ($result['telefonosDetectados'] ?? 0);
        $telefonosOmitidos = (int) ($result['telefonosCasosOmitidos'] ?? 0);

        if (($nuevos + $actualizados + $reactivados + $inactivados) === 0) {
            $lines[] = 'No se detectaron altas ni cambios sobre el personal con este archivo.';
        } else {
            $parts = [];
            if ($nuevos > 0) {
                $parts[] = $nuevos . ' nuevo(s)';
            }
            if ($actualizados > 0) {
                $parts[] = $actualizados . ' trabajador(es) actualizado(s)';
            }
            if ($reactivados > 0) {
                $parts[] = $reactivados . ' reactivado(s)';
            }
            if ($inactivados > 0) {
                $parts[] = $inactivados . ' inactivado(s)';
            }
            if ($camposActualizados > 0) {
                $parts[] = $camposActualizados . ' campo(s) modificado(s)';
            }

            if ($parts !== []) {
                $lines[] = 'Cambios aplicados: ' . implode(', ', $parts) . '.';
            }
        }

        if (($duplicados + $omitidos) > 0) {
            $parts = [];
            if ($duplicados > 0) {
                $parts[] = $duplicados . ' duplicado(s)';
            }
            if ($omitidos > 0) {
                $parts[] = $omitidos . ' omitido(s)';
            }
            $lines[] = 'Observaciones detectadas: ' . implode(', ', $parts) . '.';
        }

        if (($relacionesCreadas + $relacionesActualizadas + $relacionesEliminadas) > 0) {
            $parts = [];
            if ($relacionesCreadas > 0) {
                $parts[] = $relacionesCreadas . ' relación(es) de mina creada(s)';
            }
            if ($relacionesActualizadas > 0) {
                $parts[] = $relacionesActualizadas . ' relación(es) de mina actualizada(s)';
            }
            if ($relacionesEliminadas > 0) {
                $parts[] = $relacionesEliminadas . ' relación(es) de mina eliminada(s)';
            }
            $lines[] = 'Minas sincronizadas: ' . implode(', ', $parts) . '.';
        }

        if ($telefonosDetectados > 0 || $telefonosOmitidos > 0) {
            $parts = [];
            if ($telefonosDetectados > 0) {
                $parts[] = $telefonosDetectados . ' teléfono(s) detectado(s)';
            }
            if ($telefonosOmitidos > 0) {
                $parts[] = $telefonosOmitidos . ' caso(s) de teléfono omitido(s)';
            }
            $lines[] = 'Telefonía procesada: ' . implode(', ', $parts) . '.';
        }

        return $lines;
    }
}
