<header class="app-header">
    <div class="header-left">
        <button 
            class="menu-toggle" 
            id="menuToggle" 
            type="button"
            aria-label="Abrir menú"
            aria-controls="sidebar"
            aria-expanded="false"
            style="display: flex;"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
            </svg>
        </button>
        
        <div class="header-title">
            @yield('title', 'Proserge')
        </div>
    </div>
    
    <div class="header-right">
        <a href="{{ route('perfil.index') }}" class="header-avatar" title="Ver mi perfil - {{ session('user.name') ?? session('user.email') ?? 'Usuario' }}">
            {{ strtoupper(substr(session('user.name') ?? session('user.email') ?? 'U', 0, 2)) }}
        </a>
    </div>
</header>