@extends('layouts.app')

@section('title', 'Link generado - Proserge')

@section('content')
<div class="module-page ficha-workspace">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">{{ isset($batchResult) ? 'Temporales y links generados' : 'Link temporal generado' }}</h1>
                <p class="page-subtitle">Copia los enlaces y envialos manualmente por WhatsApp a cada trabajador.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.fichas.temporales') }}" class="btn btn-outline">Ver temporales</a>
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver a Personal</a>
                @isset($ficha)
                    <a href="{{ route('personal.fichas.review', $ficha->id) }}" class="btn btn-primary">Ver ficha</a>
                @endisset
            </div>
        </div>
    </div>

    @isset($batchResult)
        <div class="ficha-card">
            <div class="ficha-card-header">
                <div>
                    <h2 class="ficha-card-title">{{ $batchResult['created_count'] }} links generados</h2>
                    <p class="ficha-card-subtitle">{{ $batchResult['skipped_count'] }} registros omitidos por duplicados o validacion.</p>
                </div>
                <span class="ficha-status ficha-status-sent">Lote procesado</span>
            </div>
            <div class="ficha-card-body">
                @if(count($warnings ?? []) > 0)
                    <div class="ficha-alert ficha-alert-warning" style="margin-bottom:12px;">
                        <strong>Advertencias:</strong> {{ implode(' ', $warnings) }}
                    </div>
                @endif

                @if(count($skipped ?? []) > 0)
                    <div class="ficha-alert ficha-alert-warning">
                        @foreach($skipped as $skip)
                            <div>Fila {{ $skip['row_number'] }}: {{ $skip['message'] }}</div>
                        @endforeach
                    </div>
                @endif

                @if(count($results ?? []) > 0)
                <div class="ficha-batch-table-wrap">
                    <table class="ficha-batch-table">
                        <thead>
                            <tr>
                                <th>Fila</th>
                                <th>Trabajador</th>
                                <th>Documento</th>
                                <th>Vence</th>
                                <th>Link</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($results as $index => $item)
                                <tr>
                                    <td>{{ $item['row_number'] }}</td>
                                    <td>{{ $item['personal']->nombre_completo }}</td>
                                    <td>{{ $item['personal']->tipo_documento ?? 'DNI' }} {{ $item['personal']->numero_documento ?? $item['personal']->dni }}</td>
                                    <td>{{ optional($item['link']->expires_at)->format('d/m/Y H:i') }}</td>
                                    <td>
                                        <div class="ficha-link-box">
                                            <input id="fichaLink{{ $index }}" class="ficha-input" type="text" value="{{ $item['url'] }}" readonly>
                                            <button type="button" class="btn btn-primary js-copy-ficha-link" data-target="fichaLink{{ $index }}">Copiar</button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                    <div class="ficha-alert ficha-alert-danger">No se genero ningun link. Revisa los mensajes de validacion y vuelve a cargar la macro corregida.</div>
                @endif
            </div>
        </div>
    @else
    <div class="ficha-card">
        <div class="ficha-card-header">
            <div>
                <h2 class="ficha-card-title">{{ $trabajador->nombre_completo }}</h2>
                <p class="ficha-card-subtitle">{{ $trabajador->tipo_documento ?? 'DNI' }} {{ $trabajador->numero_documento ?? $trabajador->dni }}</p>
            </div>
            <span class="ficha-status ficha-status-pending">Vence: {{ optional($result['link']->expires_at)->format('d/m/Y H:i') }}</span>
        </div>
        <div class="ficha-card-body">
            <div class="ficha-link-box">
                <input id="fichaLink" class="ficha-input" type="text" value="{{ $url }}" readonly>
                <button type="button" class="btn btn-primary" id="copyFichaLink">Copiar link</button>
            </div>
            <p class="ficha-card-subtitle" style="margin-top:12px;">
                El trabajador podra editar la ficha hasta enviarla o hasta que venza el link. Luego de enviar, quedara en solo lectura por 24 horas.
            </p>
        </div>
    </div>
    @endisset
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-copy-ficha-link').forEach(function (button) {
        button.addEventListener('click', async function () {
            const input = document.getElementById(button.dataset.target);
            if (!input) return;
            input.select();
            input.setSelectionRange(0, 99999);
            try {
                await navigator.clipboard.writeText(input.value);
                button.textContent = 'Copiado';
                setTimeout(() => button.textContent = 'Copiar', 1800);
            } catch (error) {
                document.execCommand('copy');
            }
        });
    });

    const button = document.getElementById('copyFichaLink');
    const input = document.getElementById('fichaLink');
    if (!button || !input) return;

    button.addEventListener('click', async function () {
        input.select();
        input.setSelectionRange(0, 99999);
        try {
            await navigator.clipboard.writeText(input.value);
            button.textContent = 'Copiado';
            setTimeout(() => button.textContent = 'Copiar link', 1800);
        } catch (error) {
            document.execCommand('copy');
        }
    });
});
</script>
@endpush
