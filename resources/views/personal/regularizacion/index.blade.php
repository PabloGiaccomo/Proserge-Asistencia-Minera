@extends('layouts.app')

@section('title', 'Regularización de personal - Proserge')

@section('content')
<div class="module-page regularization-page" id="regularizationPage">
    <header class="regularization-header">
        <div>
            <a href="{{ route('personal.index') }}" class="regularization-back">Volver a Personal</a>
            <h1>Regularización de personal</h1>
            <p>Control de fichas, documentos y trabajadores cesados.</p>
        </div>
        <button type="button" class="btn btn-outline regularization-refresh" id="regularizationRefresh">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/>
                <path d="M3 21v-5h5"/>
                <path d="M3 12A9 9 0 0 1 18.5 5.8L21 8"/>
                <path d="M21 3v5h-5"/>
            </svg>
            <span>Actualizar</span>
        </button>
    </header>

    <nav class="regularization-tabs" aria-label="Secciones de regularización">
        <a href="{{ route('personal.regularizacion.index', ['section' => 'pending']) }}" class="{{ $dashboard['section'] === 'pending' ? 'is-active' : '' }}">
            Pendientes
            <span>{{ $dashboard['metrics']['pending_workers'] }}</span>
        </a>
        <a href="{{ route('personal.regularizacion.index', ['section' => 'ceased']) }}" class="{{ $dashboard['section'] === 'ceased' ? 'is-active' : '' }}">
            Cesados
            <span>{{ $dashboard['metrics']['ceased_workers'] }}</span>
        </a>
    </nav>

    <form method="GET" action="{{ route('personal.regularizacion.index') }}" class="regularization-filters" id="regularizationFilters">
        <input type="hidden" name="section" value="{{ $dashboard['section'] }}">
        <label class="regularization-search">
            <span>Buscar trabajador</span>
            <span class="regularization-input-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
                </svg>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Nombre, documento o puesto">
            </span>
        </label>
        @if($dashboard['section'] === 'pending')
            <label>
                <span>Tipo de pendiente</span>
                <select name="type">
                    <option value="all" @selected(request('type', 'all') === 'all')>Todos</option>
                    <option value="fields" @selected(request('type') === 'fields')>Ficha incompleta</option>
                    <option value="documents" @selected(request('type') === 'documents')>Documentos</option>
                    <option value="contracts" @selected(request('type') === 'contracts')>Contrato firmado</option>
                </select>
            </label>
        @endif
        <label>
            <span>Mostrar</span>
            <select name="per_page">
                @foreach([10, 25, 50, 100] as $size)
                    <option value="{{ $size }}" @selected((int) request('per_page', 25) === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn btn-primary">Filtrar</button>
        @if(request()->filled('q') || request('type', 'all') !== 'all' || (int) request('per_page', 25) !== 25)
            <a href="{{ route('personal.regularizacion.index', ['section' => $dashboard['section']]) }}" class="btn btn-outline">Limpiar</a>
        @endif
    </form>

    <div class="regularization-live" role="status" aria-live="polite">
        <span class="regularization-live-dot" aria-hidden="true"></span>
        <span id="regularizationStatus">Actualizado a las {{ $dashboard['updated_at']->format('H:i:s') }}</span>
    </div>

    <div id="regularizationResults">
        @include('personal.regularizacion._results', ['dashboard' => $dashboard, 'access' => $access])
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const page = document.getElementById('regularizationPage');
    const results = document.getElementById('regularizationResults');
    const refreshButton = document.getElementById('regularizationRefresh');
    const status = document.getElementById('regularizationStatus');
    if (!page || !results || !refreshButton || !status) return;

    let refreshing = false;
    const refresh = async (force = false) => {
        if (refreshing || document.hidden) return;
        if (!force && (results.querySelector('details[open]') || results.contains(document.activeElement))) return;

        refreshing = true;
        refreshButton.classList.add('is-loading');
        status.textContent = 'Actualizando...';

        try {
            const url = new URL(window.location.href);
            url.searchParams.set('fragment', '1');
            const response = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });
            if (!response.ok) throw new Error('No se pudo actualizar');

            const payload = await response.json();
            results.innerHTML = payload.html;
            status.textContent = `Actualizado a las ${payload.updated_at}`;
        } catch (error) {
            status.textContent = 'No se pudo actualizar. Intenta nuevamente.';
        } finally {
            refreshing = false;
            refreshButton.classList.remove('is-loading');
        }
    };

    refreshButton.addEventListener('click', () => refresh(true));
    window.setInterval(() => refresh(false), 60000);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) refresh(true);
    });
});
</script>
@endpush
