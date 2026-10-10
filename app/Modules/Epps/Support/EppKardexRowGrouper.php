<?php

namespace App\Modules\Epps\Support;

/** Pack units into separate item columns without crossing an evidence boundary. */
final class EppKardexRowGrouper
{
    public static function group(iterable $events): array
    {
        $groups = self::index($events);

        return self::window($groups, 0, array_sum(array_column($groups, 'count')));
    }

    public static function paginate(iterable $events, int $page, int $perPage): array
    {
        $groups = self::index($events);
        $total = array_sum(array_column($groups, 'count'));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;
        $rows = self::window($groups, $offset, $perPage);

        return ['rows' => $rows, 'pagination' => [
            'current_page' => $page, 'per_page' => $perPage, 'total' => $total,
            'last_page' => $lastPage, 'from' => $total ? $offset + 1 : 0,
            'to' => $total ? $offset + count($rows) : 0,
        ]];
    }

    /** Index quantity ranges, not individual units, before selecting a page. */
    private static function index(iterable $events): array
    {
        $groups = [];
        $sequence = 0;
        foreach ($events as $event) {
            $quantity = (int) $event['quantity'];
            if ($quantity < 1) {
                continue;
            }
            $batch = $event['batch_id'] ?? null;
            $key = json_encode([$event['date'], $event['code'], $batch ?: 'unidentified-'.($sequence++)]);
            $column = $event['column_key'];
            $offset = $groups[$key]['quantities'][$column] ?? 0;
            $end = $offset + $quantity;
            $groups[$key]['columns'][$column][] = ['start' => $offset, 'end' => $end, 'event' => $event];
            $groups[$key]['quantities'][$column] = $end;
            $groups[$key]['count'] = max($groups[$key]['count'] ?? 0, $end);
        }

        return array_values($groups);
    }

    private static function window(array $groups, int $offset, int $limit): array
    {
        $rows = [];
        $position = 0;
        foreach ($groups as $group) {
            $start = max(0, $offset - $position);
            $end = min($group['count'], $offset + $limit - $position);
            for ($index = $start; $index < $end; $index++) {
                $packed = null;
                foreach ($group['columns'] as $column => $segments) {
                    foreach ($segments as $segment) {
                        if ($index >= $segment['start'] && $index < $segment['end']) {
                            $event = $segment['event'];
                            $packed ??= ['date' => $event['date'], 'code' => $event['code'], 'batch_id' => $event['batch_id'] ?? null, 'cells' => []];
                            $packed['cells'][$column] = $event;
                            break;
                        }
                    }
                }
                $rows[] = $packed;
            }
            $position += $group['count'];
            if ($position >= $offset + $limit) {
                break;
            }
        }

        return $rows;
    }
}
