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
        <div class="header-notif-menu" id="headerNotifMenu">
            <button type="button" class="header-notif-btn" id="headerNotifToggle" aria-haspopup="true" aria-expanded="false" aria-controls="headerNotifPanel" title="Notificaciones">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/>
                    <path d="M9 17a3 3 0 0 0 6 0"/>
                </svg>
                @if(($headerUnreadCount ?? 0) > 0)
                    <span class="header-notif-count">{{ $headerUnreadCount > 99 ? '99+' : $headerUnreadCount }}</span>
                @endif
            </button>
        </div>

        <div class="header-notif-backdrop" id="headerNotifBackdrop" aria-hidden="true"></div>

        <aside class="header-notif-panel" id="headerNotifPanel" aria-hidden="true" aria-label="Bandeja de notificaciones">
            <div class="header-notif-top">
                <span>Notificaciones</span>
                @if(($headerUnreadCount ?? 0) > 0)
                    <span class="header-notif-top-count">{{ $headerUnreadCount }} no leidas</span>
                @endif
            </div>

            <div class="header-notif-panel-actions">
                @if(($headerUnreadCount ?? 0) > 0)
                    <form method="POST" action="{{ route('notificaciones.mark-all-read') }}">
                        @csrf
                        <button type="submit" class="header-user-dropdown-item">Marcar todas leidas</button>
                    </form>
                @endif
            </div>

            <div class="header-notif-list is-panel-list">
                @forelse(($headerNotifications ?? []) as $notif)
                    @php $event = $notif->event; @endphp
                    <div class="header-notif-item {{ $notif->status === 'UNREAD' ? 'is-unread' : '' }}">
                        <div class="header-notif-item-title">{{ $event->title }}</div>
                        <div class="header-notif-item-message">{{ $event->message }}</div>
                        <div class="header-notif-item-meta">
                            <span>{{ strtoupper($event->module) }}</span>
                            <span>{{ strtoupper($event->priority) }}</span>
                            <span>{{ optional($notif->created_at)->diffForHumans() }}</span>
                        </div>
                        <div class="header-notif-item-actions">
                            @if($notif->status === 'UNREAD')
                                <form method="POST" action="{{ route('notificaciones.mark-read', $notif->id) }}">
                                    @csrf
                                    <button type="submit" class="header-user-dropdown-item">Leida</button>
                                </form>
                            @endif
                            @if($notif->status !== 'ARCHIVED')
                                <form method="POST" action="{{ route('notificaciones.archive', $notif->id) }}">
                                    @csrf
                                    <button type="submit" class="header-user-dropdown-item">Archivar</button>
                                </form>
                            @endif
                            @if(!empty($event->action_route))
                                <a href="{{ route('notificaciones.action', $notif->id) }}" class="header-user-dropdown-item">{{ $event->action_label ?: 'Abrir' }}</a>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="header-notif-empty">No tienes notificaciones.</div>
                @endforelse
            </div>

            <div class="header-notif-footer">
                <button type="button" class="header-user-dropdown-item" id="headerNotifClose">Cerrar</button>
            </div>
        </aside>

        <div class="header-user-menu" id="headerUserMenu">
            <button type="button" class="header-avatar" id="headerUserMenuToggle" aria-haspopup="true" aria-expanded="false" aria-controls="headerUserMenuDropdown" title="Opciones de usuario - {{ session('user.name') ?? session('user.email') ?? 'Usuario' }}">
                {{ strtoupper(substr(session('user.name') ?? session('user.email') ?? 'U', 0, 2)) }}
            </button>

            <div class="header-user-dropdown" id="headerUserMenuDropdown" role="menu" aria-labelledby="headerUserMenuToggle">
                @if(\App\Support\Rbac\PermissionMatrix::allows(session('user.permissions', []), 'perfil', 'ver'))
                    <a href="{{ route('perfil.index') }}" class="header-user-dropdown-item" role="menuitem">
                        Ver perfil
                    </a>
                @endif

                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="header-user-dropdown-item is-danger" role="menuitem">
                        Cerrar sesión
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
<script>
(function() {
    const pollUrl = '{{ route("notificaciones.poll") }}';
    const markAllReadUrl = '{{ route("notificaciones.mark-all-read") }}';
    const csrfToken = '{{ csrf_token() }}';
    const debugEnabled = @json((bool) config('app.debug'));
    let lastCount = Number({{ $headerUnreadCount ?? 0 }});

    if (!pollUrl || pollUrl === '') return;

    function debugLog(label, payload) {
        if (!debugEnabled || typeof console === 'undefined' || typeof console.debug !== 'function') {
            return;
        }

        console.debug('[header-notificaciones] ' + label, payload || {});
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatDateLabel(isoValue) {
        if (!isoValue) {
            return '';
        }

        var date = new Date(isoValue);
        if (Number.isNaN(date.getTime())) {
            return '';
        }

        try {
            return date.toLocaleString('es-PE', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        } catch (error) {
            return date.toISOString();
        }
    }

    function ensureBadgeElement() {
        var button = document.getElementById('headerNotifToggle');
        if (!button) {
            return null;
        }

        var badge = button.querySelector('.header-notif-count');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'header-notif-count';
            badge.style.display = 'none';
            button.appendChild(badge);
        }

        return badge;
    }

    function updateTopCount(unreadCount) {
        var topCount = document.querySelector('.header-notif-top-count');
        if (!topCount) {
            var topContainer = document.querySelector('.header-notif-top');
            if (!topContainer) {
                return;
            }

            topCount = document.createElement('span');
            topCount.className = 'header-notif-top-count';
            topContainer.appendChild(topCount);
        }

        if (unreadCount > 0) {
            topCount.textContent = String(unreadCount) + ' no leidas';
            topCount.style.display = '';
        } else {
            topCount.textContent = '';
            topCount.style.display = 'none';
        }
    }

    function updateMarkAllReadAction(unreadCount) {
        var actionsContainer = document.querySelector('.header-notif-panel-actions');
        if (!actionsContainer) {
            return;
        }

        var form = actionsContainer.querySelector('form');
        if (!form && markAllReadUrl) {
            form = document.createElement('form');
            form.method = 'POST';
            form.action = markAllReadUrl;
            form.innerHTML = '<input type="hidden" name="_token" value="' + escapeHtml(csrfToken) + '">' +
                '<button type="submit" class="header-user-dropdown-item">Marcar todas leidas</button>';
            actionsContainer.appendChild(form);
        }

        if (!form) {
            return;
        }

        form.style.display = unreadCount > 0 ? '' : 'none';
    }

    function renderPostAction(url, label) {
        if (!url) {
            return '';
        }

        return '<form method="POST" action="' + escapeHtml(url) + '">' +
            '<input type="hidden" name="_token" value="' + escapeHtml(csrfToken) + '">' +
            '<button type="submit" class="header-user-dropdown-item">' + escapeHtml(label) + '</button>' +
            '</form>';
    }

    function renderOpenAction(url, label) {
        if (!url) {
            return '';
        }

        return '<a href="' + escapeHtml(url) + '" class="header-user-dropdown-item">' + escapeHtml(label || 'Abrir') + '</a>';
    }

    function renderItems(items) {
        var list = document.querySelector('.header-notif-list');
        if (!list) {
            return;
        }

        if (!Array.isArray(items) || items.length === 0) {
            list.innerHTML = '<div class="header-notif-empty">No tienes notificaciones.</div>';
            return;
        }

        list.innerHTML = items.map(function(item) {
            var moduleName = String(item.module || '').toUpperCase();
            var priority = String(item.priority || '').toUpperCase();
            var dateLabel = formatDateLabel(item.created_at || item.occurred_at);
            var actions = '';

            if (item.status === 'UNREAD') {
                actions += renderPostAction(item.mark_read_url, 'Leida');
            }

            if (item.status !== 'ARCHIVED') {
                actions += renderPostAction(item.archive_url, 'Archivar');
            }

            actions += renderOpenAction(item.action_url, item.action_label);

            return '<div class="header-notif-item ' + (item.status === 'UNREAD' ? 'is-unread' : '') + '">' +
                '<div class="header-notif-item-title">' + escapeHtml(item.title || '') + '</div>' +
                '<div class="header-notif-item-message">' + escapeHtml(item.message || '') + '</div>' +
                '<div class="header-notif-item-meta">' +
                    '<span>' + escapeHtml(moduleName) + '</span>' +
                    '<span>' + escapeHtml(priority) + '</span>' +
                    '<span>' + escapeHtml(dateLabel) + '</span>' +
                '</div>' +
                '<div class="header-notif-item-actions">' + actions + '</div>' +
                '</div>';
        }).join('');
    }

    function poll() {
        debugLog('poll.request', { url: pollUrl, at: new Date().toISOString() });

        fetch(pollUrl + '?_t=' + Date.now(), {
            method: 'GET',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
        .then(function(r) {
            if (!r.ok) {
                throw new Error('HTTP ' + r.status);
            }

            var contentType = r.headers.get('content-type') || '';
            if (contentType.indexOf('application/json') === -1) {
                throw new Error('Respuesta no JSON');
            }

            return r.json();
        })
        .then(function(data) {
            debugLog('poll.response', data);

            if (data && data.ok === false) {
                throw new Error(String(data.error || 'POLL_FAILED'));
            }

            var unreadCount = Number(data && data.count ? data.count : 0);
            if (unreadCount !== lastCount) {
                debugLog('poll.unread_count_changed', { from: lastCount, to: unreadCount });
            }

            lastCount = unreadCount;

            var badge = ensureBadgeElement();
            if (badge) {
                badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
                badge.style.display = unreadCount > 0 ? 'inline-flex' : 'none';
            }

            updateTopCount(unreadCount);
            updateMarkAllReadAction(unreadCount);
            renderItems(data && data.items ? data.items : []);

            debugLog('poll.unread_count_applied', { count: unreadCount });
        })
        .catch(function(error) {
            debugLog('poll.error', { message: error && error.message ? error.message : String(error) });
        });
    }

    poll();
    setInterval(poll, 5000);
})();
</script>
