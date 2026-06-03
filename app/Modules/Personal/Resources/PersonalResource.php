<?php

namespace App\Modules\Personal\Resources;

use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Modules\Personal\Support\PersonalNormalizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mineStates = [];
        $mineNames = [];
        $telefono1 = $this->telefono_1 ?? null;
        $telefono2 = $this->telefono_2 ?? null;
        $today = Carbon::today();
        $todayString = $today->toDateString();
        $futureWindowEnd = $today->copy()->addMonths(2)->endOfDay();

        $activeBloqueos = collect($this->whenLoaded('bloqueos', fn () => $this->bloqueos, collect()))
            ->filter(function ($bloqueo) use ($todayString): bool {
                $inicio = optional($bloqueo->fecha_inicio)->toDateString();
                $fin = optional($bloqueo->fecha_fin)->toDateString();

                return $inicio !== null && $fin !== null && $inicio <= $todayString && $fin >= $todayString;
            })
            ->values();

        $allBloqueos = collect($this->whenLoaded('bloqueos', fn () => $this->bloqueos, collect()))->values();

        $primaryBloqueo = $activeBloqueos
            ->sortBy(function ($bloqueo): int {
                return match ((string) $bloqueo->tipo) {
                    'gestacion' => 1,
                    'descanso_medico' => 1,
                    'inhabilitado' => 2,
                    'restriccion_temporal' => 3,
                    'vacaciones' => 4,
                    default => 5,
                };
            })
            ->first();

        $estadoPersonal = strtoupper((string) $this->estado);
        $ficha = $this->whenLoaded('fichaColaborador', fn () => $this->fichaColaborador, null);
        $contratoDatos = $this->whenLoaded('contratoDatos', fn () => $this->contratoDatos, null);
        $cesadoPor = $this->whenLoaded('cesadoPor', fn () => $this->cesadoPor, null);
        $contratosLaborales = collect($this->whenLoaded('contratosLaborales', fn () => $this->contratosLaborales, collect()))->values();
        $contratosCerrados = $contratosLaborales
            ->filter(fn ($contratoLaboral): bool => strtoupper((string) ($contratoLaboral->estado ?? '')) === 'CERRADO')
            ->values();
        $contratoActual = $contratosLaborales
            ->filter(fn ($contratoLaboral): bool => strtoupper((string) ($contratoLaboral->estado ?? '')) === 'ACTIVO')
            ->sortByDesc(fn ($contratoLaboral) => (int) ($contratoLaboral->contrato_numero ?? 0))
            ->first();
        $ultimoContratoCerrado = $contratosCerrados
            ->sortByDesc(fn ($contratoLaboral) => (int) ($contratoLaboral->contrato_numero ?? 0))
            ->first();
        $estadoFicha = $ficha?->estado;
        $contrato = PersonalNormalizer::contract($this->contrato);
        $fichaData = is_array($ficha?->datos_json ?? null) ? $ficha->datos_json : [];
        $fichaData['tipo_documento'] = $fichaData['tipo_documento'] ?? $ficha?->tipo_documento ?? $this->tipo_documento ?? 'DNI';
        $fichaData['numero_documento'] = $fichaData['numero_documento'] ?? $ficha?->numero_documento ?? $this->numero_documento ?? $this->dni;
        $fechaFinContrato = PersonalNormalizer::isoDate($fichaData['fecha_fin_contrato'] ?? null);
        $fechaCese = PersonalNormalizer::isoDate($fichaData['fecha_cese'] ?? $this->fecha_cese ?? null);
        $missingRequiredFichaFields = collect(PersonalFichaCatalog::requiredKeys())
            ->filter(function (string $key) use ($fichaData): bool {
                $value = $fichaData[$key] ?? null;

                if (is_array($value)) {
                    return count(array_filter($value, static fn ($item) => filled($item))) === 0;
                }

                return !filled($value);
            })
            ->values()
            ->all();

        $fechas = [
            'ingreso' => optional($this->fecha_ingreso)->toDateString(),
            'vacaciones' => null,
            'enfermo' => null,
            'parada' => null,
        ];

        $formatDate = function ($date): string {
            if (!$date) {
                return '-';
            }

            try {
                return Carbon::parse($date)->format('d/m/Y');
            } catch (\Throwable) {
                return '-';
            }
        };

        $rangeText = function ($bloqueo) use ($formatDate): string {
            if (!$bloqueo) {
                return '-';
            }

            return $formatDate(optional($bloqueo->fecha_inicio)->toDateString())
                . ' al '
                . $formatDate(optional($bloqueo->fecha_fin)->toDateString());
        };

        $buildStatusText = function (string $tipo, string $categoriaLabel) use ($allBloqueos, $today, $futureWindowEnd, $rangeText): string {
            $byType = $allBloqueos
                ->filter(fn ($b) => (string) ($b->tipo ?? '') === $tipo)
                ->values();

            $active = $byType->first(function ($b) use ($today): bool {
                $inicio = optional($b->fecha_inicio);
                $fin = optional($b->fecha_fin);
                if (!$inicio || !$fin) {
                    return false;
                }

                return $inicio->toDateString() <= $today->toDateString()
                    && $fin->toDateString() >= $today->toDateString();
            });

            if ($active) {
                return $categoriaLabel . ' vigente del ' . $rangeText($active) . '.';
            }

            $next = $byType
                ->filter(function ($b) use ($today, $futureWindowEnd): bool {
                    $inicio = optional($b->fecha_inicio);
                    if (!$inicio) {
                        return false;
                    }

                    return $inicio->greaterThan($today) && $inicio->lessThanOrEqualTo($futureWindowEnd);
                })
                ->sortBy(fn ($b) => optional($b->fecha_inicio)?->toDateString())
                ->first();

            if ($next) {
                return 'Próximo ' . mb_strtolower($categoriaLabel) . ' del ' . $rangeText($next) . '.';
            }

            $last = $byType
                ->filter(function ($b) use ($today): bool {
                    $fin = optional($b->fecha_fin);

                    return $fin && $fin->lessThan($today);
                })
                ->sortByDesc(fn ($b) => optional($b->fecha_fin)?->toDateString())
                ->first();

            if ($last) {
                return 'Último ' . mb_strtolower($categoriaLabel) . ' del ' . $rangeText($last) . '.';
            }

            return '';
        };

        $vacacionesMsg = $buildStatusText('vacaciones', 'Vacaciones');
        if ($vacacionesMsg === '') {
            $vacacionesMsg = 'Sin vacaciones próximas en los siguientes 2 meses. Disponible por ahora.';
        }

        $descansoMsg = $buildStatusText('descanso_medico', 'Descanso médico');
        if ($descansoMsg === '') {
            $descansoMsg = 'Sin descanso médico vigente. Estado de salud operativo.';
        }

        $gestacionMsg = $buildStatusText('gestacion', 'Gestacion');

        $paradaTypes = $allBloqueos
            ->filter(function ($b): bool {
                return !in_array((string) ($b->tipo ?? ''), ['vacaciones', 'descanso_medico', 'gestacion'], true);
            })
            ->values();

        $paradaVigente = $paradaTypes->first(function ($b) use ($today): bool {
            $inicio = optional($b->fecha_inicio);
            $fin = optional($b->fecha_fin);
            if (!$inicio || !$fin) {
                return false;
            }

            return $inicio->toDateString() <= $today->toDateString()
                && $fin->toDateString() >= $today->toDateString();
        });

        if ($paradaVigente) {
            $paradaMsg = 'Parada vigente del ' . $rangeText($paradaVigente) . '.';
        } else {
            $paradaMsg = 'Sin parada vigente en este momento.';
        }

        if ($primaryBloqueo) {
            $rango = [
                'inicio' => optional($primaryBloqueo->fecha_inicio)->toDateString(),
                'fin' => optional($primaryBloqueo->fecha_fin)->toDateString(),
            ];

            if ((string) $primaryBloqueo->tipo === 'vacaciones') {
                $fechas['vacaciones'] = $rango;
            } elseif ((string) $primaryBloqueo->tipo === 'descanso_medico') {
                $fechas['enfermo'] = $rango;
            } else {
                $fechas['parada'] = $rango;
            }
        }

        if (!$telefono1 && !empty($this->telefono)) {
            $phoneData = PersonalNormalizer::normalizePhonePayload($this->telefono);
            $telefono1 = $phoneData['telefono_1'];
            $telefono2 = $phoneData['telefono_2'];
        }

        foreach ($this->whenLoaded('minas', fn () => $this->minas, collect()) as $mina) {
            $mineNames[] = $mina->nombre;
            $mineStates[$mina->nombre] = PersonalNormalizer::mineStatusLabel($mina->pivot?->estado);
        }

        $ubicacionSituacion = collect($mineNames)
            ->map(function ($name): ?string {
                $lower = mb_strtolower((string) $name);

                if (str_contains($lower, 'oficina')) {
                    return 'oficina';
                }

                if (str_contains($lower, 'taller')) {
                    return 'taller';
                }

                return null;
            })
            ->filter()
            ->first();

        $hasCentroTrabajoActivo = $ubicacionSituacion !== null;

        $hasParadaActiva = $activeBloqueos->contains(function ($bloqueo): bool {
            return !in_array((string) ($bloqueo->tipo ?? ''), ['vacaciones', 'descanso_medico', 'gestacion'], true);
        });
        $hasRqProsergeParadaActiva = collect($this->whenLoaded('rqProsergeDetalles', fn () => $this->rqProsergeDetalles, collect()))
            ->isNotEmpty();

        $contratoVencido = $fechaFinContrato !== null && $fechaFinContrato !== '' && $fechaFinContrato < $todayString;
        $ceseVigente = $fechaCese !== null && $fechaCese !== '' && $fechaCese <= $todayString;
        $motivoCese = trim((string) ($this->motivo_cese ?? ''));
        $revisarFicha = in_array($estadoPersonal, ['FICHA_ENVIADA', 'OBSERVADO'], true);
        $terminarFicha = in_array($estadoPersonal, ['PENDIENTE_COMPLETAR_FICHA', 'LINK_VENCIDO'], true)
            || $ficha === null
            || count($missingRequiredFichaFields) > 0;
        $bienestarInactivo = $primaryBloqueo && in_array((string) $primaryBloqueo->tipo, ['vacaciones', 'descanso_medico'], true);
        $intermitenteActivo = $contrato === 'INTER' && ($hasParadaActiva || $hasRqProsergeParadaActiva);
        $trabajadorNoIntermitenteActivo = $contrato !== 'INTER';

        $estadoVisible = match (true) {
            $estadoPersonal === 'CESADO' || $contratoVencido || $ceseVigente => 'CESADO',
            $estadoPersonal === 'FALTA_CONTRATO' => 'ACTIVO',
            $terminarFicha => 'INACTIVO',
            $bienestarInactivo || ($primaryBloqueo && (string) $primaryBloqueo->tipo === 'gestacion') => 'INACTIVO',
            $contrato === 'INTER' && !$intermitenteActivo => 'INACTIVO',
            $trabajadorNoIntermitenteActivo || $intermitenteActivo => 'ACTIVO',
            default => 'INACTIVO',
        };
        $estadoDisplay = $estadoPersonal === 'FALTA_CONTRATO' ? 'FALTA_CONTRATO' : $estadoVisible;

        $situacionKey = match (true) {
            $estadoVisible === 'CESADO' => 'no_habilitado',
            $revisarFicha => 'revisar_ficha',
            $terminarFicha => 'terminar_ficha',
            $primaryBloqueo && (string) $primaryBloqueo->tipo === 'vacaciones' => 'vacaciones',
            $primaryBloqueo && (string) $primaryBloqueo->tipo === 'descanso_medico' => 'descanso_medico',
            $primaryBloqueo && (string) $primaryBloqueo->tipo === 'gestacion' => 'gestacion',
            $hasParadaActiva || $hasRqProsergeParadaActiva => 'parada',
            $ubicacionSituacion === 'oficina' => 'oficina',
            $ubicacionSituacion === 'taller' => 'taller',
            $contrato === 'INTER' => 'habilitado',
            collect($mineStates)->contains(fn ($state) => in_array($state, ['habilitado', 'proceso'], true)) => 'habilitado',
            collect($mineStates)->contains('no_habilitado') => 'no_habilitado',
            default => 'habilitado',
        };

        $situacionLabel = match ($situacionKey) {
            'revisar_ficha' => 'Revisar ficha',
            'ficha_observada' => 'Ficha observada',
            'terminar_ficha' => 'Terminar ficha',
            'vacaciones' => 'Vacaciones',
            'descanso_medico' => 'Descanso medico',
            'gestacion' => 'Gestacion',
            'parada' => 'En parada',
            'oficina' => 'En oficina',
            'taller' => 'En taller',
            'habilitado' => 'Habilitado',
            'no_habilitado' => 'No habilitado',
            default => 'Habilitado',
        };
        $motivoCeseVisible = match (true) {
            $estadoVisible !== 'CESADO' => '',
            $motivoCese !== '' => $motivoCese,
            $ultimoContratoCerrado && trim((string) ($ultimoContratoCerrado->motivo_cese ?? '')) !== '' => trim((string) $ultimoContratoCerrado->motivo_cese),
            $contratoVencido => 'Termino de contrato',
            $ceseVigente => 'Cese programado',
            default => 'Motivo no registrado',
        };
        $cesadoPorNombre = trim((string) ($cesadoPor?->personal?->nombre_completo ?: $cesadoPor?->email ?: ''));
        $ultimoCerradoPor = $ultimoContratoCerrado?->cerradoPor;
        $ultimoCerradoPorNombre = trim((string) ($ultimoCerradoPor?->personal?->nombre_completo ?: $ultimoCerradoPor?->email ?: ''));
        $formatContract = function ($contract) use ($formatDate): ?array {
            if (!$contract) {
                return null;
            }

            return [
                'id' => (string) $contract->id,
                'numero' => (int) $contract->contrato_numero,
                'estado' => (string) $contract->estado,
                'fecha_inicio' => optional($contract->fecha_inicio)->toDateString(),
                'fecha_fin' => optional($contract->fecha_fin)->toDateString(),
                'fecha_inicio_label' => $formatDate(optional($contract->fecha_inicio)->toDateString()),
                'fecha_fin_label' => optional($contract->fecha_fin)->toDateString()
                    ? $formatDate(optional($contract->fecha_fin)->toDateString())
                    : 'Vigente',
                'motivo_cese' => (string) ($contract->motivo_cese ?? ''),
                'activado_at' => optional($contract->activado_at)->toIso8601String(),
                'cerrado_at' => optional($contract->cerrado_at)->toIso8601String(),
                'cerrado_por_nombre' => trim((string) ($contract->cerradoPor?->personal?->nombre_completo ?: $contract->cerradoPor?->email ?: '')),
            ];
        };

        return [
            'id' => $this->id,
            'dni' => $this->dni,
            'tipo_documento' => $this->tipo_documento ?? 'DNI',
            'numero_documento' => $this->numero_documento ?? $this->dni,
            'nombre' => $this->nombre_completo,
            'nombre_completo' => $this->nombre_completo,
            'puesto' => $this->puesto,
            'ocupacion' => $this->ocupacion,
            'contrato' => $contrato,
            'tipo_contrato' => PersonalNormalizer::contractLabel($contrato),
            'supervisor' => (bool) $this->es_supervisor,
            'es_supervisor' => (bool) $this->es_supervisor,
            'fecha_ingreso' => optional($this->fecha_ingreso)->toDateString(),
            'fecha_fin_contrato' => $fechaFinContrato,
            'fecha_cese' => $fechaCese,
            'motivo_cese' => $motivoCeseVisible,
            'cese_automatico' => $estadoVisible === 'CESADO' && $motivoCese === '' && $contratoVencido,
            'cesado_por' => $cesadoPor ? [
                'id' => (string) $cesadoPor->id,
                'nombre' => $cesadoPorNombre,
                'email' => (string) ($cesadoPor->email ?? ''),
            ] : null,
            'cesado_por_nombre' => $cesadoPorNombre,
            'puede_activar' => $estadoVisible === 'CESADO',
            'contratos_count' => $contratosLaborales->count(),
            'contratos_cerrados_count' => $contratosCerrados->count(),
            'tuvo_contratos_previos' => $contratosCerrados->isNotEmpty() || $contratosLaborales->count() > 1,
            'contrato_actual' => $formatContract($contratoActual),
            'ultimo_contrato_cerrado' => $ultimoContratoCerrado ? array_merge($formatContract($ultimoContratoCerrado) ?? [], [
                'motivo_cese' => trim((string) ($ultimoContratoCerrado->motivo_cese ?? '')),
                'cerrado_por_nombre' => $ultimoCerradoPorNombre,
            ]) : null,
            'telefono' => PersonalNormalizer::combinePhones($telefono1, $telefono2),
            'telefono_1' => $telefono1,
            'telefono_2' => $telefono2,
            'correo' => $this->correo,
            'estado' => $estadoDisplay,
            'estado_operativo' => $estadoVisible,
            'estado_interno' => $estadoPersonal,
            'estado_label' => PersonalFichaCatalog::stateLabel($estadoDisplay),
            'estado_ficha' => $estadoFicha,
            'ficha_id' => $ficha?->id,
            'ficha_submitted_at' => optional($ficha?->submitted_at)->toIso8601String(),
            'ficha_link_expires_at' => optional($ficha?->link?->expires_at)->toIso8601String(),
            'activo' => $estadoVisible === 'ACTIVO',
            'estado_actual' => strtolower($estadoVisible),
            'situacion' => $situacionKey,
            'situacion_label' => $situacionLabel,
            'contrato_datos' => $contratoDatos ? [
                'id' => (string) $contratoDatos->id,
                'downloaded_at' => optional($contratoDatos->downloaded_at)->toIso8601String(),
                'signed_at' => optional($contratoDatos->signed_at)->toIso8601String(),
                'fecha_firma' => optional($contratoDatos->fecha_firma)->toDateString(),
                'signed_contract_original_name' => (string) ($contratoDatos->signed_contract_original_name ?? ''),
            ] : null,
            'contrato_datos_downloaded' => $contratoDatos?->downloaded_at !== null,
            'contrato_firmado' => $contratoDatos?->signed_at !== null,
            'missing_required_ficha_fields' => $missingRequiredFichaFields,
            'bloqueado_bienestar' => $primaryBloqueo !== null,
            'puede_cesar' => $estadoVisible !== 'CESADO',
            'bloqueo_bienestar' => $primaryBloqueo ? [
                'tipo' => (string) $primaryBloqueo->tipo,
                'tipo_label' => method_exists($primaryBloqueo, 'tipoLabel') ? $primaryBloqueo->tipoLabel() : ucfirst(str_replace('_', ' ', (string) $primaryBloqueo->tipo)),
                'motivo' => (string) ($primaryBloqueo->motivo ?? ''),
                'detalle' => (string) ($primaryBloqueo->detalle ?? ''),
                'fecha_inicio' => optional($primaryBloqueo->fecha_inicio)->toDateString(),
                'fecha_fin' => optional($primaryBloqueo->fecha_fin)->toDateString(),
            ] : null,
            'fechas' => $fechas,
            'resumen_bienestar' => [
                'vacaciones' => $vacacionesMsg,
                'descanso_medico' => $descansoMsg,
                'gestacion' => $gestacionMsg !== '' ? $gestacionMsg : 'Sin periodo de gestacion registrado.',
                'parada' => $paradaMsg,
            ],
            'minas' => array_values($mineNames),
            'minas_estado' => $mineStates,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
