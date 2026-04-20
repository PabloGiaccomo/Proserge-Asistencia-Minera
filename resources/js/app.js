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
