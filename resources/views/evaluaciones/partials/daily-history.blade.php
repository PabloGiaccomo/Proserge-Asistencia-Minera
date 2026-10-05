@if($items->isEmpty())
    <div class="eval-empty">Aún no hay evaluaciones diarias registradas.</div>
@else
    <div class="eval-table-wrap"><table class="eval-table"><thead><tr><th>Fecha</th><th>Evaluado</th><th>Mina</th><th>Evaluador</th><th>Total</th><th>Incidencia</th></tr></thead><tbody>
    @foreach($items as $item)<tr><td>{{ $item->fecha?->format('d/m/Y') }}@if($item->origen === 'HISTORICA')<small>Carga histórica</small>@elseif($item->origen === 'DIRECTA')<small>Evaluación directa</small>@endif</td><td><strong>{{ $item->trabajador?->nombre_completo ?? '-' }}</strong><small>{{ $item->trabajador?->puesto }}</small></td><td>{{ $item->mina?->nombre ?? ($item->origen === 'HISTORICA' ? 'Sin mina vinculada' : '-') }}@if($item->origen === 'HISTORICA' && $item->source_mina)<small>Origen: {{ $item->source_mina }}</small>@endif</td><td>{{ $item->evaluador?->nombre_completo ?? $item->source_evaluador ?? '-' }}</td><td><span class="eval-score-badge">{{ $item->total }} / 20</span></td><td>{{ $item->tuvo_incidencia ? 'Sí' : 'No' }}</td></tr>@endforeach
    </tbody></table></div>
@endif
