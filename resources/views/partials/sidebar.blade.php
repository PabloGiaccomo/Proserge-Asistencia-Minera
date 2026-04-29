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
            @if(\App\Support\Rbac\PermissionMatrix::allows(session('user.permissions', []), 'perfil', 'ver'))
            <a href="{{ route('perfil.index') }}" class="user-card">
            @else
            <div class="user-card">
            @endif
                <div class="user-avatar">{{ strtoupper(substr(session('user.name') ?? session('user.email') ?? 'U', 0, 2)) }}</div>
                <div class="user-info">
                    <span class="user-name">{{ session('user.name') ?? session('user.email') ?? 'Usuario' }}</span>
                    <span class="user-role">{{ session('user.rol') ?? 'Usuario' }}</span>
                </div>
            @if(\App\Support\Rbac\PermissionMatrix::allows(session('user.permissions', []), 'perfil', 'ver'))
            </a>
            @else
            </div>
            @endif
        </div>

        @php
            $permissions = session('user.permissions', []);
            $canInicio = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'inicio', 'ver');
            $canPersonal = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'personal', 'ver');
            $canBienestar = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'bienestar', 'ver');
            $canRQMina = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'rq_mina', 'ver');
            $canRQProserge = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'rq_proserge', 'ver');
            $canManPower = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'man_power', 'ver');
            $canMiAsistencia = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'mi_asistencia', 'ver');
            $canEvaluaciones = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'evaluaciones', 'ver');
            $canAsistencias = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'asistencias', 'ver');
            $canFaltas = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'faltas', 'ver');
            $canCatalogos = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'catalogos', 'ver');
            $canUsuarios = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'usuarios', 'ver');
            $canRoles = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'roles', 'ver');
        @endphp

        <nav class="sidebar-nav">
            @if($canInicio)
            <div class="nav-group">
                <div class="nav-group-title">Inicio</div>
                <a href="{{ route('inicio') }}" class="nav-item {{ request()->is('inicio') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg></span>
                    <span class="nav-label">Inicio</span>
                </a>
            </div>
            @endif

            @if($canPersonal)
            <div class="nav-group">
                <div class="nav-group-title">Personal</div>
                <a href="{{ route('personal.index') }}" class="nav-item {{ request()->is('personal*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/></svg></span>
                    <span class="nav-label">Personal</span>
                </a>
            </div>
            @endif

            @if($canBienestar)
            <div class="nav-group">
                <div class="nav-group-title">Bienestar</div>
                <a href="{{ route('bienestar.index') }}" class="nav-item {{ request()->is('bienestar*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></span>
                    <span class="nav-label">Bienestar</span>
                </a>
            </div>
            @endif

            @if($canRQMina || $canRQProserge || $canManPower || $canMiAsistencia)
            <div class="nav-group">
                <div class="nav-group-title">Operación</div>
                <div class="nav-subgroup">
                    @if($canRQMina)
                    <a href="{{ route('rq-mina.index') }}" class="nav-item {{ request()->is('rq-mina*') ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg></span>
                        <span class="nav-label">RQ Mina</span>
                    </a>
                    @endif
                    @if($canRQProserge)
                    <a href="{{ route('rq-proserge.index') }}" class="nav-item {{ request()->is('rq-proserge*') ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg></span>
                        <span class="nav-label">RQ Proserge</span>
                    </a>
                    @endif
                    @if($canManPower)
                    <a href="{{ route('man-power.index') }}" class="nav-item {{ request()->is('man-power*') ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/></svg></span>
                        <span class="nav-label">Man Power</span>
                    </a>
                    @endif
                    @if($canMiAsistencia)
                    <a href="{{ route('mi-asistencia.index') }}" class="nav-item {{ request()->is('mi-asistencia*') ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
                        <span class="nav-label">Mi Asistencia</span>
                    </a>
                    @endif
                </div>
            </div>
            @endif

            @if($canEvaluaciones || $canAsistencias || $canFaltas)
            <div class="nav-group">
                <div class="nav-group-title">Gestión</div>
                <div class="nav-subgroup">
                    @if($canEvaluaciones)
                    <a href="{{ route('evaluaciones.index') }}" class="nav-item {{ request()->is('evaluaciones*') ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span>
                        <span class="nav-label">Evaluaciones</span>
                    </a>
                    @endif

                    @if($canAsistencias)
                    <a href="{{ route('asistencia.index') }}" class="nav-item {{ request()->is('asistencia') && !request()->is('mi-asistencia*') ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/></svg></span>
                        <span class="nav-label">Asistencias</span>
                    </a>
                    @endif

                    @if($canFaltas)
                    <a href="{{ route('faltas.index') }}" class="nav-item {{ request()->is('faltas*') ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></span>
                        <span class="nav-label">Faltas</span>
                    </a>
                    @endif
                </div>
            </div>
            @endif

            @if($canCatalogos)
            <div class="nav-group">
                <div class="nav-group-title">Catálogos</div>
                <a href="{{ route('catalogos.index') }}" class="nav-item {{ request()->is('catalogos') || request()->is('catalogos/*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/></svg></span>
                    <span class="nav-label">Catálogos</span>
                </a>
            </div>
            @endif

            @if($canUsuarios || $canRoles)
            <div class="nav-group">
                <div class="nav-group-title">Administración</div>
                @if($canUsuarios)
                <a href="{{ route('usuarios.index') }}" class="nav-item {{ request()->is('usuarios*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/></svg></span>
                    <span class="nav-label">Usuarios</span>
                </a>
                @endif
                @if($canRoles)
                <a href="{{ route('seguridad.roles.index') }}" class="nav-item {{ request()->is('seguridad/roles*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15l-3.5 2 1-4-3-2.5 4-.3L12 6l1.5 4.2 4 .3-3 2.5 1 4z"/></svg></span>
                    <span class="nav-label">Roles</span>
                </a>
                @endif
            </div>
            @endif
        </nav>

        <div class="sidebar-footer">
            <form action="{{ route('logout') }}" method="POST">@csrf<button type="submit" class="logout-btn"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span>Cerrar Sesión</span></button></form>
        </div>
    </div>
</aside>
