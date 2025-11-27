// ==================== INICIALIZAÇÃO DO SISTEMA ====================
document.addEventListener('DOMContentLoaded', function() {
    initializeSystem();
    setupEventListeners();
    startAutoRefresh();
});

// ==================== FUNÇÕES DE INICIALIZAÇÃO ====================
function initializeSystem() {
    initializeTooltips();
    initializeDateTime();
    initializeDarkMode();
    initializeScrollToTop();
    initializeSidebar();
    initializeDropdowns();
    highlightActivePage();
}

function setupEventListeners() {
    // Event listeners para elementos interativos
    document.addEventListener('click', handleGlobalClicks);
    window.addEventListener('scroll', handleScroll);
    window.addEventListener('resize', handleResize);
}

// ==================== TOOLTIPS ====================
function initializeTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// ==================== DATA E HORA ====================
function initializeDateTime() {
    function updateDateTime() {
        const now = new Date();
        const options = { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        };
        const dateTimeString = now.toLocaleDateString('pt-BR', options);
        const element = document.getElementById('currentDateTime');
        if (element) {
            element.textContent = dateTimeString.charAt(0).toUpperCase() + dateTimeString.slice(1);
        }
    }

    updateDateTime();
    setInterval(updateDateTime, 1000);
}

// ==================== DARK MODE ====================
function initializeDarkMode() {
    const darkModeToggle = document.getElementById('darkModeToggle');
    const darkMode = localStorage.getItem('darkMode');
    
    // Aplicar modo escuro salvo
    if (darkMode === 'enabled') {
        document.body.classList.add('dark-mode');
        if (darkModeToggle) darkModeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    // Event listener para o toggle
    if (darkModeToggle) {
        darkModeToggle.addEventListener('click', toggleDarkMode);
    }
}

function toggleDarkMode() {
    const darkModeToggle = document.getElementById('darkModeToggle');
    document.body.classList.toggle('dark-mode');
    
    if (document.body.classList.contains('dark-mode')) {
        localStorage.setItem('darkMode', 'enabled');
        if (darkModeToggle) darkModeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        showNotification('Modo escuro ativado', 'success');
    } else {
        localStorage.setItem('darkMode', 'disabled');
        if (darkModeToggle) darkModeToggle.innerHTML = '<i class="fas fa-moon"></i>';
        showNotification('Modo claro ativado', 'info');
    }
}

// ==================== SCROLL TO TOP ====================
function initializeScrollToTop() {
    const scrollTopBtn = document.getElementById('scrollTopBtn');
    
    if (scrollTopBtn) {
        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 300) {
                scrollTopBtn.classList.add('show');
            } else {
                scrollTopBtn.classList.remove('show');
            }
        });

        scrollTopBtn.addEventListener('click', function(e) {
            e.preventDefault();
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
}

// ==================== SIDEBAR ====================
function initializeSidebar() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarToggleTop = document.getElementById('sidebarToggleTop');
    const sidebar = document.querySelector('.sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    
    // Toggle desktop
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            document.body.classList.toggle('sidebar-toggled');
        });
    }
    
    // Toggle mobile
    if (sidebarToggleTop) {
        sidebarToggleTop.addEventListener('click', () => {
            document.body.classList.toggle('sidebar-toggled');
            if (sidebar) sidebar.classList.toggle('show');
            if (sidebarOverlay) sidebarOverlay.classList.toggle('show');
        });
    }
    
    // Overlay mobile
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', () => {
            document.body.classList.remove('sidebar-toggled');
            if (sidebar) sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
        });
    }

    // Fechar sidebar ao clicar em link (mobile)
    if (window.innerWidth < 768) {
        document.querySelectorAll('.sidebar .nav-link, .sidebar .collapse-item').forEach(function(element) {
            element.addEventListener('click', function() {
                if (!this.hasAttribute('data-bs-toggle')) {
                    document.body.classList.remove('sidebar-toggled');
                    if (sidebar) sidebar.classList.remove('show');
                    if (sidebarOverlay) sidebarOverlay.classList.remove('show');
                }
            });
        });
    }
}

// ==================== DROPDOWNS ====================
function initializeDropdowns() {
    // Animações para dropdowns
    document.querySelectorAll('.dropdown').forEach(function(dropdown) {
        dropdown.addEventListener('show.bs.dropdown', function() {
            const menu = this.querySelector('.dropdown-menu');
            if (menu) {
                menu.classList.add('animated--grow-in');
            }
        });
        
        dropdown.addEventListener('hide.bs.dropdown', function() {
            const menu = this.querySelector('.dropdown-menu');
            if (menu) {
                menu.classList.remove('animated--grow-in');
            }
        });
    });
}

// ==================== PÁGINA ATIVA ====================
function highlightActivePage() {
    const currentPath = window.location.pathname;
    document.querySelectorAll('.sidebar .nav-link, .sidebar .collapse-item').forEach(function(link) {
        const href = link.getAttribute('href');
        if (href && currentPath.includes(href)) {
            link.classList.add('active');
            
            // Expandir collapse se estiver dentro
            const collapse = link.closest('.collapse');
            if (collapse) {
                collapse.classList.add('show');
            }
        }
    });
}

// ==================== AUTO-REFRESH ====================
function startAutoRefresh() {
    const currentUrl = window.location.pathname;
    const validPages = ['home', 'dashboard', 'routes', 'irregularidades'];
    const refreshInterval = 180000; // 3 minutos

    if (validPages.some(page => currentUrl.includes(page))) {
        console.log('🔄 Auto-refresh ativado para esta página');
        setInterval(() => {
            console.log('🔄 Atualizando página...');
            showNotification('Atualizando dados...', 'info', 2000);
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        }, refreshInterval);
    }
}

// ==================== HANDLERS GLOBAIS ====================
function handleGlobalClicks(e) {
    // Fechar dropdowns ao clicar fora
    if (!e.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown-menu').forEach(function(menu) {
            if (menu.classList.contains('show')) {
                const dropdown = menu.closest('.dropdown');
                if (dropdown) {
                    const toggle = dropdown.querySelector('.dropdown-toggle');
                    if (toggle) {
                        bootstrap.Dropdown.getInstance(toggle)?.hide();
                    }
                }
            }
        });
    }
}

function handleScroll() {
    // Efeitos de parallax ou outros efeitos de scroll
    const scrolled = window.pageYOffset;
    const parallaxElements = document.querySelectorAll('[data-parallax]');
    
    parallaxElements.forEach(element => {
        const speed = element.dataset.parallaxSpeed || 0.5;
        element.style.transform = `translateY(${scrolled * speed}px)`;
    });
}

function handleResize() {
    // Ajustes responsivos em tempo real
    if (window.innerWidth >= 768) {
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        if (sidebarOverlay) sidebarOverlay.classList.remove('show');
    }
}

// ==================== UTILITY FUNCTIONS ====================
function showNotification(message, type = 'info', duration = 3000) {
    if (typeof $.notify !== 'undefined') {
        $.notify(message, {
            position: "top right",
            className: type,
            autoHideDelay: duration,
            style: 'bootstrap'
        });
    } else if (typeof Toast !== 'undefined') {
        // Usar BS5 Toast como fallback
        const toast = new Toast({
            title: type.charAt(0).toUpperCase() + type.slice(1),
            message: message,
            type: type,
            delay: duration
        });
        toast.show();
    } else {
        console.log(`[${type.toUpperCase()}] ${message}`);
    }
}

function confirmAction(title, text, confirmText = 'Confirmar', cancelText = 'Cancelar') {
    if (typeof Swal !== 'undefined') {
        return Swal.fire({
            title: title,
            text: text,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#667eea',
            cancelButtonColor: '#858796',
            confirmButtonText: confirmText,
            cancelButtonText: cancelText,
            backdrop: true,
            allowOutsideClick: false
        });
    } else {
        return Promise.resolve({ isConfirmed: confirm(`${title}\n\n${text}`) });
    }
}

// ==================== LOADING SCREEN ====================
window.addEventListener('load', function() {
    setTimeout(() => {
        const loader = document.querySelector('.page-loading');
        if (loader) {
            loader.classList.add('hidden');
            setTimeout(() => {
                loader.remove();
                showNotification('Sistema carregado com sucesso!', 'success', 2000);
            }, 300);
        }
    }, 1000);
});

// ==================== CONSOLE INFO ====================
console.log('%c🚀 Sistema WAZE Data carregado com sucesso!', 'color: #667eea; font-size: 18px; font-weight: bold;');
console.log('%c📊 Versão: 2.0 | Bootstrap 5.3.3 | Design Moderno', 'color: #858796; font-size: 14px;');

// Expor funções globalmente
window.showNotification = showNotification;
window.confirmAction = confirmAction;