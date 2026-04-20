<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Proserge')</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="app-layout with-sidebar">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-inner">
                <div class="sidebar-brand">
                    <div class="brand-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
                    </div>
                    <div class="brand-text">
                        <span class="brand-name">Proserge</span>
                        <span class="brand-subtitle">Asistencia Minera</span>
                    </div>
                </div>
                <div class="sidebar-user">
                    <div class="user-card">
                        <div class="user-avatar">{{ strtoupper(substr(session('user.name') ?? session('user.email') ?? 'U', 0, 2)) }}</div>
                        <div class="user-info">
                            <span class="user-name">{{ session('user.name') ?? session('user.email') ?? 'Usuario' }}</span>
                            <span class="user-role">{{ session('user.rol') ?? 'Usuario' }}</span>
                        </div>
                    </div>
                </div>
                <nav class="sidebar-nav">
                    <div class="nav-group">
                        <a href="{{ route('inicio') }}" class="nav-item {{ request()->is('inicio') ? 'active' : '' }}"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg></span><span class="nav-label">Inicio</span></a>
                    </div>
                    <div class="nav-group">
                        <div class="nav-group-title">Operación</div>
                        <a href="{{ route('personal.index') }}" class="nav-item {{ request()->is('personal*') ? 'active' : '' }}"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/></svg></span><span class="nav-label">Personal</span></a>
                        <a href="{{ route('asistencia.index') }}" class="nav-item {{ request()->is('asistencia') && !request()->is('mi-asistencia*') ? 'active' : '' }}"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span><span class="nav-label">Asistencia</span></a>
                        <a href="{{ route('mi-asistencia.index') }}" class="nav-item {{ request()->is('mi-asistencia*') ? 'active' : '' }}"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/></svg></span><span class="nav-label">Mi Asistencia</span></a>
                        <a href="{{ route('man-power.index') }}" class="nav-item {{ request()->is('man-power*') ? 'active' : '' }}"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/></svg></span><span class="nav-label">Man Power</span></a>
                        <a href="{{ route('rq-mina.index') }}" class="nav-item {{ request()->is('rq-mina*') ? 'active' : '' }}"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg></span><span class="nav-label">RQ Mina</span></a>
                        <a href="{{ route('rq-proserge.index') }}" class="nav-item {{ request()->is('rq-proserge*') ? 'active' : '' }}"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg></span><span class="nav-label">RQ Proserge</span></a>
                    </div>
                    <div class="nav-group">
                        <div class="nav-group-title">Gestión</div>
                        <a href="{{ route('bienestar.index') }}" class="nav-item {{ request()->is('bienestar*') ? 'active' : '' }}"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></span><span class="nav-label">Bienestar</span></a>
                        <a href="{{ route('evaluaciones.index') }}" class="nav-item {{ request()->is('evaluaciones*') ? 'active' : '' }}"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span><span class="nav-label">Evaluaciones</span></a>
                    </div>
                    <div class="nav-group">
                        <div class="nav-group-title">Administración</div>
                        <a href="{{ route('usuarios.index') }}" class="nav-item {{ request()->is('usuarios*') ? 'active' : '' }}"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/></svg></span><span class="nav-label">Usuarios</span></a>
                        <a href="{{ route('catalogos.index') }}" class="nav-item {{ request()->is('catalogos*') ? 'active' : '' }}"><span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/></svg></span><span class="nav-label">Catálogos</span></a>
                    </div>
                </nav>
                <div class="sidebar-footer">
                    <form action="{{ route('logout') }}" method="POST">@csrf<button type="submit" class="logout-btn"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span>Cerrar Sesión</span></button></form>
                </div>
            </div>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <div class="app-main">
            @include('partials.header')
            <main class="app-content">@yield('content')</main>
            <footer class="app-footer"><span>&copy; {{ date('Y') }} Proserge</span></footer>
        </div>
    </div>

    <!-- Mobile Bottom Nav -->
    <nav class="mobile-bottom-nav">
        <div class="mobile-bottom-nav-inner">
            <a href="{{ route('inicio') }}" class="mobile-bottom-nav-item {{ request()->is('inicio') ? 'active' : '' }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg><span>Inicio</span></a>
            <a href="{{ route('personal.index') }}" class="mobile-bottom-nav-item {{ request()->is('personal*') ? 'active' : '' }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/></svg><span>Personal</span></a>
            <a href="{{ route('asistencia.index') }}" class="mobile-bottom-nav-item {{ request()->is('asistencia') && !request()->is('mi-asistencia*') ? 'active' : '' }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><span>Asistencia</span></a>
            <a href="{{ route('mi-asistencia.index') }}" class="mobile-bottom-nav-item {{ request()->is('mi-asistencia*') ? 'active' : '' }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg><span>Marca</span></a>
            <a href="{{ route('rq-mina.index') }}" class="mobile-bottom-nav-item {{ request()->is('rq-mina*') ? 'active' : '' }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg><span>RQ Mina</span></a>
            <button class="mobile-bottom-nav-item" id="menuToggleBtn" type="button" aria-label="Abrir menú" aria-controls="sidebar" aria-expanded="false"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg><span>Más</span></button>
        </div>
    </nav>

    <!-- Modal Container -->
    <div class="modal" id="workerDetailModal" style="display: none;">
        <div class="modal-backdrop" onclick="closeModal('workerDetailModal')"></div>
        <div class="modal-content"></div>
    </div>

    @stack('scripts')
</body>
</html>