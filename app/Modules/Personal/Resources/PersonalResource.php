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
        $estadoFicha = $ficha?->estado;
        $estadoActual = $estadoPersonal === 'ACTIVO' ? 'trabajando' : 'inactivo';

        if (in_array($estadoPersonal, ['PENDIENTE_COMPLETAR_FICHA', 'FICHA_ENVIADA', 'LINK_VENCIDO'], true)) {
            $estadoActual = strtolower($estadoPersonal);
        }

        if ($primaryBloqueo && $estadoPersonal === 'ACTIVO') {
            $estadoActual = match ((string) $primaryBloqueo->tipo) {
                'vacaciones' => 'vacaciones',
                'descanso_medico' => 'enfermo',
                default => 'bloqueado_bienestar',
            };
        }

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

        $paradaTypes = $allBloqueos
            ->filter(function ($b): bool {
                return !in_array((string) ($b->tipo ?? ''), ['vacaciones', 'descanso_medico'], true);
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

        return [
            'id' => $this->id,
            'dni' => $this->dni,
            'tipo_documento' => $this->tipo_documento ?? 'DNI',
            'numero_documento' => $this->numero_documento ?? $this->dni,
            'nombre' => $this->nombre_completo,
            'nombre_completo' => $this->nombre_completo,
            'puesto' => $this->puesto,
            'ocupacion' => $this->ocupacion,
            'contrato' => $this->contrato,
            'tipo_contrato' => PersonalNormalizer::contractLabel($this->contrato),
            'supervisor' => (bool) $this->es_supervisor,
            'es_supervisor' => (bool) $this->es_supervisor,
            'fecha_ingreso' => optional($this->fecha_ingreso)->toDateString(),
            'telefono' => PersonalNormalizer::combinePhones($telefono1, $telefono2),
            'telefono_1' => $telefono1,
            'telefono_2' => $telefono2,
            'correo' => $this->correo,
            'estado' => $estadoPersonal,
            'estado_label' => PersonalFichaCatalog::stateLabel($estadoFicha ?: $estadoPersonal),
            'estado_ficha' => $estadoFicha,
            'ficha_id' => $ficha?->id,
            'ficha_submitted_at' => optional($ficha?->submitted_at)->toIso8601String(),
            'ficha_link_expires_at' => optional($ficha?->link?->expires_at)->toIso8601String(),
            'activo' => $estadoPersonal === 'ACTIVO',
            'estado_actual' => $estadoActual,
            'bloqueado_bienestar' => $primaryBloqueo !== null,
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
                'parada' => $paradaMsg,
            ],
            'minas' => array_values($mineNames),
            'minas_estado' => $mineStates,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
