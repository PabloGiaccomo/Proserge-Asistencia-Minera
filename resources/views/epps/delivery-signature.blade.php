@extends('layouts.app')
@section('title', 'Revisar y firmar EPP - Proserge')
@section('content')
<div class="epp-operation epp-worker-review" data-epp-operation>
    <header class="epp-operation-card">
        <h1>Revisar y firmar {{ $operation->tipo === 'DEVOLUCION' ? 'movimiento' : 'entrega' }}</h1>
        <p>{{ ($payload['return_selection'] ?? false) ? 'Revisa con el trabajador los articulos que devuelve y el saldo restante. Todavia no se han actualizado sus saldos.' : 'Revisa con el trabajador los datos registrados. Todavia no se ha guardado la entrega ni cerrado el registro anterior.' }}</p>
        <a href="{{ url('/logistica?tab=entregas') }}">Volver a Entregas y cambios sin confirmar</a>
    </header>
    @include('epps.operations.messages')
    <section class="epp-operation-card">
        <h2>{{ $payload['worker']['name'] }}</h2>
        <p>Documento: {{ $payload['worker']['document'] }} · Fecha: {{ $payload['date'] }}</p>
        @if(!empty($payload['input']['_epp_replaces_entrega_id']))<p>Este registro confirmara el cambio de la entrega anterior. Motivo: {{ $payload['input']['_epp_replacement_motivo'] ?? '' }}</p>@endif
        @if($operation->tipo === 'DEVOLUCION')<p>Movimiento: {{ str_replace('_', ' ', $payload['input']['estado'] ?? 'DEVOLUCION') }}. Motivo: {{ $payload['input']['motivo_cambio'] ?? $payload['input']['observacion'] ?? '' }}</p>@endif
        <h2>Articulos incluidos en esta firma ({{ count($payload['details']) }})</h2>
        <p>La firma confirma todos los articulos detallados a continuacion.</p>
        @foreach($payload['details'] as $detail)
            <article class="epp-operation-card" data-epp-signing-item>
            <h3>{{ $detail['snapshot']['real']['nombre'] }}</h3>
            <p>Cantidad: {{ $detail['processed_quantity'] }} · Talla: {{ $detail['size'] ?: 'No aplica' }} · Color: {{ $detail['color'] ?: 'No aplica' }}</p>
            @if($payload['return_selection'] ?? false)<p>Saldo anterior: {{ $detail['snapshot']['saldo_anterior'] }} · Saldo restante: {{ $detail['snapshot']['saldo_restante'] }}</p>@endif
            @foreach($detail['attributes'] as $name => $value)<p>{{ $name }}: {{ $value }}</p>@endforeach
            @if($detail['reason'])<p>Observacion: {{ $detail['reason'] }}</p>@endif
            </article>
        @endforeach
    </section>
    <form method="POST" action="{{ route('epps.delivery-signature.confirm', $operation) }}" class="epp-operation-card" data-operation-sign>
        @csrf
        <input type="hidden" name="submission_uuid" value="{{ $operation->submission_uuid }}">
        <input type="hidden" name="personal_id" value="{{ $operation->personal_id }}">
        <input type="hidden" name="review_hash" value="{{ $operation->resumen_sha256 }}" data-review-identity>
        <input type="hidden" name="review_version" value="{{ $operation->version }}" data-review-identity>
        <input type="hidden" name="tipo_firmante" value="TRABAJADOR">
        @include('epps.operations.delivery-declaration')
        <label class="epp-operation-check"><input type="checkbox" name="confirmacion" value="1" required><span>{{ ($payload['return_selection'] ?? false) ? 'Confirmo haber devuelto los articulos y cantidades indicados.' : ($operation->tipo === 'DEVOLUCION' ? 'Confirmo el movimiento y los articulos indicados.' : 'Confirmo haber recibido los articulos y cantidades indicados.') }}</span></label>
        <div class="signature-pad-wrap">
            <h2>Firma del trabajador</h2>
            <p>Dibuja tu firma con el dedo o el mouse. Si los datos no coinciden, vuelve sin confirmar.</p>
            <canvas class="signature-pad" data-signature-pad data-required="1" data-min-ink="1" aria-label="Firma del trabajador"></canvas>
            <input type="hidden" name="firma_data_url" data-signature-input>
            <input type="hidden" name="ink[point_count]" data-ink="point_count">
            @foreach(['min_x', 'min_y', 'max_x', 'max_y'] as $bound)<input type="hidden" name="ink[bounds][{{ $bound }}]" data-ink="{{ $bound }}">@endforeach
            <p data-signature-error role="alert" hidden>Dibuja la firma antes de confirmar.</p>
            <button type="button" class="epp-operation-button" data-clear-signature>Limpiar firma</button>
        </div>
        <button type="submit" class="epp-operation-button is-primary">Firmar y registrar</button>
    </form>
</div>
@endsection
