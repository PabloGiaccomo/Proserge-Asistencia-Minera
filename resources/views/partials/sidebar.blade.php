<aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">
        <!-- Brand -->
        <div class="sidebar-brand">
            <div class="brand-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 21h18"/>
                    <path d="M5 21V7l8-4v18"/>
                    <path d="M19 21V11l-6-4"/>
                    <path d="M9 9v.01"/>
                    <path d="M9 12v.01"/>
                    <path d="M9 15v.01"/>
                    <path d="M9 18v.01"/>
                </svg>
            </div>
            <div class="brand-text">
                <span class="brand-name">Proserge</span>
                <span class="brand-subtitle">Asistencia Minera</span>
            </div>
        </div>
        
        <!-- User Card -->
        <div class="sidebar-user">
            <a href="{{ route('perfil.index') }}" class="user-card">
                <div class="user-avatar">
                    {{ strtoupper(substr(session('user.name') ?? session('user.email') ?? 'U', 0, 2)) }}
                </div>
                <div class="user-info">
                    <span class="user-name">{{ session('user.name') ?? session('user.email') ?? 'Usuario' }}</span>
                    <span class="user-role">{{ session('user.rol') ?? 'Usuario' }}</span>
                </div>
            </a>
        </div>
        
        <!-- Navigation -->
        <nav class="sidebar-nav">
            <div class="nav-group">
                <div class="nav-group-title">Inicio</div>
                <a href="{{ route('inicio') }}" class="nav-item {{ request()->is('inicio') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
                    <span class="nav-label">Inicio</span>
                </a>
                <a href="{{ route('perfil.index') }}" class="nav-item {{ request()->is('perfil*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                    <span class="nav-label">Perfil</span>
                </a>
            </div>
            
            <div class="nav-group">
                <div class="nav-group-title">Operación</div>
                <a href="{{ route('personal.index') }}" class="nav-item {{ request()->is('personal*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span>
                    <span class="nav-label">Personal</span>
                </a>
                <a href="{{ route('rq-mina.index') }}" class="nav-item {{ request()->is('rq-mina*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                    <span class="nav-label">RQ Mina</span>
                </a>
                <a href="{{ route('rq-proserge.index') }}" class="nav-item {{ request()->is('rq-proserge*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                    <span class="nav-label">RQ Proserge</span>
                </a>
                <a href="{{ route('man-power.index') }}" class="nav-item {{ request()->is('man-power*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span>
                    <span class="nav-label">Man Power</span>
                </a>
                <a href="{{ route('mi-asistencia.index') }}" class="nav-item {{ request()->is('mi-asistencia*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
                    <span class="nav-label">Mi Asistencia</span>
                </a>
                <a href="{{ route('asistencia.index') }}" class="nav-item {{ request()->is('asistencia*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/></svg></span>
                    <span class="nav-label">Asistencias</span>
                </a>
                <a href="{{ route('faltas.index') }}" class="nav-item {{ request()->is('faltas*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></span>
                    <span class="nav-label">Faltas</span>
                </a>
                <a href="#" class="nav-item {{ request()->is('remoto*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="14" rx="2"/><line x1="8" y1="20" x2="16" y2="20"/><line x1="12" y1="18" x2="12" y2="20"/></svg></span>
                    <span class="nav-label">Remoto</span>
                </a>
                <a href="#" class="nav-item {{ request()->is('epps*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 14a8 8 0 0 1 16 0"/><path d="M6 14v4a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-4"/><path d="M9 20v-3"/><path d="M15 20v-3"/></svg></span>
                    <span class="nav-label">EPPs</span>
                </a>
            </div>
            
            <div class="nav-group">
                <div class="nav-group-title">Gestión</div>
                <a href="{{ route('bienestar.index') }}" class="nav-item {{ request()->is('bienestar*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></span>
                    <span class="nav-label">Bienestar</span>
                </a>
                <a href="{{ route('evaluaciones.index') }}" class="nav-item {{ request()->is('evaluaciones*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span>
                    <span class="nav-label">Evaluaciones</span>
                </a>
            </div>

            <div class="nav-group">
                <div class="nav-group-title">Catálogos</div>
                <a href="{{ route('catalogos.minas.index') }}" class="nav-item {{ request()->is('catalogos/minas*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 20h18"/><path d="M6 20V10l4-3 4 3v10"/><path d="M14 20V7l4-3 3 2v14"/></svg></span>
                    <span class="nav-label">Minas</span>
                </a>
                <a href="{{ route('catalogos.talleres.index') }}" class="nav-item {{ request()->is('catalogos/talleres*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-2-2a1 1 0 0 1 0-1.4l8-8a1 1 0 0 1 1.4 0z"/><path d="M16 4l4 4"/><path d="M19 2l3 3"/></svg></span>
                    <span class="nav-label">Talleres</span>
                </a>
                <a href="{{ route('catalogos.oficinas.index') }}" class="nav-item {{ request()->is('catalogos/oficinas*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg></span>
                    <span class="nav-label">Oficinas</span>
                </a>
            </div>
            
            <div class="nav-group">
                <div class="nav-group-title">Administración</div>
                <a href="{{ route('usuarios.index') }}" class="nav-item {{ request()->is('usuarios*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span>
                    <span class="nav-label">Usuarios</span>
                </a>
                <a href="{{ route('seguridad.roles.index') }}" class="nav-item {{ request()->is('seguridad/roles*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15l-3.5 2 1-4-3-2.5 4-.3L12 6l1.5 4.2 4 .3-3 2.5 1 4z"/></svg></span>
                    <span class="nav-label">Roles</span>
                </a>
            </div>
        </nav>
        
        <!-- Logout -->
        <div class="sidebar-footer">
            <form action="{{ route('logout') }}" method="POST">@csrf<button type="submit" class="logout-btn"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span>Cerrar Sesión</span></button></form>
        </div>
    </div>
</aside>