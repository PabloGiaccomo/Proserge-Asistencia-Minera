<?php

namespace App\Modules\Personal\Resources;

use App\Models\PersonalContrato;
use App\Models\PersonalFicha;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Modules\Personal\Support\PersonalNormalizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonalIndexResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = Carbon::today();
        $todayString = $today->toDateString();
        $telefono1 = $this->telefono_1 ?? null;
        $telefono2 = $this->telefono_2 ?? null;
        $estadoPersonal = strtoupper((string) $this->estado);
        $ficha = $this->whenLoaded('fichaColaborador', fn () => $this->fichaColaborador, null);
        $contratoDatos = $this->whenLoaded('contratoDatos', fn () => $this->contratoDatos, null);
        $cesadoPor = $this->whenLoaded('cesadoPor', fn () => $this->cesadoPor, null);
        $listaNegraPor = $this->whenLoaded('listaNegraPor', fn () => $this->listaNegraPor, null);
        $contratosLaborales = collect($this->whenLoaded('contratosLaborales', fn () => $this->contratosLaborales, collect()))->values();
        $contratosOperativos = $contratosLaborales
            ->reject(fn ($contratoLaboral): bool => strtoupper((string) ($contratoLaboral->estado ?? '')) === PersonalContrato::ESTADO_ANULADO)
            ->values();
        $activeBloqueos = collect($this->whenLoaded('bloqueos', fn () => $this->bloqueos, collect()))
            ->filter(function ($bloqueo) use ($todayString): bool {
                $inicio = optional($bloqueo->fecha_inicio)->toDateString();
                $fin = optional($bloqueo->fecha_fin)->toDateString();

                return $inicio !== null && $fin !== null && $inicio <= $todayString && $fin >= $todayString;
            })
            ->values();

        $primaryBloqueo = $activeBloqueos
            ->sortBy(function ($bloqueo): int {
                return match ((string) $bloqueo->tipo) {
                    'gestacion', 'descanso_medico' => 1,
                    'inhabilitado' => 2,
                    'restriccion_temporal' => 3,
                    'vacaciones' => 4,
                    default => 5,
                };
            })
            ->first();

        // Pre-compute sort keys once, avoid redundant sorting across 7+ lookups
        $sortKeys = [];
        foreach ($contratosOperativos as $c) {
            $sortKeys[$c->id] = PersonalNormalizer::contractHistorySortKey($c);
        }
        $sortedContracts = $contratosOperativos->sortByDesc(fn ($c): string => $sortKeys[$c->id] ?? '')->values();

        $ultimoContratoLaboral = $sortedContracts->first();
        $contratoActual = $sortedContracts->first(fn ($c): bool => in_array(strtoupper((string) ($c->estado ?? '')), ['PREPARACION', 'ACTIVO'], true));
        $contratoActivo = $sortedContracts->first(fn ($c): bool => strtoupper((string) ($c->estado ?? '')) === 'ACTIVO');
        $contratoVigenteFirmado = $sortedContracts->first(fn ($c): bool => strtoupper((string) ($c->estado ?? '')) === 'ACTIVO'
            && $c->signed_at
            && trim((string) ($c->signed_contract_path ?? '')) !== ''
            && (!optional($c->fecha_fin)->toDateString()
                || optional($c->fecha_fin)->toDateString() >= $todayString));
        $contratoPreparacion = $sortedContracts->first(fn ($c): bool => strtoupper((string) ($c->estado ?? '')) === 'PREPARACION');

        $contratosCerrados = $sortedContracts
            ->filter(fn ($c): bool => PersonalNormalizer::isFinalizedContractState($c->estado ?? ''))
            ->values();
        $ultimoContratoCerrado = $contratosCerrados->first();

        $contrato = PersonalNormalizer::contract($this->contrato);
        $fichaData = is_array($ficha?->datos_json ?? null) ? $ficha->datos_json : [];
        $fichaData['tipo_documento'] = $fichaData['tipo_documento'] ?? $ficha?->tipo_documento ?? $this->tipo_documento ?? 'DNI';
        $fichaData['numero_documento'] = $fichaData['numero_documento'] ?? $ficha?->numero_documento ?? $this->numero_documento ?? $this->dni;
        $fechaFinContrato = PersonalNormalizer::isoDate(
            optional($contratoActivo?->fecha_fin)->toDateString()
                ?? ($contratoActivo ? null : (optional($ultimoContratoCerrado?->fecha_fin)->toDateString()
                    ?? ($fichaData['fecha_fin_contrato'] ?? null)))
        );
        $fechaCese = PersonalNormalizer::isoDate($fichaData['fecha_cese'] ?? $this->fecha_cese ?? null);
        $missingRequiredFichaFields = $this->missingRequiredFichaFields($fichaData, $ficha !== null);
        $hasCurrentSignedContract = $this->hasCurrentSignedContract($contratoDatos, $contratoActual, $contratoVigenteFirmado);
        $hasOperationalContractDates = $this->hasOperationalContractDates($contratoActual, $contratoActivo, $todayString);

        if (!$telefono1 && !empty($this->telefono)) {
            $phoneData = PersonalNormalizer::normalizePhonePayload($this->telefono);
            $telefono1 = $phoneData['telefono_1'];
            $telefono2 = $phoneData['telefono_2'];
        }

        $mineNames = [];
        $mineStates = [];
        foreach ($this->whenLoaded('minas', fn () => $this->minas, collect()) as $mina) {
            $mineNames[] = $mina->nombre;
            $mineStates[$mina->nombre] = PersonalNormalizer::mineStatusLabel($mina->pivot?->estado_habilitacion ?: $mina->pivot?->estado);
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

        $hasParadaActiva = $activeBloqueos->contains(function ($bloqueo): bool {
            return !in_array((string) ($bloqueo->tipo ?? ''), ['vacaciones', 'descanso_medico', 'gestacion'], true);
        });
        $hasRqProsergeParadaActiva = collect($this->whenLoaded('rqProsergeDetalles', fn () => $this->rqProsergeDetalles, collect()))->isNotEmpty();

        $ultimoContratoFinalizadoSinVigente = !$hasCurrentSignedContract
            && $ultimoContratoLaboral !== null
            && PersonalNormalizer::isFinalizedContractState($ultimoContratoLaboral->estado ?? '');
        $contratoVencido = !$hasCurrentSignedContract && $fechaFinContrato !== null && $fechaFinContrato !== '' && $fechaFinContrato < $todayString;
        $ceseVigente = $fechaCese !== null && $fechaCese !== '' && $fechaCese <= $todayString;
        $revisarFicha = in_array($estadoPersonal, ['FICHA_ENVIADA', 'OBSERVADO'], true);
        $fichaAprobada = strtoupper((string) ($ficha?->estado ?? '')) === PersonalFicha::ESTADO_APROBADO;
        $faltaContrato = $estadoPersonal === 'FALTA_CONTRATO';
        $noFirmoContrato = $estadoPersonal === 'NO_FIRMO_CONTRATO';
        $pendienteContratoFirmado = (bool) ($this->pendiente_contrato_firmado ?? false);
        $faltaContratoSoloPorAdjunto = $faltaContrato && $hasOperationalContractDates;
        $faltaContratoOperativo = $faltaContrato && !$faltaContratoSoloPorAdjunto;
        $terminarFicha = !$hasCurrentSignedContract && (
            in_array($estadoPersonal, ['PENDIENTE_COMPLETAR_FICHA', 'LINK_VENCIDO'], true)
            || $ficha === null
            || (!$fichaAprobada && count($missingRequiredFichaFields) > 0)
        );
        $bienestarInactivo = $primaryBloqueo && in_array((string) $primaryBloqueo->tipo, ['vacaciones', 'descanso_medico'], true);
        $intermitenteActivo = $contrato === 'INTER' && ($hasParadaActiva || $hasRqProsergeParadaActiva);
        $trabajadorNoIntermitenteActivo = $contrato !== 'INTER';

        $estadoVisible = match (true) {
            $estadoPersonal === 'CESADO' || $ultimoContratoFinalizadoSinVigente || $contratoVencido || $ceseVigente => 'CESADO',
            $noFirmoContrato => 'INACTIVO',
            $faltaContrato, $terminarFicha => 'INACTIVO',
            $bienestarInactivo || ($primaryBloqueo && (string) $primaryBloqueo->tipo === 'gestacion') => 'INACTIVO',
            $contrato === 'INTER' && !$intermitenteActivo => 'INACTIVO',
            $trabajadorNoIntermitenteActivo || $intermitenteActivo => 'ACTIVO',
            default => 'INACTIVO',
        };
        $estadoDisplay = match (true) {
            $estadoVisible === 'CESADO' => 'CESADO',
            $noFirmoContrato => 'NO_FIRMO_CONTRATO',
            $faltaContratoOperativo && $estadoVisible !== 'CESADO' => 'FALTA_CONTRATO',
            default => $estadoVisible,
        };

        $situacionKey = match (true) {
            $estadoVisible === 'CESADO' => 'no_habilitado',
            $noFirmoContrato => 'no_firmo_contrato',
            $faltaContratoOperativo => 'falta_contrato',
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
            default => 'habilitado',
        };

        $situacionLabel = match ($situacionKey) {
            'revisar_ficha' => 'Revisar ficha',
            'falta_contrato' => 'Falta contrato firmado',
            'no_firmo_contrato' => 'No firmo contrato',
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

        $puestoCatalogo = $this->puestoCatalogo ?? null;
        $puestoNombre = trim((string) ($puestoCatalogo?->nombre ?: $this->puesto));
        $cesadoPorNombre = trim((string) ($cesadoPor?->personal?->nombre_completo ?: $cesadoPor?->email ?: ''));
        $listaNegraPorNombre = trim((string) ($listaNegraPor?->personal?->nombre_completo ?: $listaNegraPor?->email ?: ''));
        $baseReentryContract = $contratoActual
            ?: $ultimoContratoCerrado
            ?: $sortedContracts->first();
        $reentryData = [
            'puesto' => $puestoNombre,
            'tipo_contrato' => PersonalNormalizer::contract($baseReentryContract?->tipo_contrato ?: $contrato),
            'ocupacion' => (string) ($this->ocupacion ?? ($fichaData['ocupacion'] ?? '')),
            'area' => (string) ($baseReentryContract?->area ?? ''),
            'remuneracion' => (string) ($contratoDatos?->sueldo_num ?: ($baseReentryContract?->remuneracion ?: ($fichaData['remuneracion'] ?? ''))),
            'costo_hora' => (string) ($contratoDatos?->sueldo_hora_paradas ?: ($baseReentryContract?->costo_hora ?: '')),
            'banco' => (string) ($fichaData['banco'] ?? ''),
            'banco_otro' => (string) ($fichaData['banco_otro'] ?? ''),
            'numero_cuenta' => (string) ($fichaData['numero_cuenta'] ?? ''),
            'cci' => (string) ($fichaData['cci'] ?? ''),
            'sistema_pensionario' => (string) ($fichaData['sistema_pensionario'] ?? ''),
            'tipo_comision' => (string) ($fichaData['tipo_comision'] ?? ''),
            'tipo_afp' => (string) ($fichaData['tipo_afp'] ?? ''),
            'cuspp' => (string) ($fichaData['cuspp'] ?? ''),
        ];

        return [
            'id' => $this->id,
            'dni' => $this->dni,
            'tipo_documento' => $this->tipo_documento ?? 'DNI',
            'numero_documento' => $this->numero_documento ?? $this->dni,
            'nombre' => $this->nombre_completo,
            'nombre_completo' => $this->nombre_completo,
            'puesto' => $puestoNombre,
            'puesto_id' => (string) ($this->puesto_id ?? ''),
            'puesto_funciones' => (string) ($puestoCatalogo?->funciones ?? ''),
            'ocupacion' => $this->ocupacion,
            'contrato' => $contrato,
            'tipo_contrato' => PersonalNormalizer::contractLabel($contrato),
            'supervisor' => (bool) $this->es_supervisor,
            'es_supervisor' => (bool) $this->es_supervisor,
            'fecha_ingreso' => optional($this->fecha_ingreso)->toDateString(),
            'fecha_fin_contrato' => $fechaFinContrato,
            'fecha_cese' => $fechaCese,
            'motivo_cese' => $this->motivoCeseVisible($estadoVisible, $ultimoContratoCerrado, $contratoVencido, $ceseVigente),
            'cesado_por_nombre' => $cesadoPorNombre,
            'puede_activar' => $estadoVisible === 'CESADO',
            'contratos_count' => $contratosOperativos->count(),
            'contratos_historial_count' => $contratosLaborales->count(),
            'contratos_cerrados_count' => $contratosCerrados->count(),
            'tuvo_contratos_previos' => $contratosCerrados->isNotEmpty() || $contratosOperativos->count() > 1,
            'contrato_actual' => $this->formatContract($contratoActual),
            'contrato_vigente_firmado' => $this->formatContract($contratoVigenteFirmado),
            'contrato_preparacion' => $this->formatContract($contratoPreparacion),
            'renovacion_en_preparacion' => strtoupper((string) ($contratoPreparacion?->tipo_movimiento ?? '')) === 'RENOVACION',
            'ultimo_contrato_cerrado' => $this->formatContract($ultimoContratoCerrado, true),
            'reactivacion_datos' => $reentryData,
            'telefono' => PersonalNormalizer::combinePhones($telefono1, $telefono2),
            'telefono_1' => $telefono1,
            'telefono_2' => $telefono2,
            'correo' => $this->correo,
            'estado' => $estadoDisplay,
            'estado_operativo' => $estadoVisible,
            'estado_interno' => $estadoPersonal,
            'estado_label' => PersonalFichaCatalog::stateLabel($estadoDisplay),
            'estado_ficha' => $ficha?->estado,
            'ficha_id' => $ficha?->id,
            'ficha_submitted_at' => optional($ficha?->submitted_at)->toIso8601String(),
            'ficha_link_expires_at' => null,
            'activo' => $estadoVisible === 'ACTIVO',
            'estado_actual' => strtolower($estadoVisible),
            'origen_registro' => (string) ($this->origen_registro ?? 'NUEVO'),
            'observacion_historica' => (string) ($this->observacion_historica ?? ''),
            'pendiente_regularizacion' => (bool) ($this->pendiente_regularizacion ?? false),
            'pendiente_contrato_firmado' => $pendienteContratoFirmado,
            'en_lista_negra' => (bool) ($this->en_lista_negra ?? false),
            'lista_negra_motivo' => (string) ($this->lista_negra_motivo ?? ''),
            'lista_negra_at' => optional($this->lista_negra_at)->toIso8601String(),
            'lista_negra_fecha' => optional($this->lista_negra_at)->format('d/m/Y H:i'),
            'lista_negra_por_nombre' => $listaNegraPorNombre,
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
            'contrato_firmado' => $hasCurrentSignedContract,
            'missing_required_ficha_fields' => $missingRequiredFichaFields,
            'bloqueado_bienestar' => $primaryBloqueo !== null,
            'puede_cesar' => $estadoVisible === 'ACTIVO' && $estadoPersonal === 'ACTIVO',
            'bloqueo_bienestar' => $this->formatBloqueo($primaryBloqueo),
            'fechas' => [
                'ingreso' => optional($this->fecha_ingreso)->toDateString(),
                'vacaciones' => null,
                'enfermo' => null,
                'parada' => null,
            ],
            'resumen_bienestar' => [
                'vacaciones' => 'Sin vacaciones proximas en los siguientes 2 meses. Disponible por ahora.',
                'descanso_medico' => 'Sin descanso medico vigente. Estado de salud operativo.',
                'gestacion' => 'Sin periodo de gestacion registrado.',
                'parada' => $hasParadaActiva ? 'Parada vigente registrada.' : 'Sin parada vigente en este momento.',
            ],
            'minas' => array_values($mineNames),
            'minas_estado' => $mineStates,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }

    private function missingRequiredFichaFields(array $fichaData, bool $hasFicha): array
    {
        if (!$hasFicha) {
            return PersonalFichaCatalog::requiredKeys();
        }

        return collect(PersonalFichaCatalog::requiredKeys())
            ->filter(function (string $key) use ($fichaData): bool {
                $value = $fichaData[$key] ?? null;

                if (is_array($value)) {
                    return count(array_filter($value, static fn ($item) => filled($item))) === 0;
                }

                return !filled($value);
            })
            ->values()
            ->all();
    }

    private function hasCurrentSignedContract($contratoDatos, $contratoActual, $contratoVigenteFirmado = null): bool
    {
        if ($contratoVigenteFirmado?->signed_at && trim((string) ($contratoVigenteFirmado->signed_contract_path ?? '')) !== '') {
            return true;
        }

        $today = Carbon::today()->toDateString();
        $contratoActualEstado = strtoupper((string) ($contratoActual->estado ?? ''));
        $contratoActualFin = optional($contratoActual?->fecha_fin)->toDateString();
        if ($contratoActualEstado === 'ACTIVO'
            && $contratoActual?->signed_at
            && trim((string) ($contratoActual->signed_contract_path ?? '')) !== ''
            && (!$contratoActualFin || $contratoActualFin >= $today)) {
            return true;
        }

        if ($contratoActualEstado === 'ACTIVO' && $contratoActualFin && $contratoActualFin < $today) {
            return false;
        }

        if (!$contratoDatos?->signed_at || trim((string) ($contratoDatos?->signed_contract_path ?? '')) === '') {
            return false;
        }

        if (!$contratoActual?->activado_at) {
            return true;
        }

        return $contratoDatos->signed_at->greaterThanOrEqualTo($contratoActual->activado_at);
    }

    private function hasOperationalContractDates($contratoActual, $contratoActivo, string $todayString): bool
    {
        $contract = $contratoActivo ?: $contratoActual;

        if (!$contract) {
            return false;
        }

        $estado = strtoupper((string) ($contract->estado ?? ''));
        if (!in_array($estado, [PersonalContrato::ESTADO_ACTIVO, PersonalContrato::ESTADO_PREPARACION], true)) {
            return false;
        }

        $inicio = optional($contract->fecha_inicio)->toDateString();
        $fin = optional($contract->fecha_fin)->toDateString();

        return $inicio !== null && (!$fin || $fin >= $todayString);
    }

    private function formatContract($contract, bool $includeClosedData = false): ?array
    {
        if (!$contract) {
            return null;
        }

        $inicio = optional($contract->fecha_inicio)->toDateString();
        $fin = optional($contract->fecha_fin)->toDateString();
        $cerradoPor = $contract->relationLoaded('cerradoPor') ? $contract->cerradoPor : null;
        $cerradoPorNombre = trim((string) ($cerradoPor?->personal?->nombre_completo ?: $cerradoPor?->email ?: ''));

        $data = [
            'id' => (string) $contract->id,
            'numero' => (int) $contract->contrato_numero,
            'label' => 'Contrato ' . trim(($this->formatDate($inicio) ?: 'sin inicio') . ' al ' . ($fin ? $this->formatDate($fin) : 'Vigente')),
            'estado' => (string) $contract->estado,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'fecha_inicio_label' => $this->formatDate($inicio),
            'fecha_fin_label' => $fin ? $this->formatDate($fin) : 'Vigente',
            'motivo_cese' => (string) ($contract->motivo_cese ?? ''),
            'activado_at' => optional($contract->activado_at)->toIso8601String(),
            'cerrado_at' => optional($contract->cerrado_at)->toIso8601String(),
            'cerrado_por_nombre' => $cerradoPorNombre,
        ];

        if ($includeClosedData && trim($data['motivo_cese']) === '') {
            $data['motivo_cese'] = 'Motivo no registrado';
        }

        return $data;
    }

    private function formatDate($date): string
    {
        if (!$date) {
            return '-';
        }

        try {
            return Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable) {
            return '-';
        }
    }

    private function motivoCeseVisible(string $estadoVisible, $ultimoContratoCerrado, bool $contratoVencido, bool $ceseVigente): string
    {
        $motivoCese = trim((string) ($this->motivo_cese ?? ''));

        return match (true) {
            $estadoVisible !== 'CESADO' => '',
            $motivoCese !== '' => $motivoCese,
            $ultimoContratoCerrado && trim((string) ($ultimoContratoCerrado->motivo_cese ?? '')) !== '' => trim((string) $ultimoContratoCerrado->motivo_cese),
            $contratoVencido => 'Termino de contrato',
            $ceseVigente => 'Cese programado',
            default => 'Motivo no registrado',
        };
    }

    private function formatBloqueo($bloqueo): ?array
    {
        if (!$bloqueo) {
            return null;
        }

        return [
            'tipo' => (string) $bloqueo->tipo,
            'tipo_label' => method_exists($bloqueo, 'tipoLabel') ? $bloqueo->tipoLabel() : ucfirst(str_replace('_', ' ', (string) $bloqueo->tipo)),
            'motivo' => (string) ($bloqueo->motivo ?? ''),
            'detalle' => (string) ($bloqueo->detalle ?? ''),
            'fecha_inicio' => optional($bloqueo->fecha_inicio)->toDateString(),
            'fecha_fin' => optional($bloqueo->fecha_fin)->toDateString(),
        ];
    }
}
