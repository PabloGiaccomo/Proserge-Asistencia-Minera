@php
    $percent = static fn ($value): string => number_format((float) $value, 1) . '%';
    $barPct = static fn ($value, $max): int => $max > 0 ? min(100, (int) round(((float) $value / (float) $max) * 100)) : 0;
    $stockTone = static fn ($value): string => in_array($value, ['OK'], true) ? 'ok' : (in_array($value, ['Bajo'], true) ? 'warning' : 'danger');

    $sizeMissingCounts = collect($missingSizeWorkers ?? []);
@endphp

<form class="lgtx-filters" method="GET" action="{{ url('/logistica') }}" data-logistics-dashboard-filters>
    <input type="hidden" name="tab" value="dashboard">

    <label>
        <span>Buscar</span>
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Trabajador, DNI, cargo o EPP">
    </label>

    <label>
        <span>Mina</span>
        <select name="minas[]">
            <option value="">Todas las minas</option>
            @foreach($asCollection(data_get($options ?? [], 'minas', [])) as $mina)
                <option value="{{ data_get($mina, 'id') }}" @selected(in_array((string) data_get($mina, 'id'), (array) ($filters['minas'] ?? []), true))>
                    {{ data_get($mina, 'nombre', 'Sin mina') }}
                </option>
            @endforeach
        </select>
    </label>

    <label>
        <span>Parada / RQ</span>
        <select name="parada_id">
            <option value="">Todas las paradas</option>
            @foreach($asCollection(data_get($options ?? [], 'paradas', [])) as $parada)
                <option value="{{ data_get($parada, 'id') }}" @selected((string) ($filters['parada_id'] ?? '') === (string) data_get($parada, 'id'))>
                    {{ data_get($parada, 'label', 'Sin parada') }}
                </option>
            @endforeach
        </select>
    </label>

    <label>
        <span>EPP</span>
        <select name="epps[]">
            <option value="">Todos los EPP</option>
            @foreach($asCollection(data_get($options ?? [], 'epps', [])) as $epp)
                <option value="{{ data_get($epp, 'id') }}" @selected(in_array((string) data_get($epp, 'id'), (array) ($filters['epps'] ?? []), true))>
                    {{ data_get($epp, 'nombre', 'Sin EPP') }}
                </option>
            @endforeach
        </select>
    </label>

    <label>
        <span>Estado</span>
        <select name="epp_estado[]">
            <option value="">Todos los estados</option>
            @foreach($asCollection(data_get($options ?? [], 'estados', [])) as $value => $label)
                <option value="{{ $value }}" @selected(in_array((string) $value, (array) ($filters['epp_estado'] ?? []), true))>{{ $label }}</option>
            @endforeach
        </select>
    </label>

    <label>
        <span>Ficha</span>
        <select name="ficha">
            @foreach($asCollection(data_get($options ?? [], 'fichas', [])) as $value => $label)
                <option value="{{ $value }}" @selected((string) ($filters['ficha'] ?? '') === (string) $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>

    <label>
        <span>Talla</span>
        <select name="talla_estado">
            @foreach($asCollection(data_get($options ?? [], 'talla_estados', [])) as $value => $label)
                <option value="{{ $value }}" @selected((string) ($filters['talla_estado'] ?? '') === (string) $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>

    <label>
        <span>Fecha desde</span>
        <input type="date" name="fecha_desde" value="{{ $filters['fecha_desde'] ?? '' }}">
    </label>

    <label>
        <span>Fecha hasta</span>
        <input type="date" name="fecha_hasta" value="{{ $filters['fecha_hasta'] ?? '' }}">
    </label>

    <div class="lgtx-filter-actions">
        <button type="submit">Actualizar</button>
        <a href="{{ url('/logistica?tab=dashboard') }}">Limpiar</a>
    </div>
</form>

<div class="lgtx-chips" aria-label="Filtros activos">
    @forelse($filterChips as $chip)
        <span><b>{{ data_get($chip, 'label') }}:</b> {{ data_get($chip, 'value') }}</span>
    @empty
        <span><b>Filtros:</b> Todos los datos disponibles</span>
    @endforelse
</div>

<section class="lgtx-kpis" aria-label="Indicadores principales">
    <article class="tone-info"><span>Trabajadores en alcance</span><strong>{{ number_format((float) data_get($metrics, 'workers', 0)) }}</strong><small>Total segun filtros</small></article>
    <article class="tone-ok"><span>Trabajadores habilitados</span><strong>{{ number_format((float) data_get($metrics, 'habilitados', 0)) }} / {{ number_format((float) data_get($metrics, 'workers', 0)) }}</strong><small>{{ $percent(data_get($metrics, 'habilitados_pct', 0)) }}</small></article>
    <article class="tone-info"><span>Cobertura EPP</span><strong>{{ $percent(data_get($metrics, 'coverage_pct', 0)) }}</strong><small>{{ number_format((float) data_get($metrics, 'delivered_epp', 0)) }} de {{ number_format((float) data_get($metrics, 'required_epp', 0)) }}</small></article>
    <article class="tone-warning"><span>EPP pendientes de cobertura</span><strong>{{ number_format((float) data_get($metrics, 'pending_epp', 0)) }}</strong><small>Items requeridos sin entrega vigente</small></article>
    <article class="tone-danger"><span>Fichas incompletas por tallas</span><strong>{{ number_format((float) data_get($metrics, 'fichas_incompletas_tallas', 0)) }}</strong><small>Zapatos, camisa, pantalon o respirador</small></article>
    <article class="tone-warning"><span>Proximos vencimientos</span><strong>{{ number_format((float) data_get($metrics, 'expiring_epp', 0)) }}</strong><small>Dentro de 30 dias</small></article>
    <article class="tone-danger"><span>EPP vencidos</span><strong>{{ number_format((float) data_get($metrics, 'expired_epp', 0)) }}</strong><small>Requieren cambio o revision</small></article>
</section>

<div class="lgtx-grid">
    <section class="lgtx-card">
        <header><h2>Trabajadores habilitados por mina</h2><p>Habilitados, no habilitados y porcentaje por unidad.</p></header>
        <div class="lgtx-stack-list">
            @forelse($mineSummary as $row)
                @php
                    $total = max(1, (int) data_get($row, 'total', 0));
                    $enabled = (int) data_get($row, 'habilitados', 0);
                    $disabled = max(0, $total - $enabled);
                    $pct = round(($enabled / $total) * 100, 1);
                @endphp
                <div class="lgtx-stack-row">
                    <div><strong>{{ data_get($row, 'label', 'Sin mina') }}</strong><small>{{ $enabled }} habilitados / {{ $total }} total - {{ $pct }}%</small></div>
                    <div class="lgtx-stack-bar">
                        <i class="ok" style="width: {{ $barPct($enabled, $total) }}%"></i>
                        <i class="danger" style="width: {{ $barPct($disabled, $total) }}%"></i>
                    </div>
                </div>
            @empty
                <div class="lgtx-empty">No hay datos disponibles segun los filtros aplicados.</div>
            @endforelse
        </div>
    </section>

    <section class="lgtx-card">
        <header><h2>Cobertura de EPP por item</h2><p>Obligatorios: casco, chaleco, zapatos, camisa, pantalon y respirador.</p></header>
        <div class="lgtx-stack-list">
            @foreach($coverageByItem as $row)
                @php $required = max(1, (int) data_get($row, 'requerido', 0)); @endphp
                <div class="lgtx-stack-row">
                    <div><strong>{{ data_get($row, 'nombre') }}</strong><small>Req. {{ data_get($row, 'requerido') }} - Ent. {{ data_get($row, 'entregado') }} - Pend. {{ data_get($row, 'pendiente') }} - {{ $percent(data_get($row, 'coverage_pct')) }}</small></div>
                    <div class="lgtx-stack-bar">
                        <i class="ok" style="width: {{ $barPct(data_get($row, 'segments.entregado', 0), $required) }}%"></i>
                        <i class="warning" style="width: {{ $barPct(data_get($row, 'segments.por_vencer', 0), $required) }}%"></i>
                        <i class="danger" style="width: {{ $barPct(data_get($row, 'segments.vencido', 0), $required) }}%"></i>
                        <i class="muted" style="width: {{ $barPct(data_get($row, 'segments.no_aplica', 0), $required) }}%"></i>
                        <i class="info" style="width: {{ $barPct(data_get($row, 'segments.pendiente', 0), $required) }}%"></i>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>

<section class="lgtx-card">
    <header><h2>Heatmap de faltantes por mina e item</h2><p>Cantidad de faltantes por unidad y EPP obligatorio.</p></header>
    <div class="lgtx-heatmap">
        <div class="lgtx-heat-head"><span>Mina</span>@foreach(['casco','chaleco','zapatos','camisa','pantalon','respirador'] as $key)<span>{{ data_get(['casco'=>'Casco','chaleco'=>'Chaleco','zapatos'=>'Zapatos','camisa'=>'Camisa','pantalon'=>'Pantalon','respirador'=>'Respirador'], $key) }}</span>@endforeach</div>
        @forelse($missingHeatmap as $row)
            <div class="lgtx-heat-row">
                <strong>{{ data_get($row, 'mina') }}</strong>
                @foreach(data_get($row, 'cells', []) as $cell)
                    <span class="heat-{{ data_get($cell, 'tone') }}">{{ data_get($cell, 'value') }}</span>
                @endforeach
            </div>
        @empty
            <div class="lgtx-empty">No hay datos disponibles segun los filtros aplicados.</div>
        @endforelse
    </div>
</section>

<section class="lgtx-card">
    <header><h2>Tallas del personal</h2><p>Distribucion de tallas registradas y trabajadores con ficha incompleta.</p></header>
    <div class="lgtx-mini-kpis">
        <article><span>Sin talla de zapatos</span><strong>{{ number_format($sizeMissingCounts->where('zapatos', true)->count()) }}</strong></article>
        <article><span>Sin talla de camisa</span><strong>{{ number_format($sizeMissingCounts->where('camisa', true)->count()) }}</strong></article>
        <article><span>Sin talla de pantalon</span><strong>{{ number_format($sizeMissingCounts->where('pantalon', true)->count()) }}</strong></article>
        <article><span>Sin talla de respirador</span><strong>{{ number_format($sizeMissingCounts->where('respirador', true)->count()) }}</strong></article>
        <article><span>Fichas incompletas</span><strong>{{ number_format($sizeMissingCounts->count()) }}</strong></article>
    </div>
    <div class="lgtx-size-grid">
        @foreach($sizeSummary as $group)
            @php $maxSize = max(1, (int) collect(data_get($group, 'tallas', []))->max('total')); @endphp
            <div class="lgtx-size-chart">
                <h3>Distribucion de {{ data_get($group, 'tipo') }}</h3>
                @foreach(data_get($group, 'tallas', []) as $size)
                    <div class="lgtx-size-row">
                        <span>{{ data_get($size, 'talla') }}</span>
                        <div><i class="{{ data_get($size, 'tone') }}" style="width: {{ $barPct(data_get($size, 'total', 0), $maxSize) }}%"></i></div>
                        <b>{{ data_get($size, 'total') }}</b>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</section>

<div class="lgtx-grid">
    <section class="lgtx-card">
        <header><h2>Stock y requerimiento de compra</h2><p>Requerido, stock disponible y pendiente de compra por item y talla.</p></header>
        <div class="lgtx-table-wrap"><table class="lgtx-table"><thead><tr><th>Item</th><th>Talla</th><th>Requerido</th><th>Stock disponible</th><th>Pendiente de compra</th><th>Estado</th></tr></thead><tbody>
            @forelse($stockRows->take(18) as $row)
                <tr><td>{{ data_get($row, 'item') }}</td><td>{{ data_get($row, 'talla') }}</td><td>{{ data_get($row, 'requerido') }}</td><td>{{ data_get($row, 'stock') }}</td><td>{{ data_get($row, 'pendiente_compra') }}</td><td><span class="lgtx-badge {{ $stockTone(data_get($row, 'estado', '')) }}">{{ data_get($row, 'estado') }}</span></td></tr>
            @empty
                <tr><td colspan="6">No hay stock registrado para este filtro.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>

    <section class="lgtx-card">
        <header><h2>Acciones urgentes</h2><p>Prioridades automaticas segun el tablero actual.</p></header>
        <ol class="lgtx-actions">
            @forelse($urgentActions as $action)
                <li>{{ $action }}</li>
            @empty
                <li>No hay acciones urgentes segun los filtros aplicados.</li>
            @endforelse
        </ol>
    </section>
</div>

<section class="lgtx-card">
    <header><h2>Proximos vencimientos</h2><p>EPP vencidos o por vencer dentro de los proximos 30 dias.</p></header>
    <div class="lgtx-mini-kpis">
        <article><span>Vencidos</span><strong>{{ number_format(data_get($metrics, 'expired_epp', 0)) }}</strong></article>
        <article><span>Vencen en 7 dias</span><strong>{{ number_format(data_get($metrics, 'expiring_7', 0)) }}</strong></article>
        <article><span>Vencen en 15 dias</span><strong>{{ number_format(data_get($metrics, 'expiring_15', 0)) }}</strong></article>
        <article><span>Vencen en 30 dias</span><strong>{{ number_format(data_get($metrics, 'expiring_30', 0)) }}</strong></article>
    </div>
    <div class="lgtx-table-wrap"><table class="lgtx-table"><thead><tr><th>Trabajador</th><th>DNI</th><th>Mina</th><th>EPP</th><th>Fecha entrega</th><th>Fecha vencimiento</th><th>Dias restantes</th><th>Dias efectivos usados</th><th>Estado</th></tr></thead><tbody>
        @forelse($expiringDeliveries as $row)
            <tr><td>{{ data_get($row, 'trabajador') }}</td><td>{{ data_get($row, 'documento') }}</td><td>{{ data_get($row, 'mina') }}</td><td>{{ data_get($row, 'epp') }}</td><td>{{ data_get($row, 'fecha_entrega') }}</td><td>{{ data_get($row, 'fecha_vencimiento') }}</td><td>{{ data_get($row, 'dias') }}</td><td>{{ data_get($row, 'uso_efectivo', '0 / 0 dias') }}</td><td>{{ data_get($row, 'estado_visual') }}</td></tr>
        @empty
            <tr><td colspan="9">No hay EPP vencidos ni proximos a vencer segun los filtros aplicados.</td></tr>
        @endforelse
    </tbody></table></div>
</section>

<section class="lgtx-card">
    <header><h2>Entregas y cambios recientes</h2><p>Ultimos movimientos de EPP.</p></header>
    <div class="lgtx-table-wrap"><table class="lgtx-table"><thead><tr><th>Fecha</th><th>Trabajador</th><th>Mina</th><th>EPP</th><th>Talla</th><th>Tipo de movimiento</th><th>Responsable</th></tr></thead><tbody>
        @forelse($recentDeliveries as $row)
            <tr><td>{{ data_get($row, 'fecha_entrega') }}</td><td>{{ data_get($row, 'trabajador') }}</td><td>{{ data_get($row, 'mina') }}</td><td>{{ data_get($row, 'epp') }}</td><td>{{ data_get($row, 'talla') }}</td><td>{{ data_get($row, 'tipo_movimiento') }}</td><td>{{ data_get($row, 'responsable') }}</td></tr>
        @empty
            <tr><td colspan="7">No hay movimientos recientes segun los filtros aplicados.</td></tr>
        @endforelse
    </tbody></table></div>
</section>
