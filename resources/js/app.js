import './bootstrap';

document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const mobileMenuToggle = document.getElementById('menuToggleBtn');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const appLayout = document.querySelector('.app-layout');
    const sidebarCollapseToggle = document.getElementById('sidebarCollapseToggle');
    const sidebarResizeHandle = document.getElementById('sidebarResizeHandle');
    const userMenu = document.getElementById('headerUserMenu');
    const userMenuToggle = document.getElementById('headerUserMenuToggle');
    const notifMenu = document.getElementById('headerNotifMenu');
    const notifMenuToggle = document.getElementById('headerNotifToggle');
    const notifPanel = document.getElementById('headerNotifPanel');
    const notifBackdrop = document.getElementById('headerNotifBackdrop');
    const notifCloseButton = document.getElementById('headerNotifClose');
    const mobileBreakpoint = 1023;

    const closeUserMenu = function() {
        if (!userMenu || !userMenuToggle) {
            return;
        }

        userMenu.classList.remove('is-open');
        userMenuToggle.setAttribute('aria-expanded', 'false');
    };

    const closeNotifMenu = function() {
        if (!notifMenu || !notifMenuToggle) {
            return;
        }

        notifMenu.classList.remove('is-open');
        notifMenuToggle.setAttribute('aria-expanded', 'false');
        if (notifPanel) {
            notifPanel.classList.remove('is-open');
            notifPanel.setAttribute('aria-hidden', 'true');
        }
        if (notifBackdrop) {
            notifBackdrop.classList.remove('is-visible');
            notifBackdrop.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove('notif-panel-open');
    };

    if (userMenu && userMenuToggle) {
        userMenuToggle.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            const willOpen = !userMenu.classList.contains('is-open');
            userMenu.classList.remove('is-open');
            userMenuToggle.setAttribute('aria-expanded', 'false');
            closeNotifMenu();
            if (willOpen) {
                userMenu.classList.add('is-open');
                userMenuToggle.setAttribute('aria-expanded', 'true');
            }
        });
    }

    if (notifMenu && notifMenuToggle) {
        notifMenuToggle.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            const willOpen = !notifMenu.classList.contains('is-open');
            closeNotifMenu();
            closeUserMenu();
            if (willOpen) {
                notifMenu.classList.add('is-open');
                notifMenuToggle.setAttribute('aria-expanded', 'true');
                if (notifPanel) {
                    notifPanel.classList.add('is-open');
                    notifPanel.setAttribute('aria-hidden', 'false');
                }
                if (notifBackdrop) {
                    notifBackdrop.classList.add('is-visible');
                    notifBackdrop.setAttribute('aria-hidden', 'false');
                }
                document.body.classList.add('notif-panel-open');
            }
        });
    }

    if (notifCloseButton) {
        notifCloseButton.addEventListener('click', function() {
            closeNotifMenu();
        });
    }

    if (notifBackdrop) {
        notifBackdrop.addEventListener('click', function() {
            closeNotifMenu();
        });
    }

    document.addEventListener('click', function(event) {
        if (!event.target.closest('#headerUserMenu')) {
            closeUserMenu();
        }

        if (!event.target.closest('#headerNotifMenu') && !event.target.closest('#headerNotifPanel')) {
            closeNotifMenu();
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeUserMenu();
            closeNotifMenu();
        }
    });

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('open_notifications') === '1' && notifMenu && notifMenuToggle) {
        notifMenu.classList.add('is-open');
        notifMenuToggle.setAttribute('aria-expanded', 'true');
        if (notifPanel) {
            notifPanel.classList.add('is-open');
            notifPanel.setAttribute('aria-hidden', 'false');
        }
        if (notifBackdrop) {
            notifBackdrop.classList.add('is-visible');
            notifBackdrop.setAttribute('aria-hidden', 'false');
        }
        document.body.classList.add('notif-panel-open');
    }
    
    if (!sidebar) {
        return;
    }

    const sidebarStateKey = 'proserge.sidebar.state.v1';
    const sidebarWidthKey = 'proserge.sidebar.width.v1';
    const minSidebarWidth = 220;
    const maxSidebarWidth = 380;

    const isMobileViewport = function() {
        return window.innerWidth <= mobileBreakpoint;
    };

    const storedSidebarState = function() {
        try {
            return window.localStorage.getItem(sidebarStateKey);
        } catch (error) {
            return null;
        }
    };

    const storeSidebarState = function(isOpen) {
        try {
            if (isMobileViewport()) {
                if (!isOpen) {
                    window.localStorage.setItem(sidebarStateKey, 'mobile:closed');
                }

                return;
            }

            window.localStorage.setItem(sidebarStateKey, 'desktop:' + (isOpen ? 'open' : 'closed'));
        } catch (error) {
            // Keep the sidebar usable even when storage is unavailable.
        }
    };

    const clampSidebarWidth = function(width) {
        return Math.max(minSidebarWidth, Math.min(maxSidebarWidth, Number(width) || 280));
    };

    const applySidebarWidth = function(width) {
        const nextWidth = clampSidebarWidth(width);
        document.documentElement.style.setProperty('--sidebar-width', nextWidth + 'px');
        try {
            window.localStorage.setItem(sidebarWidthKey, String(nextWidth));
        } catch (error) {
            // no-op
        }
    };

    const restoreSidebarWidth = function() {
        try {
            const storedWidth = window.localStorage.getItem(sidebarWidthKey);
            if (storedWidth) {
                applySidebarWidth(storedWidth);
            }
        } catch (error) {
            // no-op
        }
    };

    const setSidebarState = function(isOpen, persist) {
        const mobile = isMobileViewport();
        sidebar.classList.toggle('open', mobile && isOpen);
        document.body.classList.toggle('sidebar-mobile-open', mobile && isOpen);

        if (appLayout) {
            appLayout.classList.toggle('sidebar-hidden', !isOpen);
        }

        if (sidebarOverlay) {
            sidebarOverlay.classList.toggle('visible', mobile && isOpen);
        }

        if (menuToggle) {
            menuToggle.setAttribute('aria-expanded', String(isOpen));
            menuToggle.setAttribute('aria-label', isOpen ? 'Ocultar menu' : 'Mostrar menu');
            menuToggle.setAttribute('title', isOpen ? 'Ocultar menu' : 'Mostrar menu');
        }

        if (mobileMenuToggle) {
            mobileMenuToggle.setAttribute('aria-expanded', String(isOpen));
        }

        if (sidebarCollapseToggle) {
            sidebarCollapseToggle.setAttribute('aria-expanded', String(isOpen));
        }

        if (persist) {
            storeSidebarState(isOpen);
        }
    };

    const toggleSidebar = function() {
        const isOpen = isMobileViewport()
            ? sidebar.classList.contains('open')
            : !(appLayout && appLayout.classList.contains('sidebar-hidden'));

        setSidebarState(!isOpen, true);
    };

    let lastMobileScrollTouchAt = 0;

    window.addEventListener('touchmove', function() {
        if (isMobileViewport()) {
            lastMobileScrollTouchAt = Date.now();
        }
    }, { passive: true });

    const registerSidebarToggle = function(button) {
        if (!button) {
            return;
        }

        let touchStartX = 0;
        let touchStartY = 0;
        let touchMoved = false;
        let ignoreNextClick = false;

        button.addEventListener('touchstart', function(event) {
            if (!isMobileViewport() || !event.touches || event.touches.length !== 1) {
                return;
            }

            touchMoved = false;
            touchStartX = event.touches[0].clientX;
            touchStartY = event.touches[0].clientY;
        }, { passive: true });

        button.addEventListener('touchmove', function(event) {
            if (!isMobileViewport() || !event.touches || event.touches.length !== 1) {
                return;
            }

            const deltaX = Math.abs(event.touches[0].clientX - touchStartX);
            const deltaY = Math.abs(event.touches[0].clientY - touchStartY);

            if (deltaY > 8 || deltaX > 12) {
                touchMoved = true;
            }
        }, { passive: true });

        button.addEventListener('touchend', function() {
            if (!isMobileViewport() || !touchMoved) {
                return;
            }

            ignoreNextClick = true;
            window.setTimeout(function() {
                ignoreNextClick = false;
            }, 420);
        }, { passive: true });

        button.addEventListener('click', function(event) {
            if (ignoreNextClick || (isMobileViewport() && Date.now() - lastMobileScrollTouchAt < 450)) {
                event.preventDefault();
                event.stopPropagation();
                ignoreNextClick = false;
                return;
            }

            toggleSidebar();
        });
    };

    const applyResponsiveSidebarDefault = function() {
        if (isMobileViewport()) {
            setSidebarState(sidebar.classList.contains('open'), false);
            return;
        }

        const stored = storedSidebarState();
        const defaultOpen = true;
        const statePrefix = 'desktop:';
        const scopedStored = stored && stored.startsWith(statePrefix) ? stored.replace(statePrefix, '') : null;
        const nextOpen = scopedStored === 'open' ? true : (scopedStored === 'closed' ? false : defaultOpen);
        setSidebarState(nextOpen, false);
    };

    restoreSidebarWidth();
    applyResponsiveSidebarDefault();

    registerSidebarToggle(menuToggle);
    registerSidebarToggle(mobileMenuToggle);

    if (sidebarCollapseToggle) {
        sidebarCollapseToggle.addEventListener('click', function() {
            setSidebarState(false, true);
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function() {
            setSidebarState(false);
        });
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && (sidebar.classList.contains('open') || !(appLayout && appLayout.classList.contains('sidebar-hidden')))) {
            setSidebarState(false, true);
        }
    });

    window.addEventListener('resize', function() {
        applyResponsiveSidebarDefault();
    });

    sidebar.addEventListener('click', function(event) {
        const navItem = event.target.closest('.nav-item');

        if (navItem && isMobileViewport()) {
            setSidebarState(false, false);
        }
    });

    if (sidebarResizeHandle) {
        let isResizingSidebar = false;

        sidebarResizeHandle.addEventListener('pointerdown', function(event) {
            if (isMobileViewport() || (appLayout && appLayout.classList.contains('sidebar-hidden'))) {
                return;
            }

            isResizingSidebar = true;
            document.body.classList.add('sidebar-resizing');
            sidebarResizeHandle.setPointerCapture(event.pointerId);
            event.preventDefault();
        });

        sidebarResizeHandle.addEventListener('pointermove', function(event) {
            if (!isResizingSidebar) {
                return;
            }

            applySidebarWidth(event.clientX);
        });

        const stopSidebarResize = function(event) {
            if (!isResizingSidebar) {
                return;
            }

            isResizingSidebar = false;
            document.body.classList.remove('sidebar-resizing');
            if (event && sidebarResizeHandle.hasPointerCapture(event.pointerId)) {
                sidebarResizeHandle.releasePointerCapture(event.pointerId);
            }
        };

        sidebarResizeHandle.addEventListener('pointerup', stopSidebarResize);
        sidebarResizeHandle.addEventListener('pointercancel', stopSidebarResize);
    }
});

// Modal Functions
window.openModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
};

window.closeModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
};

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal[style*="flex"], .modal[style="flex"]').forEach(function(modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
});

window.ProsergeUI = window.ProsergeUI || {};

window.ProsergeUI.initCollapsiblePanel = function(options) {
    const toggleButton = document.getElementById(options.toggleButtonId);
    const panelBody = document.getElementById(options.panelBodyId);
    const iconElement = options.iconElementId ? document.getElementById(options.iconElementId) : null;

    if (!toggleButton || !panelBody) {
        return null;
    }

    const expandedIcon = options.expandedIcon || '▲';
    const collapsedIcon = options.collapsedIcon || '▼';
    const expandedLabel = options.expandedLabel || 'Ocultar filtros';
    const collapsedLabel = options.collapsedLabel || 'Mostrar filtros';
    const storageKey = options.storageKey || null;

    const readStoredExpanded = function() {
        if (!storageKey || !window.localStorage) {
            return null;
        }

        try {
            const storedValue = window.localStorage.getItem(storageKey);

            if (storedValue === 'expanded' || storedValue === 'true') {
                return true;
            }

            if (storedValue === 'collapsed' || storedValue === 'false') {
                return false;
            }
        } catch (error) {
            return null;
        }

        return null;
    };

    const writeStoredExpanded = function(isExpanded) {
        if (!storageKey || !window.localStorage) {
            return;
        }

        try {
            window.localStorage.setItem(storageKey, isExpanded ? 'expanded' : 'collapsed');
        } catch (error) {
            // Ignore storage errors so the filter toggle keeps working normally.
        }
    };

    const setExpandedState = function(isExpanded) {
        panelBody.style.display = isExpanded ? 'block' : 'none';

        if (iconElement) {
            iconElement.textContent = isExpanded ? expandedIcon : collapsedIcon;
        }

        toggleButton.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
        toggleButton.setAttribute('aria-label', isExpanded ? expandedLabel : collapsedLabel);
        toggleButton.setAttribute('title', isExpanded ? expandedLabel : collapsedLabel);
    };

    const storedExpanded = readStoredExpanded();
    const initialExpanded = storedExpanded !== null ? storedExpanded : panelBody.style.display !== 'none';
    setExpandedState(initialExpanded);

    toggleButton.addEventListener('click', function() {
        const isExpanded = panelBody.style.display !== 'none';
        const nextExpanded = !isExpanded;
        setExpandedState(nextExpanded);
        writeStoredExpanded(nextExpanded);
    });

    return { setExpandedState };
};

window.ProsergeUI.initClientPagination = function(options) {
    const items = Array.from(document.querySelectorAll(options.itemSelector));
    if (items.length === 0) {
        return null;
    }

    const searchInput = options.searchInputSelector ? document.querySelector(options.searchInputSelector) : null;
    const pageSizeSelect = document.getElementById(options.pageSizeSelectId);
    const paginationInfo = document.getElementById(options.paginationInfoId);
    const paginationWrap = document.getElementById(options.paginationWrapId);
    const pageJumpInput = options.pageJumpInputId ? document.getElementById(options.pageJumpInputId) : null;
    const pageJumpButton = options.pageJumpButtonId ? document.getElementById(options.pageJumpButtonId) : null;

    let currentPage = 1;
    let pageSize = Number(options.defaultPageSize || 9);

    const normalizeText = function(value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    };

    const buildSearchableText = function(item) {
        const data = item.dataset || {};
        const fromDataAttrs = [data.nombre, data.dni, data.puesto, data.contrato, data.minas].join(' ');
        return normalizeText(fromDataAttrs || item.innerText || '');
    };

    const resolveSearchText = function() {
        return searchInput ? normalizeText(searchInput.value) : '';
    };

    const getFilteredItems = function() {
        const term = resolveSearchText();
        if (!term) {
            return items;
        }

        const tokens = term.split(' ').filter(function(token) {
            return token.length > 0;
        });

        return items.filter(function(item) {
            const searchableText = buildSearchableText(item);
            if (!searchableText) {
                return false;
            }

            if (searchableText.includes(term)) {
                return true;
            }

            return tokens.every(function(token) {
                return searchableText.includes(token);
            });
        });
    };

    const renderPagination = function(totalPages) {
        if (!paginationWrap) {
            return;
        }

        if (totalPages <= 1) {
            paginationWrap.innerHTML = '';
            return;
        }

        const btnClass = options.paginationButtonClass || 'pager-btn';
        const maxVisible = Number(options.maxVisiblePages || 7);

        const buildVisiblePages = function() {
            if (totalPages <= maxVisible) {
                return Array.from({ length: totalPages }, function(_, index) {
                    return index + 1;
                });
            }

            const pages = new Set([1, totalPages]);
            const around = Math.max(1, Math.floor((maxVisible - 3) / 2));
            const start = Math.max(2, currentPage - around);
            const end = Math.min(totalPages - 1, currentPage + around);

            for (let page = start; page <= end; page++) {
                pages.add(page);
            }

            const ordered = Array.from(pages).sort(function(a, b) { return a - b; });
            const withEllipsis = [];

            ordered.forEach(function(page, index) {
                if (index > 0) {
                    const previous = ordered[index - 1];
                    if (page - previous > 1) {
                        withEllipsis.push('ellipsis');
                    }
                }

                withEllipsis.push(page);
            });

            return withEllipsis;
        };

        let html = '';
        html += '<button type="button" class="' + btnClass + '" data-page="' + (currentPage - 1) + '" ' + (currentPage === 1 ? 'disabled' : '') + ' aria-label="Pagina anterior">&#x2039;</button>';

        buildVisiblePages().forEach(function(page) {
            if (page === 'ellipsis') {
                html += '<span class="' + btnClass + ' personal-pager-ellipsis" aria-hidden="true">...</span>';
                return;
            }

            html += '<button type="button" class="' + btnClass + ' ' + (page === currentPage ? 'active' : '') + '" data-page="' + page + '">' + page + '</button>';
        });

        html += '<button type="button" class="' + btnClass + '" data-page="' + (currentPage + 1) + '" ' + (currentPage === totalPages ? 'disabled' : '') + ' aria-label="Pagina siguiente">&#x203A;</button>';
        paginationWrap.innerHTML = html;

        if (pageJumpInput) {
            pageJumpInput.min = '1';
            pageJumpInput.max = String(totalPages);
            pageJumpInput.placeholder = '1-' + totalPages;
            pageJumpInput.value = String(currentPage);
        }
    };

    const goToPage = function(targetPage) {
        const parsed = parseInt(String(targetPage || ''), 10);
        if (Number.isNaN(parsed)) {
            return;
        }

        const filteredItems = getFilteredItems();
        const totalPages = Math.max(1, Math.ceil(filteredItems.length / pageSize));
        const nextPage = Math.min(Math.max(parsed, 1), totalPages);

        currentPage = nextPage;
        render();
    };

    const render = function() {
        const filteredItems = getFilteredItems();
        const totalItems = filteredItems.length;
        const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));

        if (currentPage > totalPages) {
            currentPage = totalPages;
        }

        items.forEach(function(item) {
            item.style.display = 'none';
        });

        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;
        const displayStyle = options.itemDisplayStyle || '';

        filteredItems.slice(start, end).forEach(function(item) {
            item.style.display = displayStyle;
        });

        if (paginationInfo) {
            if (totalItems === 0) {
                paginationInfo.textContent = '0 resultados';
            } else {
                paginationInfo.textContent = 'Mostrando ' + (start + 1) + '-' + Math.min(end, totalItems) + ' de ' + totalItems;
            }
        }

        const countBadgeId = options.countBadgeId || null;
        const countBadge = countBadgeId ? document.getElementById(countBadgeId) : null;
        if (countBadge) {
            countBadge.textContent = totalItems + ' trabajadores';
        }

        renderPagination(totalPages);
    };

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            currentPage = 1;
            render();
        });
    }

    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function() {
            const parsed = parseInt(pageSizeSelect.value, 10);
            pageSize = Number.isNaN(parsed) ? Number(options.defaultPageSize || 9) : parsed;
            currentPage = 1;
            render();
        });
    }

    if (paginationWrap) {
        paginationWrap.addEventListener('click', function(event) {
            const btn = event.target.closest('button[data-page]');
            if (!btn || btn.disabled) {
                return;
            }

            const page = parseInt(btn.dataset.page, 10);
            if (Number.isNaN(page) || page < 1) {
                return;
            }

            goToPage(page);
        });
    }

    if (pageJumpButton && pageJumpInput) {
        pageJumpButton.addEventListener('click', function() {
            goToPage(pageJumpInput.value);
        });

        pageJumpInput.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                goToPage(pageJumpInput.value);
            }
        });
    }

    render();

    return {
        render,
        resetPage: function() {
            currentPage = 1;
            render();
        },
    };
};
