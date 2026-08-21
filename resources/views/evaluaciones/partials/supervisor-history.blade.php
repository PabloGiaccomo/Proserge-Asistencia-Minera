@if($items->isEmpty())
    <div class="eval-empty">No hay evaluaciones de supervisores registradas.</div>
@else
    <div class="eval-table-wrap"><table class="eval-table"><thead><tr><th>Fecha</th><th>Supervisor</th><th>Mina</th><th>Evaluador</th><th>Resultado</th><th>Estado</th></tr></thead><tbody>
    @foreach($items as $item)<tr><td>{{ $item->fecha?->format('d/m/Y') }}</td><td><strong>{{ $item->evaluado?->nombre_completo ?? '-' }}</strong><small>{{ $item->evaluado?->puesto }}</small></td><td>{{ $item->mina?->nombre ?? '-' }}</td><td>{{ $item->evaluador?->nombre_completo ?? '-' }}</td><td><span class="eval-score-badge">{{ number_format((float) $item->resultado_final, 1) }}%</span></td><td>{{ $item->estado }}</td></tr>@endforeach
    </tbody></table></div>
@endif
