<table><thead><tr><th>Articulo y condiciones revisadas</th><th>Solicitado</th><th>Procesado</th><th>Pendiente</th><th>Estado</th></tr></thead><tbody>
@foreach(['details' => 'PROCESADO', 'pending' => 'PENDIENTE', 'rejected' => 'RECHAZADO'] as $group => $label)
@foreach(data_get($payload, $group, []) as $detail)
@continue($group === 'pending' && ($detail['processed_quantity'] ?? 0) > 0)
<tr><td>
{{ data_get($detail, 'snapshot.real.nombre', data_get($detail, 'snapshot.solicitado.nombre', $detail['epp_name'] ?? 'EPP')) }}
@if(data_get($detail, 'snapshot.solicitado.nombre') && data_get($detail, 'snapshot.real.nombre') !== data_get($detail, 'snapshot.solicitado.nombre'))<br>Solicitado: {{ data_get($detail, 'snapshot.solicitado.nombre') }}@endif
<br>{{ $detail['size'] ?? '' }} {{ $detail['color'] ?? '' }}
@foreach(($detail['attributes'] ?? []) as $key => $value)<br>{{ $key }}: {{ $value }}@endforeach
@if(!empty($detail['return_condition']))<br>Condicion: {{ $detail['return_condition'] }}@endif
@if(!empty($detail['inventory_destination']))<br>Destino: {{ $detail['inventory_destination'] }}@endif
@if(!empty($detail['original_delivery_id']))<br>Entrega origen: {{ $detail['original_delivery_id'] }}@endif
@if(!empty($detail['reason']))<br>Motivo: {{ $detail['reason'] }}@endif
</td><td>{{ $detail['requested_quantity'] ?? 0 }}</td><td>{{ $detail['processed_quantity'] ?? 0 }}</td><td>{{ $detail['pending_quantity'] ?? 0 }}</td><td>{{ $label }}</td></tr>
@endforeach
@endforeach
</tbody></table>
