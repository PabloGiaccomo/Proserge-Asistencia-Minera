@extends('layouts.app')

@section('title', 'Dashboard de Logistica - Proserge')

@section('content')
@php
    $standaloneDashboard = true;
    $asCollection = static fn ($value) => collect($value ?? []);
@endphp
<div class="lgt-page">
    <header class="lgt-header">
        <h1 class="lgt-title">Logistica</h1>
        <p class="lgt-subtitle">Cobertura y recursos dentro de tu alcance</p>
    </header>
    @include('logistica.partials.dashboard')
</div>
@endsection
