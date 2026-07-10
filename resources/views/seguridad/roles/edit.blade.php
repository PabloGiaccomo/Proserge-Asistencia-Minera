@extends('layouts.app')

@section('title', 'Editar Rol - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Editar Rol</h1>
                <p class="page-subtitle">{{ $rol->nombre }}</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('seguridad.roles.index') }}" class="btn btn-outline">Volver</a>
                <a href="{{ route('seguridad.roles.show', $rol->id) }}" class="btn btn-outline">Ver rol</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-error" style="margin-bottom:16px;">{{ session('error') }}</div>
    @endif

    @include('seguridad.roles._form', ['mode' => 'edit'])
</div>
@endsection
