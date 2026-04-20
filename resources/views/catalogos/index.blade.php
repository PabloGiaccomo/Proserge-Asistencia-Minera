@extends('layouts.app')

@section('title', 'Catálogos - Proserge')

@section('content')
<div class="module-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Catálogos</h1>
                <p class="page-subtitle">Gestión de catálogos del sistema</p>
            </div>
        </div>
    </div>

    <!-- Catalogs Grid -->
    <div class="catalog-grid">
        <a href="{{ route('catalogos.minas.index') }}" class="catalog-card">
            <div class="cc-icon" style="background: linear-gradient(135deg, rgba(25, 211, 197, 0.15), rgba(25, 211, 197, 0.08)); color: #19D3C5;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 21h18"/>
                    <path d="M5 21V7l8-4v18"/>
                    <path d="M19 21V11l-6-4"/>
                    <path d="M9 9v.01"/>
                    <path d="M9 12v.01"/>
                    <path d="M9 15v.01"/>
                    <path d="M9 18v.01"/>
                </svg>
            </div>
            <div class="cc-content">
                <span class="cc-title">Minas</span>
                <span class="cc-desc">Catálogo de minas</span>
            </div>
        </a>

        <a href="{{ route('catalogos.talleres.index') }}" class="catalog-card">
            <div class="cc-icon" style="background: linear-gradient(135deg, rgba(79, 140, 255, 0.15), rgba(79, 140, 255, 0.08)); color: #4F8CFF;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                </svg>
            </div>
            <div class="cc-content">
                <span class="cc-title">Talleres</span>
                <span class="cc-desc">Catálogo de talleres</span>
            </div>
        </a>

        <a href="{{ route('catalogos.oficinas.index') }}" class="catalog-card">
            <div class="cc-icon" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.08)); color: #10B981;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="4" y="2" width="16" height="20" rx="2" ry="2"/>
                    <path d="M9 22v-4h6v4"/>
                    <path d="M8 6h.01"/>
                    <path d="M16 6h.01"/>
                    <path d="M12 6h.01"/>
                    <path d="M12 10h.01"/>
                    <path d="M12 14h.01"/>
                    <path d="M16 10h.01"/>
                    <path d="M16 14h.01"/>
                    <path d="M8 10h.01"/>
                    <path d="M8 14h.01"/>
                </svg>
            </div>
            <div class="cc-content">
                <span class="cc-title">Oficinas</span>
                <span class="cc-desc">Catálogo de oficinas</span>
            </div>
        </a>

        <a href="{{ route('catalogos.paraderos.index') }}" class="catalog-card">
            <div class="cc-icon" style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(245, 158, 11, 0.08)); color: #F59E0B;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 11l19-9-9 19-2-8-8-2z"/>
                </svg>
            </div>
            <div class="cc-content">
                <span class="cc-title">Paraderos</span>
                <span class="cc-desc">Catálogo de paraderos</span>
            </div>
        </a>
    </div>
</div>
@endsection
