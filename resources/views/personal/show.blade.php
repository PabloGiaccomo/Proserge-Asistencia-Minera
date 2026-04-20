@extends('layouts.app')

@section('title', 'Ver Personal - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Detalle del Trabajador</h1>
                <p class="page-subtitle">Información completa del personal</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">
                    ← Volver
                </a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <p class="text-gray-500">ID: {{ $id }}</p>
            <p class="text-gray-500">Vista de detallecoming soon...</p>
        </div>
    </div>
</div>
@endsection
