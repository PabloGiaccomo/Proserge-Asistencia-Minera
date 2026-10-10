@if($operation->tipo === 'ENTREGA')
    <section aria-label="Declaracion de recepcion de EPP" data-epp-delivery-declaration>
        <h2>Declaracion del trabajador</h2>
        @foreach(explode("\n", $payload['declaration_text'] ?? \App\Modules\Epps\Support\EppDeliveryDeclaration::TEXT) as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
    </section>
@endif
