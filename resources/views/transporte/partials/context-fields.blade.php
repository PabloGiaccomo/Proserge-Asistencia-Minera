@foreach(['rq_mina_id', 'rq_mina_plan_id', 'fecha', 'turno'] as $field)
    <input type="hidden" name="contexto[{{ $field }}]" value="{{ $formContext[$field] ?? '' }}">
@endforeach
@foreach($formContext['lista'] as $field => $value)
    <input type="hidden" name="contexto[lista][{{ $field }}]" value="{{ $value }}">
@endforeach
