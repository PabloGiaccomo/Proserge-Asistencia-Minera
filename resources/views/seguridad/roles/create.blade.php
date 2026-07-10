@extends('layouts.app')

@section('title', 'Nuevo Rol - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Nuevo Rol</h1>
                <p class="page-subtitle">Define módulos visibles y acciones permitidas.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('seguridad.roles.index') }}" class="btn btn-outline">Volver</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-error" style="margin-bottom:16px;">{{ session('error') }}</div>
    @endif

    @include('seguridad.roles._form', ['mode' => 'create'])
</div>
@endsection
