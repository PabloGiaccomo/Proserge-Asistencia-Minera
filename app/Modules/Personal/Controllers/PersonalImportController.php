<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
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
            $result = $this->service->import(
                $request->file('file'),
                Usuario::query()->find(session('user.id'))
            );
            $summaryLines = $this->buildSummaryLines($result);

            if (($result['tipoImportacion'] ?? null) === 'contactos') {
                $message = sprintf(
                    'Importacion de contactos completada: %d trabajador(es) actualizado(s) y %d campo(s) modificado(s).',
                    $result['actualizados'] ?? 0,
                    $result['camposActualizados'] ?? 0,
                );
            } elseif (($result['tipoImportacion'] ?? null) === 'datos_personal') {
                $nuevos = $result['nuevos'] ?? 0;
                $actualizados = $result['actualizados'] ?? 0;
                $fichas = $result['fichasActualizadas'] ?? 0;
                $contratoDatos = $result['contratoDatosActualizados'] ?? 0;
                $bloqueadas = $result['activacionesBloqueadas'] ?? 0;

                $message = 'Importacion de datos del personal completada.';

                $detalles = [];
                if ($nuevos > 0) $detalles[] = "{$nuevos} nuevo(s) creado(s)";
                if ($actualizados > 0) $detalles[] = "{$actualizados} actualizado(s)";
                if ($fichas > 0) $detalles[] = "{$fichas} ficha(s) actualizada(s)";
                if ($contratoDatos > 0) $detalles[] = "{$contratoDatos} dato(s) de contrato sincronizado(s)";
                if ($detalles !== []) {
                    $message .= ' ✅ ' . implode(', ', $detalles) . '.';
                }
                if ($bloqueadas > 0) {
                    $message .= ' ⚠️ ' . $bloqueadas . ' trabajador(es) pendiente(s) por contrato firmado.';
                }
            } else {
                $message = sprintf(
                    'Importacion completada: %d nuevos, %d actualizados, %d reactivados, %d inactivados y %d campos modificados.',
                    $result['nuevos'] ?? 0,
                    $result['actualizados'] ?? 0,
                    $result['reactivados'] ?? 0,
                    $result['inactivados'] ?? 0,
                    $result['camposActualizados'] ?? 0,
                );
                if (($result['activacionesBloqueadas'] ?? 0) > 0) {
                    $message .= ' ' . ($result['activacionesBloqueadas'] ?? 0) . ' activacion(es) quedaron pendientes por contrato firmado.';
                }
            }

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
        $noEncontrados = (int) ($result['noEncontrados'] ?? 0);
        $correosInvalidos = (int) ($result['correosInvalidos'] ?? 0);
        $sinCambios = (int) ($result['sinCambios'] ?? 0);
        $activacionesBloqueadas = (int) ($result['activacionesBloqueadas'] ?? 0);
        $fichasActualizadas = (int) ($result['fichasActualizadas'] ?? 0);
        $contratoDatosActualizados = (int) ($result['contratoDatosActualizados'] ?? 0);
        $contratosPreparados = (int) ($result['contratosPreparados'] ?? 0);

        if (!empty($result['formatoDetectado'])) {
            $lines[] = 'Formato detectado: ' . $result['formatoDetectado'] . '.';
        }

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
            if ($activacionesBloqueadas > 0) {
                $parts[] = $activacionesBloqueadas . ' activacion(es) bloqueada(s)';
            }
            if ($fichasActualizadas > 0) {
                $parts[] = $fichasActualizadas . ' ficha(s) actualizada(s)';
            }
            if ($contratoDatosActualizados > 0) {
                $parts[] = $contratoDatosActualizados . ' dato(s) de contrato sincronizado(s)';
            }
            if ($contratosPreparados > 0) {
                $parts[] = $contratosPreparados . ' contrato(s) en preparacion';
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

        if ($activacionesBloqueadas > 0) {
            $lines[] = 'Advertencia: ' . $activacionesBloqueadas . ' trabajador(es) no fueron activados porque no tienen contrato firmado vigente.';
        }

        if (($noEncontrados + $correosInvalidos + $sinCambios) > 0) {
            $parts = [];
            if ($noEncontrados > 0) {
                $parts[] = $noEncontrados . ' DNI no encontrado(s)';
            }
            if ($correosInvalidos > 0) {
                $parts[] = $correosInvalidos . ' correo(s) invalido(s)';
            }
            if ($sinCambios > 0) {
                $parts[] = $sinCambios . ' trabajador(es) sin cambios';
            }
            $lines[] = 'Detalle de contactos: ' . implode(', ', $parts) . '.';
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
