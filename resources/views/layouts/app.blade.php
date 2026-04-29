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
        @include('partials.sidebar')
        
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
            @allowed('inicio', 'ver')<a href="{{ route('inicio') }}" class="mobile-bottom-nav-item {{ request()->is('inicio') ? 'active' : '' }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg><span>Inicio</span></a>@endallowed
            @allowed('personal', 'ver')<a href="{{ route('personal.index') }}" class="mobile-bottom-nav-item {{ request()->is('personal*') ? 'active' : '' }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/></svg><span>Personal</span></a>@endallowed
            @allowed('mi_asistencia', 'ver')<a href="{{ route('mi-asistencia.index') }}" class="mobile-bottom-nav-item {{ request()->is('mi-asistencia*') ? 'active' : '' }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><span>Mi Marca</span></a>@endallowed
            @allowed('bienestar', 'ver')<a href="{{ route('bienestar.index') }}" class="mobile-bottom-nav-item {{ request()->is('bienestar*') ? 'active' : '' }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg><span>Bienestar</span></a>@endallowed
            @allowed('usuarios', 'ver')<a href="{{ route('usuarios.index') }}" class="mobile-bottom-nav-item {{ request()->is('usuarios*') ? 'active' : '' }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/></svg><span>Usuarios</span></a>@endallowed
            <button class="mobile-bottom-nav-item" id="menuToggleBtn" type="button" aria-label="Abrir menú" aria-controls="sidebar" aria-expanded="false"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg><span>Más</span></button>
        </div>
    </nav>
    
    @stack('scripts')
    @yield('scripts')
</body>
</html>
