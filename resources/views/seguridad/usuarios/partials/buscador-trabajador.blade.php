@php
    $search = $search ?? '';
    $trabajadores = $trabajadores ?? [];
    $isBatch = ($modo ?? 'individual') === 'lote';
    $inputId = $isBatch ? 'usuario-worker-search-batch' : 'usuario-worker-search';
@endphp
<div data-user-worker-search data-mode="{{ $isBatch ? 'lote' : 'individual' }}"
    data-search-url="{{ route('usuarios.buscar-trabajadores') }}">
    @unless($isBatch)
        <form method="GET" action="{{ route('usuarios.create') }}" data-user-worker-search-form>
    @endunless
    <div class="form-group">
        <label class="form-label" for="{{ $inputId }}">Buscar por nombre, DNI o puesto</label>
        <input id="{{ $inputId }}" type="search" @unless($isBatch) name="search" @endunless
            class="form-control" value="{{ $search }}" maxlength="191" autocomplete="off"
            aria-controls="{{ $inputId }}-results" aria-describedby="{{ $inputId }}-status"
            data-user-worker-search-input>
    </div>
    @unless($isBatch)
            <noscript><button type="submit" class="btn btn-outline">Buscar trabajador</button></noscript>
        </form>
    @endunless

    <p id="{{ $inputId }}-status" role="status" data-user-worker-search-status
        style="color: var(--color-text-secondary); font-size: 13px; margin-bottom: 12px;">
        @if(mb_strlen(trim($search)) < 2)
            Escribe al menos 2 caracteres.
        @elseif(count($trabajadores) === 0)
            No se encontraron trabajadores.
        @else
            {{ count($trabajadores) }} {{ count($trabajadores) === 1 ? 'resultado' : 'resultados' }}.
        @endif
    </p>

    <div id="{{ $inputId }}-results" data-user-worker-search-results style="display: grid; gap: 12px;">
        @foreach($trabajadores as $trabajador)
            <div class="card">
                <div class="card-body" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                    <div style="min-width: 0; overflow-wrap: anywhere;">
                        <strong>{{ $trabajador['nombre'] }}</strong><br>
                        <span style="color: var(--color-text-secondary); font-size: 13px;">
                            DNI: {{ $trabajador['dni'] }} · {{ $trabajador['puesto'] ?: 'Sin puesto' }}
                        </span>
                    </div>
                    @if($trabajador['tiene_usuario'])
                        <span class="badge badge-danger">Ya tiene usuario</span>
                    @elseif($isBatch)
                        <button type="button" class="btn btn-sm btn-primary" data-user-worker-pick
                            data-worker="{{ json_encode($trabajador, JSON_THROW_ON_ERROR) }}"
                            aria-label="Agregar a {{ $trabajador['nombre'] }}">Agregar</button>
                    @else
                        <a href="{{ $trabajador['seleccionar_url'] }}" class="btn btn-sm btn-primary"
                            aria-label="Seleccionar a {{ $trabajador['nombre'] }}">Seleccionar</a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
