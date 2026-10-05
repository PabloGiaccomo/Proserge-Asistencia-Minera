<section class="ed-specific" data-detail-type="{{ $detail['type'] }}">
    <header><h2>Analisis detallado · {{ $dashboard['types'][$detail['type']] }}</h2><p>{{ $detail['total'] }} evaluaciones dentro de los filtros seleccionados</p></header>
    <div class="ed-kpis">
        <div><span>Mediana del resultado</span><strong>{{ $detail['median'] ?? 'Sin datos' }}</strong><small>Escala de {{ $detail['max'] }} puntos</small></div>
        <div><span>Minimo / maximo observado</span><strong>{{ $detail['min'] ?? '-' }} / {{ $detail['maxObserved'] ?? '-' }}</strong><small>{{ $detail['n'] }} puntajes validos</small></div>
        <div><span>Puntajes faltantes o fuera de escala</span><strong>{{ $detail['total'] - $detail['n'] }}</strong></div>
        @foreach($detail['extra'] as $extra)<div><span>{{ $extra['label'] }}</span><strong>{{ $extra['value'] }}</strong><small>{{ $extra['note'] }}</small></div>@endforeach
    </div>
    @if(!$detail['total'])<p class="ed-empty">Sin evaluaciones para el analisis de este tipo.</p>@endif
    <div class="ed-charts">
        @foreach($detail['charts'] as $chart)
        <section class="ed-metrics">
            @if($chart['collapsed'] ?? false)<details><summary>{{ $chart['title'] }}</summary>@else<h2>{{ $chart['title'] }}</h2>@endif
            @foreach($chart['items'] as $metric)
                <div class="ed-metric"><div><span>{{ $metric['label'] }}</span><strong>{{ $metric['n'] ? $metric['value'].' / '.$metric['max'] : 'Sin datos' }}</strong></div>
                    <div class="ed-track" role="img" aria-label="{{ $metric['label'] }}: {{ $metric['n'] ? $metric['value'].' de '.$metric['max'] : 'sin datos' }}"><span style="width:{{ $metric['percent'] }}%;background:#43877b"></span></div>
                    <small>{{ $metric['n'] }} registros con dato · {{ $metric['missing'] }} sin dato valido</small>
                </div>
            @endforeach
            @if($chart['collapsed'] ?? false)</details>@endif
        </section>
        @endforeach
        <section><h2>Distribucion de puntajes · sobre {{ $detail['max'] }}</h2>
            @foreach($detail['bands'] as $band)<div class="ed-bar-row"><span>{{ $band['label'] }}</span><div class="ed-track"><span style="width:{{ $detail['n'] ? $band['count'] / $detail['n'] * 100 : 0 }}%;background:#4e79bc"></span></div><strong>{{ $band['count'] }}</strong></div>@endforeach
            <p class="ed-method">Frecuencia por intervalos numericos. No son niveles de aprobacion o desempeno.</p>
        </section>
        <section><h2>Evolucion del puntaje promedio</h2><div class="ed-trend"><table><thead><tr><th>Mes</th><th>Promedio / {{ $detail['max'] }}</th><th>Evaluaciones con puntaje</th></tr></thead><tbody>
            @forelse($detail['trend'] as $month)<tr><td>{{ $month['month'] }}</td><td>{{ $month['average'] ?? 'Sin datos' }}<div class="ed-track"><span style="width:{{ ($month['average'] ?? 0) / $detail['max'] * 100 }}%;background:#af6b23"></span></div></td><td>{{ $month['n'] }}</td></tr>@empty<tr><td colspan="3">Sin datos en el periodo.</td></tr>@endforelse
        </tbody></table></div></section>
    </div>
    <p class="ed-method">Promedios por evaluacion, no por persona. Cada criterio usa sus registros validos; una muestra pequena o incompleta limita la comparacion. Los valores faltantes no se convierten en cero, excepto respuestas vacias en el aporte ponderado de supervisores, segun su formulario.</p>
</section>
