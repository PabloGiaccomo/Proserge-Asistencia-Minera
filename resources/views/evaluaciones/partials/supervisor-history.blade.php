@if($items->isEmpty())
    <div class="eval-empty">No hay evaluaciones de supervisores para estos filtros.</div>
@else
    <div class="eval-table-wrap"><table class="eval-table">
        <thead><tr><th>Fecha</th><th>Supervisor</th><th>Mina</th><th>Evaluador</th><th>Resultado</th><th>Estado / seguimiento</th></tr></thead><tbody>
        @foreach($items as $item)
            <tr>
                <td>{{ $item->fecha?->format('d/m/Y') }}</td>
                <td><strong>{{ $item->evaluado?->nombre_completo ?? '-' }}</strong><small>{{ $item->evaluado?->dni }} · {{ $item->evaluado?->puesto }}</small></td>
                <td>{{ $item->mina?->nombre ?? 'Sin mina vinculada' }}@if($item->source_mina)<small>Origen: {{ $item->source_mina }}</small>@endif</td>
                <td>{{ $item->evaluador?->nombre_completo ?? $item->source_evaluador ?? $item->usuarioEvaluador?->email ?? 'Evaluador histórico sin cuenta' }}</td>
                <td><span class="eval-score-badge">{{ number_format((float) $item->resultado_final, 1) }}%</span>
                    @if($item->calculo_version === 'HISTORICA')<small>Cálculo histórico</small>@elseif($item->calculo_version === 'REGULARIZADA')<small>Regularizada · fórmula actual</small><small>Nota original: {{ $item->source_payload[31] ?? '-' }}%</small>@endif
                </td>
                <td>
                    @if($item->estado === \App\Models\EvaluacionSupervisor::PENDING_REGULARIZATION)
                        <strong>Pendiente de regularización</strong><small>Faltan: {{ implode(', ', $item->missingCriteria()) }}</small><small>Fuera de indicadores.</small>
                        @if($canRegularizeSupervisor ?? false)<a class="btn btn-outline" href="{{ route('evaluaciones.index', ['tipo' => 'supervisores', 'regularizar_supervisor' => $item->id]) }}">Regularizar</a>@endif
                    @else
                        {{ $item->calculo_version === 'REGULARIZADA' ? 'Regularizada' : $item->estado }}
                    @endif
                    @foreach($item->regularizationEvents() as $event)
                        <small>{{ \Illuminate\Support\Carbon::parse($event['fecha'])->format('d/m/Y H:i') }} · {{ $event['actor'] ?? $event['usuario_id'] }} · {{ $event['accion'] === 'PENDIENTE' ? $event['motivo'] : 'Regularización completada' }}</small>
                    @endforeach
                </td>
            </tr>
        @endforeach
        </tbody></table></div>
    @if($items instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="eval-actions">
            <span>{{ $items->total() }} evaluaciones · Página {{ $items->currentPage() }} de {{ $items->lastPage() }}</span>
            @if($items->previousPageUrl())<a class="btn btn-outline" href="{{ $items->previousPageUrl() }}">Anterior</a>@endif
            @if($items->nextPageUrl())<a class="btn btn-outline" href="{{ $items->nextPageUrl() }}">Siguiente</a>@endif
        </div>
    @endif
@endif
