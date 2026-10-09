<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>body{font-family:Helvetica,sans-serif;font-size:11px;color:#172536}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbc5cf;padding:7px;text-align:left}.signature{width:240px;height:120px}.qr{width:140px}.warning{padding:12px;border:2px solid #943b24}</style></head><body>
<h1>Constancia EPP — {{ $operation->tipo }}</h1>
<p>Operacion: {{ $operation->id }}</p>
<p>{{ data_get($payload, 'worker.name') }} — Documento: {{ data_get($payload, 'worker.document') }}</p>
<p>Almacen: {{ data_get($payload, 'warehouse.name') }}</p>
<p>Estado evidencia: {{ $verification['valid'] ? 'FIRMADO_VALIDO' : 'EVIDENCIA_INVALIDA' }}</p>
@unless($verification['valid'])<p class="warning">Evidencia invalida. Solicita revision de la firma.</p>@endunless
<table><thead><tr><th>Articulo revisado</th><th>Solicitado</th><th>Procesado</th><th>Pendiente</th></tr></thead><tbody>
@foreach(data_get($payload, 'details', []) as $detail)<tr><td>{{ data_get($detail, 'snapshot.real.nombre', $detail['epp_name'] ?? 'EPP') }} {{ $detail['size'] ?? '' }} {{ $detail['color'] ?? '' }}</td><td>{{ $detail['requested_quantity'] ?? 0 }}</td><td>{{ $detail['processed_quantity'] ?? 0 }}</td><td>{{ $detail['pending_quantity'] ?? 0 }}</td></tr>@endforeach
@foreach(data_get($payload, 'pending', []) as $detail)@if(($detail['processed_quantity'] ?? 0) === 0)<tr><td>{{ data_get($detail, 'snapshot.requested.nombre', 'EPP pendiente') }}</td><td>{{ $detail['requested_quantity'] ?? 0 }}</td><td>0</td><td>{{ $detail['pending_quantity'] ?? 0 }}</td></tr>@endif @endforeach
</tbody></table>
<p>{{ $acceptance->declaracion_version === 'RETURN_V1' ? 'Confirmo la devolucion de las unidades y condiciones revisadas.' : 'Confirmo haber recibido unicamente los articulos mostrados como procesados. Las unidades pendientes no han sido entregadas.' }}</p>
<p>Declaracion: {{ $acceptance->declaracion_version }}. Firmante: {{ $acceptance->firmante_nombre }} ({{ $acceptance->tipo_firmante }}).</p>
<p>Firma: {{ $acceptance->firmado_at }} {{ $acceptance->zona_horaria }}</p>
<h3>{{ $acceptance->tipo_firmante === 'TRABAJADOR' ? 'Firma del trabajador' : 'Firma del testigo autorizado' }}</h3>
@if($signature && $verification['valid'])<img class="signature" src="{{ $signature }}" alt="Firma">@else<p>Firma no disponible como evidencia valida.</p>@endif
@if($acceptance->motivo_excepcion)<p>Motivo de excepcion: {{ $acceptance->motivo_excepcion }}</p>@endif
@if($operation->operacion_origen_id)<p>Operacion original: {{ $operation->operacion_origen_id }}. Motivo: {{ $operation->motivo }}</p>@endif
@foreach($operation->operacionesCompensatorias as $correction)<p>{{ $correction->tipo }}: {{ $correction->id }} — {{ $correction->motivo }}</p>@endforeach
<p>Codigo verificacion: {{ $acceptance->codigo_verificacion }}</p>
<small>Contenido SHA-256: {{ substr($acceptance->contenido_sha256, 0, 16) }} · Firma SHA-256: {{ substr($acceptance->firma_sha256, 0, 16) }}</small>
<p><img class="qr" src="data:image/svg+xml;base64,{{ base64_encode($qrSvg) }}" alt="QR de verificacion interna"></p>
<p>La verificacion del servidor es autoritativa. El acceso requiere sesion y permisos.</p>
</body></html>
