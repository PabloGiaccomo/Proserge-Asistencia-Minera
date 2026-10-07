@foreach(['success', 'warning'] as $message)
    @if(session($message))<p class="epp-operation-card" role="status">{{ session($message) }}</p>@endif
@endforeach
@if($errors->any())<div class="epp-operation-card" role="alert"><h2>Revisa antes de continuar</h2><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul><p>La firma no se conserva al cambiar o corregir una revision. Revisa los articulos y firma nuevamente.</p></div>@endif
