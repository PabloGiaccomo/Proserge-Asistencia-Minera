@extends('layouts.app')
@section('title', ($operation->tipo === 'DEVOLUCION' ? 'Revisar devolucion EPP' : 'Revisar entrega EPP').' - Proserge')
@section('content')
<div class="epp-operation epp-worker-review" data-epp-operation>
    <header class="epp-operation-card"><p>Paso 2 de 2 · Revision del trabajador</p><h1>{{ $operation->tipo === 'DEVOLUCION' ? 'Revisa lo que devuelves' : 'Revisa lo que recibes' }}</h1><p>Lee los articulos y cantidades. Si algo no coincide o no deseas firmar, registra el desacuerdo abajo.</p><p>La revision vence {{ $operation->reserva_expires_at->format('H:i') }}. Si vence, se debera preparar otro lote.</p></header>
    @include('epps.operations.messages')
    @if($operation->tipo === 'DEVOLUCION' && $errors->any())<a class="epp-operation-button" href="{{ route('epps.operations.returns.create', ['personal_id' => $operation->personal_id, 'almacen_id' => $operation->almacen_id]) }}">Consultar saldos y preparar nueva devolucion</a>@endif
    @include('epps.operations.summary')
    <form method="POST" action="{{ route('epps.operations.sign', $operation) }}" class="epp-operation-card" data-operation-sign>
        @csrf
        <input type="hidden" name="submission_uuid" value="{{ $operation->submission_uuid }}">
        <input type="hidden" name="personal_id" value="{{ $operation->personal_id }}">
        <input type="hidden" name="review_hash" value="{{ $operation->resumen_sha256 }}" data-review-identity>
        <input type="hidden" name="review_version" value="{{ $operation->version }}" data-review-identity>
        <input type="hidden" name="tipo_firmante" value="{{ $witness ? 'TESTIGO_AUTORIZADO' : 'TRABAJADOR' }}">
        @if($witness)
            <h2>Testigo autorizado</h2><p>El trabajador no firmo. Esta firma pertenece al testigo identificado; no sustituye una negativa a firmar.</p>
            <p>Operador: {{ $operator?->personal?->nombre_completo ?? $operator?->email ?? 'Operador registrado' }}. Aprobador: {{ $approver->personal?->nombre_completo ?? $approver->email }}.</p>
            <label>Testigo identificado<select name="testigo_usuario_id" required data-signer-field><option value="">Seleccionar testigo</option>@foreach($witnesses as $user)<option value="{{ $user->id }}">{{ $user->personal->nombre_completo }} · {{ $user->personal->dni }}</option>@endforeach</select></label>
            <label>Motivo de la excepcion (no admite negativa)<textarea name="motivo_excepcion" required maxlength="1000" data-signer-field></textarea></label>
        @endif
        @include('epps.operations.delivery-declaration')
        <label class="epp-operation-check"><input type="checkbox" name="confirmacion" value="1" required><span>{{ $operation->tipo === 'DEVOLUCION' ? ($witness ? 'Confirmo la devolucion presenciada de unicamente los articulos mostrados' : 'Confirmo haber devuelto unicamente los articulos mostrados') : ($witness ? 'Confirmo la recepcion presenciada de unicamente los articulos mostrados' : 'Confirmo haber recibido unicamente los articulos mostrados') }}</span></label>
        <div class="signature-pad-wrap">
            <h2>{{ $witness ? 'Firma del testigo' : 'Tu firma' }}</h2><p>Dibuja tu firma completa con el dedo o el mouse. Si te equivocas, puedes limpiar y volver a firmar.</p>
            <canvas class="signature-pad" data-signature-pad data-required="1" data-min-ink="1" aria-label="Recuadro para dibujar la firma"></canvas>
            <input type="hidden" name="firma_data_url" data-signature-input>
            <input type="hidden" name="ink[point_count]" data-ink="point_count">
            @foreach(['min_x', 'min_y', 'max_x', 'max_y'] as $bound)<input type="hidden" name="ink[bounds][{{ $bound }}]" data-ink="{{ $bound }}">@endforeach
            <p data-signature-error role="alert" hidden>Dibuja una firma completa antes de confirmar.</p>
            <button type="button" class="epp-operation-button" data-clear-signature>Limpiar firma</button>
        </div>
        <button class="epp-operation-button is-primary" type="submit">{{ $witness ? 'Aprobar excepcion y confirmar' : 'Firmar y confirmar' }} {{ $operation->tipo === 'DEVOLUCION' ? 'devolucion' : 'entrega' }}</button>
    </form>
    <section class="epp-operation-card"><h2>Algo no coincide o no deseas firmar</h2><p>{{ $operation->tipo === 'DEVOLUCION' ? 'No se registrara devolucion ni cambio de saldo.' : 'Se liberara la reserva y no se registrara entrega.' }} El operador debera revisar el lote.</p>
        <form method="POST" action="{{ route('epps.operations.reject', $operation) }}" data-operation-reject>
            @csrf
            <label>Indica lo ocurrido<select name="decision" required><option value="DESACUERDO">No estoy de acuerdo con los articulos o cantidades</option><option value="NEGATIVA">No deseo firmar</option></select></label>
            <label>Motivo<textarea name="motivo" maxlength="900" required></textarea></label>
            <button type="submit" class="epp-operation-button">Registrar sin {{ $operation->tipo === 'DEVOLUCION' ? 'devolucion' : 'entrega' }}</button>
        </form>
    </section>
    @if($canWitness && !$witness)<details class="epp-operation-card"><summary>Excepcion autorizada por imposibilidad de firmar</summary><p>Requiere testigo identificado y aprobador distinto del operador y testigo. No aplica cuando el trabajador se niega.</p><a class="epp-operation-button" href="{{ route('epps.operations.review', [$operation, 'testigo' => 1]) }}">Abrir testigo autorizado</a></details>@endif
</div>
@endsection
