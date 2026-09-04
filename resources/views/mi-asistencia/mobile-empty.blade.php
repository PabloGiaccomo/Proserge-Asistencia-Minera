@extends('layouts.mobile')

@section('title', 'Mi Marca - Proserge')

@section('content')
@php
    $personal = $user->personal;
@endphp

<div class="mob-page">
    <header class="mob-header">
        <div class="mob-header-top">
            <div class="mob-avatar">{{ mb_strtoupper(mb_substr((string) ($personal?->nombre_completo ?? $user->name ?? '??'), 0, 2)) }}</div>
            <div class="mob-header-info">
                <div class="mob-label">Bienvenido</div>
                <div class="mob-name">{{ $personal?->nombre_completo ?? $user->name ?? $user->email }}</div>
            </div>
        </div>
        <div class="mob-header-meta">
            <span>&#128197; {{ now()->locale('es')->translatedFormat('d \\d\\e F \\d\\e Y') }}</span>
        </div>
    </header>

    <div class="mob-body">
        <div class="mob-card">
            <div style="text-align:center;padding:24px 10px;">
                <div style="font-size:48px;margin-bottom:12px;">&#128197;</div>
                <div style="font-size:16px;font-weight:800;color:#1e293b;margin-bottom:6px;">Sin grupo asignado hoy</div>
                <div style="font-size:13px;color:#7b8da6;line-height:1.5;">
                    No se encontro un grupo de trabajo asignado para ti en esta fecha.<br>
                    Consulta con tu supervisor.
                </div>
            </div>
        </div>

        <div class="mob-footer">Proserge &copy; {{ date('Y') }}</div>
    </div>
</div>
@endsection
