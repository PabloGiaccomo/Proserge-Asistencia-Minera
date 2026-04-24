import './bootstrap';

document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const mobileMenuToggle = document.getElementById('menuToggleBtn');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const mobileBreakpoint = 1023;
    
    if (!sidebar) {
        return;
    }

    const isMobileViewport = function() {
        return window.innerWidth <= mobileBreakpoint;
    };

    const setSidebarState = function(isOpen) {
        sidebar.classList.toggle('open', isOpen);

        if (sidebarOverlay) {
            sidebarOverlay.classList.toggle('visible', isOpen);
        }

        if (menuToggle) {
            menuToggle.setAttribute('aria-expanded', String(isOpen));
        }

        if (mobileMenuToggle) {
            mobileMenuToggle.setAttribute('aria-expanded', String(isOpen));
        }
    };

    const toggleSidebar = function() {
        if (!isMobileViewport()) {
            return;
        }

        setSidebarState(!sidebar.classList.contains('open'));
    };

    if (menuToggle) {
        menuToggle.addEventListener('click', toggleSidebar);
    }

    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', toggleSidebar);
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function() {
            setSidebarState(false);
        });
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && sidebar.classList.contains('open')) {
            setSidebarState(false);
        }
    });

    window.addEventListener('resize', function() {
        if (!isMobileViewport()) {
            setSidebarState(false);
        }
    });

    sidebar.addEventListener('click', function(event) {
        const navItem = event.target.closest('.nav-item');

        if (navItem && isMobileViewport()) {
            setSidebarState(false);
        }
    });
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

    const setExpandedState = function(isExpanded) {
        panelBody.style.display = isExpanded ? 'block' : 'none';

        if (iconElement) {
            iconElement.textContent = isExpanded ? expandedIcon : collapsedIcon;
        }

        toggleButton.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
        toggleButton.setAttribute('aria-label', isExpanded ? expandedLabel : collapsedLabel);
        toggleButton.setAttribute('title', isExpanded ? expandedLabel : collapsedLabel);
    };

    const initialExpanded = panelBody.style.display !== 'none';
    setExpandedState(initialExpanded);

    toggleButton.addEventListener('click', function() {
        const isExpanded = panelBody.style.display !== 'none';
        setExpandedState(!isExpanded);
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
