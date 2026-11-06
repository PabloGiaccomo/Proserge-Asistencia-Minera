<?php

namespace App\Modules\Transporte\Support;

use App\Models\TransporteServicio;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class TransportePlanningNavigation
{
    public static function listFilters(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        return array_filter(Arr::only($input, [
            'q', 'mina_id', 'estado', 'created_by_usuario_id', 'fecha_inicio_desde', 'fecha_inicio_hasta',
            'fecha_fin_desde', 'fecha_fin_hasta', 'page', 'per_page',
        ]), static fn ($value): bool => is_scalar($value) && $value !== '');
    }

    public static function url(Request $request, bool $failed = false, ?TransporteServicio $service = null, bool $absolute = true): string
    {
        $context = $request->input('contexto');
        if (!is_array($context)) {
            $context = $service ? [
                'rq_mina_id' => $service->rq_mina_id,
                'rq_mina_plan_id' => $service->rq_mina_plan_id,
                'fecha' => $service->fecha?->toDateString(),
                'turno' => $service->turno,
            ] : $request->only(['rq_mina_id', 'rq_mina_plan_id', 'fecha', 'turno', 'lista']);
        }

        $filters = [];
        foreach (['rq_mina_id', 'rq_mina_plan_id'] as $key) {
            if (is_string($context[$key] ?? null) && Str::isUuid($context[$key])) {
                $filters[$key] = $context[$key];
            }
        }

        $date = $context['fecha'] ?? null;
        $parsedDate = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $date)
            : false;
        if ($parsedDate && $parsedDate->format('Y-m-d') === $date) {
            $filters['fecha'] = $date;
        }
        $turn = $context['turno'] ?? null;
        if (in_array($turn, ['A', 'B', 'DIA', 'NOCHE'], true)) {
            $filters['turno'] = $turn;
        }
        $filters['lista'] = self::listFilters($context['lista'] ?? []);

        $anchor = '';
        if ($failed) {
            $id = $request->route('servicio');
            if ($request->routeIs('transporte.servicios.update') && is_string($id) && Str::isUuid($id)) {
                $filters['editar'] = $id;
                $anchor = '#transporte-editar';
            } else {
                $anchor = $request->routeIs('transporte.servicios.store') ? '#transporte-nuevo' : '#transporte-servicios';
            }
        }

        return route('transporte.planificacion', $filters, $absolute).$anchor;
    }
}
