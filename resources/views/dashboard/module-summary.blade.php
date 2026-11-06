@extends('layouts.app')

@section('title', 'Dashboard de '.$definition['title'].' - Proserge')

@section('content')
<div class="module-dashboard">
    <header class="module-dashboard-heading">
        <div>
            <p class="module-dashboard-eyebrow">Resumen operativo</p>
            <h1>{{ $definition['title'] }}</h1>
            <p>{{ $definition['description'] }}</p>
        </div>
        @if($definition['route'])
            @allowed($module, 'ver')
                <a class="btn btn-primary" href="{{ route($definition['route']) }}">Abrir modulo</a>
            @endallowed
        @endif
    </header>

    @if($hasPeriod)
        <form class="module-dashboard-filters" method="GET" action="{{ route('inicio.dashboard.'.$module) }}" aria-label="Filtros del dashboard">
            <div class="form-group">
                <label for="dashboard-from">Desde</label>
                <input class="form-control" id="dashboard-from" type="date" name="fecha_desde" value="{{ old('fecha_desde', $filters['fecha_desde']) }}" required>
            </div>
            <div class="form-group">
                <label for="dashboard-to">Hasta</label>
                <input class="form-control" id="dashboard-to" type="date" name="fecha_hasta" value="{{ old('fecha_hasta', $filters['fecha_hasta']) }}" required>
            </div>
            <button class="btn btn-primary" type="submit">Aplicar filtros</button>
            <a class="btn btn-outline" href="{{ route('inicio.dashboard.'.$module) }}">Restablecer</a>
        </form>
    @endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif
    @if(!empty($dashboard['note']))
        <p class="module-dashboard-note">{{ $dashboard['note'] }}</p>
    @endif

    <section class="module-dashboard-metrics" aria-label="Indicadores">
        @foreach($dashboard['metrics'] as $metric)
            <article class="card module-dashboard-metric">
                <span>{{ $metric['label'] }}</span>
                <strong>{{ is_numeric($metric['value']) ? number_format($metric['value'], 0, ',', '.') : $metric['value'] }}</strong>
            </article>
        @endforeach
    </section>

    <div class="module-dashboard-sections">
        @foreach($dashboard['sections'] as $section)
            <section class="card">
                <div class="card-header"><h2 class="card-title">{{ $section['title'] }}</h2></div>
                <div class="card-body">
                    @forelse($section['rows'] as $row)
                        <div class="module-dashboard-row">
                            @if(!empty($row['url']))
                                <a href="{{ $row['url'] }}">{{ $row['label'] }}</a>
                            @else
                                <span>{{ $row['label'] }}</span>
                            @endif
                            <strong>{{ $row['value'] }}</strong>
                        </div>
                    @empty
                        <p class="module-dashboard-empty">No hay registros dentro de tu alcance{{ $hasPeriod ? ' para este periodo' : '' }}.</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</div>
@endsection
