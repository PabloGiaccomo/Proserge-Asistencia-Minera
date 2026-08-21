@if($items->isEmpty())
    <div class="eval-empty">No hay evaluaciones mensuales de residentes.</div>
@else
    <div class="eval-table-wrap"><table class="eval-table"><thead><tr><th>Periodo</th><th>Residente</th><th>Evaluador</th><th>Resultado</th><th>Estado</th></tr></thead><tbody>
    @foreach($items as $item)<tr><td>{{ ($item->periodo_mes ?? $item->fecha)?->translatedFormat('F Y') }}</td><td><strong>{{ $item->residente?->nombre_completo ?? '-' }}</strong><small>{{ $item->residente?->puesto }}</small></td><td>{{ $item->evaluador?->nombre_completo ?? '-' }}</td><td><span class="eval-score-badge">{{ number_format((float) $item->total, 0) }} / 20</span></td><td>{{ $item->estado ?? 'REGISTRADA' }}</td></tr>@endforeach
    </tbody></table></div>
@endif
