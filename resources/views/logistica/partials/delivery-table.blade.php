@php
    $rowsCollection = $rows instanceof \Illuminate\Contracts\Pagination\Paginator
        ? collect($rows->items())
        : collect($rows ?? []);

    $fallback = '—';

    $formatDate = static function ($value) use ($fallback): string {
        if (blank($value)) {
            return $fallback;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    };

    $resolve = static function ($row, array $keys) use ($fallback): string {
        foreach ($keys as $key) {
            $value = data_get($row, $key);

            if (! blank($value)) {
                return (string) $value;
            }
        }

        return $fallback;
    };
@endphp

@if($rowsCollection->isEmpty())
    <div class="logistics-empty">
        <strong>No hay registros para mostrar</strong>
    </div>
@else
    <div class="logistics-table-wrap">
        <table class="logistics-table">
            <thead>
                <tr>
                    <th>Trabajador</th>
                    <th>DNI</th>
                    <th>Item / EPP</th>
                    <th>Talla</th>
                    <th>Fecha entrega</th>
                    <th>Fecha vencimiento</th>
                    @if($showDays ?? false)
                        <th>Dias restantes</th>
                        <th>Dias efectivos usados</th>
                    @endif
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rowsCollection as $row)
                    @php
                        $worker = $resolve($row, [
                            'trabajador',
                            'personal.nombre_completo',
                            'personal.nombre',
                            'personal.apellidos_nombres',
                            'nombre_trabajador',
                        ]);

                        $dni = $resolve($row, [
                            'dni',
                            'documento',
                            'personal.dni',
                            'personal.numero_documento',
                            'personal.documento',
                        ]);

                        $item = $resolve($row, [
                            'item',
                            'epp',
                            'epp_nombre',
                            'epp.nombre',
                            'registro.nombre',
                            'catalogo.nombre',
                        ]);

                        $size = $resolve($row, [
                            'talla',
                            'size',
                            'detalle.talla',
                            'epp_talla',
                        ]);

                        $deliveryDate = $formatDate($resolve($row, [
                            'fecha_entrega',
                            'entrega',
                            'fecha',
                            'created_at',
                        ]));

                        $expirationDate = $formatDate($resolve($row, [
                            'fecha_vencimiento',
                            'vencimiento',
                            'vence',
                            'vence_el',
                            'fecha_renovacion',
                        ]));

                        $days = $resolve($row, [
                            'dias_restantes',
                            'days_remaining',
                            'dias',
                            'remaining_days',
                        ]);

                        $effectiveUsage = $resolve($row, [
                            'uso_efectivo',
                            'dias_uso_efectivo_label',
                        ]);

                        if ($effectiveUsage === $fallback) {
                            $effectiveDays = data_get($row, 'dias_uso_efectivo');
                            $lifeDays = data_get($row, 'vida_dias');
                            $effectiveUsage = $effectiveDays !== null && $lifeDays !== null
                                ? ((int) $effectiveDays) . ' / ' . ((int) $lifeDays) . ' dias'
                                : $fallback;
                        }

                        $state = $resolve($row, [
                            'estado_label',
                            'estado_visual',
                            'estado',
                            'status',
                        ]);
                    @endphp
                    <tr>
                        <td>{{ $worker }}</td>
                        <td>{{ $dni }}</td>
                        <td>{{ $item }}</td>
                        <td>{{ $size }}</td>
                        <td>{{ $deliveryDate }}</td>
                        <td>{{ $expirationDate }}</td>
                        @if($showDays ?? false)
                            <td>{{ $days }}</td>
                            <td>{{ $effectiveUsage }}</td>
                        @endif
                        <td>{{ $state }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($rows instanceof \Illuminate\Contracts\Pagination\Paginator)
        {{ $rows->withQueryString()->links() }}
    @endif
@endif
