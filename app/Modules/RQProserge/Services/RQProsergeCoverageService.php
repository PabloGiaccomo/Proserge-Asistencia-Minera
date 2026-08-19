<?php

namespace App\Modules\RQProserge\Services;

use App\Models\RQProserge;
use App\Models\RQProsergeDetalle;
use App\Models\RQMinaDetalle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RQProsergeCoverageService
{
    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_PARCIAL = 'PARCIAL';
    public const ESTADO_COMPLETADO = 'COMPLETADO';

    public function calculateForDetalle(RQMinaDetalle $detalle): array
    {
        $detalle->loadMissing('asignaciones');

        return $this->calculateFromAssignments($detalle, collect($detalle->asignaciones ?? []));
    }

    public function calculateForRq(RQProserge $rq): array
    {
        $detalles = RQMinaDetalle::query()
            ->with('asignaciones')
            ->where('rq_mina_id', $rq->rq_mina_id)
            ->get();

        $detalleMetrics = $detalles
            ->mapWithKeys(fn (RQMinaDetalle $detalle): array => [
                (string) $detalle->id => $this->calculateForDetalle($detalle),
            ]);

        $global = [
            'titular_objetivo' => $detalleMetrics->sum('titular_objetivo'),
            'respaldo_objetivo' => $detalleMetrics->sum('respaldo_objetivo'),
            'total_objetivo' => $detalleMetrics->sum('total_objetivo'),
            'titulares_regulares' => $detalleMetrics->sum('titulares_regulares'),
            'suplentes_regulares' => $detalleMetrics->sum('suplentes_regulares'),
            'adicionales_titulares' => $detalleMetrics->sum('adicionales_titulares'),
            'adicionales_suplentes' => $detalleMetrics->sum('adicionales_suplentes'),
            'adicionales_como_respaldo' => $detalleMetrics->sum('adicionales_como_respaldo'),
            'sin_clasificar' => $detalleMetrics->sum('sin_clasificar'),
            'retirados' => $detalleMetrics->sum('retirados'),
            'reemplazados' => $detalleMetrics->sum('reemplazados'),
            'titular_efectivo' => $detalleMetrics->sum('titular_efectivo'),
            'respaldo_efectivo' => $detalleMetrics->sum('respaldo_efectivo'),
            'brecha_titular' => $detalleMetrics->sum('brecha_titular'),
            'brecha_respaldo' => $detalleMetrics->sum('brecha_respaldo'),
            'requiere_clasificacion' => $detalleMetrics->contains(fn (array $item): bool => (bool) $item['requiere_clasificacion']),
        ];

        $global['porcentaje_titular'] = $this->percentage($global['titular_efectivo'], $global['titular_objetivo']);
        $global['porcentaje_respaldo'] = $this->percentage($global['respaldo_efectivo'], $global['respaldo_objetivo']);
        $global['porcentaje_total'] = $this->percentage($global['titular_efectivo'] + $global['respaldo_efectivo'], $global['total_objetivo']);

        return [
            'detalles' => $detalleMetrics->all(),
            'global' => $global,
            'estado' => $this->headerState($detalleMetrics),
        ];
    }

    public function syncDetalle(RQMinaDetalle $detalle): array
    {
        $metrics = $this->calculateForDetalle($detalle);

        RQMinaDetalle::query()
            ->where('id', $detalle->id)
            ->update([
                'cantidad_atendida' => $metrics['cantidad_atendida'],
                'updated_at' => now(),
            ]);

        return $metrics;
    }

    public function syncRq(RQProserge $rq): array
    {
        $detalles = RQMinaDetalle::query()
            ->with('asignaciones')
            ->where('rq_mina_id', $rq->rq_mina_id)
            ->get();

        $detalleMetrics = [];

        foreach ($detalles as $detalle) {
            $metrics = $this->calculateForDetalle($detalle);
            $detalleMetrics[(string) $detalle->id] = $metrics;
            DB::table('rq_mina_detalle')->where('id', $detalle->id)->update([
                'cantidad_atendida' => $metrics['cantidad_atendida'],
                'updated_at' => now(),
            ]);
        }

        $matrix = collect($detalleMetrics);
        return [
            'detalles' => $detalleMetrics,
            'estado' => $this->headerState($matrix),
        ];
    }

    public function calculateFromAssignments(RQMinaDetalle $detalle, Collection $assignments): array
    {
        $titularObjetivo = max(0, (int) $detalle->cantidad);
        $respaldoObjetivo = max(0, (int) $detalle->cantidad_backup);
        $totalObjetivo = max(0, (int) ($detalle->cantidad_total ?: ($titularObjetivo + $respaldoObjetivo)));

        $active = $assignments->filter(fn (RQProsergeDetalle $assignment): bool => $assignment->isActiva());
        $titularesRegulares = $active->filter(fn (RQProsergeDetalle $assignment): bool => $assignment->isTitularRegular())->count();
        $suplentesRegulares = $active->filter(fn (RQProsergeDetalle $assignment): bool => $assignment->isSuplenteRegular())->count();
        $adicionalesTitulares = $active
            ->filter(fn (RQProsergeDetalle $assignment): bool => $assignment->posicion_asignacion === RQProsergeDetalle::POSICION_TITULAR && $assignment->isAdicional())
            ->count();
        $adicionalesSuplentes = $active
            ->filter(fn (RQProsergeDetalle $assignment): bool => $assignment->posicion_asignacion === RQProsergeDetalle::POSICION_SUPLENTE && $assignment->isAdicional())
            ->count();
        $sinClasificar = $active->filter(fn (RQProsergeDetalle $assignment): bool => $assignment->isSinClasificar())->count();

        $titularGapBeforeLegacy = max(0, $titularObjetivo - $titularesRegulares);
        $legacyForTitular = min($sinClasificar, $titularGapBeforeLegacy);
        $legacyRemaining = max(0, $sinClasificar - $legacyForTitular);
        $adicionalesTotal = $adicionalesTitulares + $adicionalesSuplentes;
        $respaldoGapBeforeAdicional = max(0, $respaldoObjetivo - $suplentesRegulares);
        $adicionalesComoRespaldo = min($adicionalesTotal, $respaldoGapBeforeAdicional);
        $respaldoBeforeLegacy = $suplentesRegulares + $adicionalesComoRespaldo;
        $respaldoGapBeforeLegacy = max(0, $respaldoObjetivo - $respaldoBeforeLegacy);
        $legacyForRespaldo = min($legacyRemaining, $respaldoGapBeforeLegacy);

        $titularEfectivo = $titularesRegulares + $legacyForTitular;
        $respaldoEfectivo = $respaldoBeforeLegacy + $legacyForRespaldo;
        $brechaTitular = max(0, $titularObjetivo - $titularEfectivo);
        $brechaRespaldo = max(0, $respaldoObjetivo - $respaldoEfectivo);
        $cantidadAtendida = min($totalObjetivo, $titularEfectivo + $respaldoEfectivo);

        return [
            'titular_objetivo' => $titularObjetivo,
            'respaldo_objetivo' => $respaldoObjetivo,
            'total_objetivo' => $totalObjetivo,
            'titulares_regulares' => $titularesRegulares,
            'suplentes_regulares' => $suplentesRegulares,
            'adicionales_titulares' => $adicionalesTitulares,
            'adicionales_suplentes' => $adicionalesSuplentes,
            'adicionales_total' => $adicionalesTotal,
            'adicionales_como_respaldo' => $adicionalesComoRespaldo,
            'sin_clasificar' => $sinClasificar,
            'retirados' => $assignments->filter(fn (RQProsergeDetalle $assignment): bool => $assignment->isRetirada())->count(),
            'reemplazados' => $assignments->filter(fn (RQProsergeDetalle $assignment): bool => $assignment->isReemplazada())->count(),
            'titular_efectivo' => $titularEfectivo,
            'respaldo_efectivo' => $respaldoEfectivo,
            'brecha_titular' => $brechaTitular,
            'brecha_respaldo' => $brechaRespaldo,
            'porcentaje_titular' => $this->percentage($titularEfectivo, $titularObjetivo),
            'porcentaje_respaldo' => $this->percentage($respaldoEfectivo, $respaldoObjetivo),
            'porcentaje_total' => $this->percentage($titularEfectivo + $respaldoEfectivo, $totalObjetivo),
            'cantidad_atendida' => $cantidadAtendida,
            'requiere_clasificacion' => $sinClasificar > 0,
            'estado_cobertura' => $this->detailState($active->count(), $brechaTitular, $brechaRespaldo),
        ];
    }

    private function detailState(int $activeCount, int $brechaTitular, int $brechaRespaldo): string
    {
        if ($activeCount === 0) {
            return self::ESTADO_PENDIENTE;
        }

        if ($brechaTitular === 0 && $brechaRespaldo === 0) {
            return self::ESTADO_COMPLETADO;
        }

        return self::ESTADO_PARCIAL;
    }

    private function headerState(Collection $detalleMetrics): string
    {
        if ($detalleMetrics->isEmpty() || $detalleMetrics->every(fn (array $item): bool => $item['estado_cobertura'] === self::ESTADO_PENDIENTE)) {
            return self::ESTADO_PENDIENTE;
        }

        if ($detalleMetrics->every(fn (array $item): bool => $item['estado_cobertura'] === self::ESTADO_COMPLETADO)) {
            return self::ESTADO_COMPLETADO;
        }

        return self::ESTADO_PARCIAL;
    }

    private function percentage(int|float $value, int|float $target): float
    {
        if ($target <= 0) {
            return $value > 0 ? 100.0 : 0.0;
        }

        return round(min(100, ($value / $target) * 100), 1);
    }
}
