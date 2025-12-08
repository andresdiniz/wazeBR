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
    document.addEventListener('click', handleGlobalClicks);
    window.addEventListener('scroll', handleScroll);
    window.addEventListener('resize', handleResize);
    window.addEventListener('orientationchange', handleOrientationChange);
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
    
    if (darkMode === 'enabled') {
        document.body.classList.add('dark-mode');
        if (darkModeToggle) darkModeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

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
    
    // Toggle desktop (Colapsar/Expandir)
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            document.body.classList.toggle('sidebar-toggled');
            setupSidebarTooltips();
            
            // Salvar preferência
            const isToggled = document.body.classList.contains('sidebar-toggled');
            localStorage.setItem('sidebar-toggled', isToggled);
        });
    }
    
    // Toggle mobile (Mostrar/Esconder)
    if (sidebarToggleTop) {
        sidebarToggleTop.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleMobileSidebar();
        });
    }
    
    // Overlay mobile
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', () => {
            closeMobileSidebar();
        });
    }

    // Fechar sidebar ao clicar em links (mobile)
    document.querySelectorAll('.sidebar .nav-link, .sidebar .collapse-item').forEach(function(element) {
        element.addEventListener('click', function(e) {
            // Não fechar se for um botão de collapse
            if (this.hasAttribute('data-bs-toggle')) {
                return;
            }
            
            // Fechar apenas em mobile
            if (isMobile()) {
                closeMobileSidebar();
            }
        });
    });
    
    // Restaurar preferência de sidebar (desktop)
    if (!isMobile()) {
        const sidebarToggled = localStorage.getItem('sidebar-toggled');
        if (sidebarToggled === 'true') {
            document.body.classList.add('sidebar-toggled');
        }
    }
    
    // Inicializar tooltips
    setupSidebarTooltips();
}

// Funções auxiliares para sidebar mobile
function toggleMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    if (sidebar && overlay) {
        const isOpen = sidebar.classList.contains('show');
        
        if (isOpen) {
            closeMobileSidebar();
        } else {
            openMobileSidebar();
        }
    }
}

function openMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    if (sidebar) sidebar.classList.add('show');
    if (overlay) overlay.classList.add('show');
    
    // Prevenir scroll do body
    document.body.style.overflow = 'hidden';
}

function closeMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    if (sidebar) sidebar.classList.remove('show');
    if (overlay) overlay.classList.remove('show');
    
    // Restaurar scroll do body
    document.body.style.overflow = '';
}

function isMobile() {
    return window.innerWidth < 768;
}

// ==================== SIDEBAR TOOLTIPS ====================
function setupSidebarTooltips() {
    const isToggled = document.body.classList.contains('sidebar-toggled');
    
    // Só ativa tooltips em desktop E com a sidebar colapsada
    if (!isToggled || isMobile()) {
        removeAllTooltips();
        return;
    }

    // Adiciona listeners para criar os tooltips
    document.querySelectorAll('.sidebar .nav-link, .sidebar .collapse-item').forEach(link => {
        link.removeEventListener('mouseenter', createAndShowTooltip);
        link.removeEventListener('mouseleave', hideAndRemoveTooltip);

        const tooltipText = link.getAttribute('data-tooltip') || link.querySelector('span')?.textContent.trim();
        if (tooltipText) {
            link.addEventListener('mouseenter', createAndShowTooltip);
            link.addEventListener('mouseleave', hideAndRemoveTooltip);
        }
    });
}

function createAndShowTooltip(e) {
    if (isMobile()) return;
    
    const link = e.currentTarget;
    const tooltipText = link.getAttribute('data-tooltip') || link.querySelector('span')?.textContent.trim();
    if (!tooltipText) return;

    removeAllTooltips();

    const tooltip = document.createElement('div');
    tooltip.classList.add('sidebar-tooltip');
    tooltip.textContent = tooltipText;
    document.body.appendChild(tooltip);

    const linkRect = link.getBoundingClientRect();
    const sidebar = document.querySelector('.sidebar');
    const sidebarRect = sidebar.getBoundingClientRect();
    const tooltipHeight = tooltip.offsetHeight;
    
    tooltip.style.top = `${linkRect.top + linkRect.height / 2 - tooltipHeight / 2}px`;
    tooltip.style.left = `${sidebarRect.right + 10}px`;

    setTimeout(() => {
        tooltip.classList.add('show');
    }, 50);

    link.dataset.tooltipRef = true;
}

function hideAndRemoveTooltip(e) {
    const link = e.currentTarget;
    const tooltip = document.querySelector('.sidebar-tooltip.show');
    if (tooltip && link.dataset.tooltipRef) {
        tooltip.classList.remove('show');
        setTimeout(() => {
            tooltip.remove();
        }, 300);
        delete link.dataset.tooltipRef;
    }
}

function removeAllTooltips() {
    document.querySelectorAll('.sidebar-tooltip').forEach(tip => tip.remove());
    document.querySelectorAll('.sidebar .nav-link, .sidebar .collapse-item').forEach(link => {
        delete link.dataset.tooltipRef;
    });
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
        
        // Fechar dropdown ao clicar em item (mobile)
        dropdown.addEventListener('click', function(e) {
            if (isMobile() && e.target.classList.contains('dropdown-item')) {
                const dropdownToggle = this.querySelector('[data-bs-toggle="dropdown"]');
                if (dropdownToggle) {
                    const bsDropdown = bootstrap.Dropdown.getInstance(dropdownToggle);
                    if (bsDropdown) {
                        setTimeout(() => bsDropdown.hide(), 150);
                    }
                }
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
        document.querySelectorAll('.dropdown-menu.show').forEach(function(menu) {
            const dropdown = menu.closest('.dropdown');
            if (dropdown) {
                const toggle = dropdown.querySelector('.dropdown-toggle');
                if (toggle) {
                    const bsDropdown = bootstrap.Dropdown.getInstance(toggle);
                    if (bsDropdown) bsDropdown.hide();
                }
            }
        });
    }
    
    // Fechar sidebar mobile ao clicar fora
    if (isMobile()) {
        const sidebar = document.querySelector('.sidebar');
        const sidebarToggle = document.getElementById('sidebarToggleTop');
        
        if (sidebar && sidebar.classList.contains('show')) {
            if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                closeMobileSidebar();
            }
        }
    }
}

function handleScroll() {
    // Efeitos de parallax
    const scrolled = window.pageYOffset;
    const parallaxElements = document.querySelectorAll('[data-parallax]');
    
    parallaxElements.forEach(element => {
        const speed = element.dataset.parallaxSpeed || 0.5;
        element.style.transform = `translateY(${scrolled * speed}px)`;
    });
}

function handleResize() {
    // Fechar sidebar mobile ao mudar para desktop
    if (!isMobile()) {
        closeMobileSidebar();
    }
    
    // Reconfigurar tooltips
    setupSidebarTooltips();
    
    // Fechar dropdowns abertos
    document.querySelectorAll('.dropdown-menu.show').forEach(function(menu) {
        const dropdown = menu.closest('.dropdown');
        if (dropdown) {
            const toggle = dropdown.querySelector('.dropdown-toggle');
            if (toggle) {
                const bsDropdown = bootstrap.Dropdown.getInstance(toggle);
                if (bsDropdown) bsDropdown.hide();
            }
        }
    });
}

function handleOrientationChange() {
    // Fechar sidebar ao mudar orientação
    if (isMobile()) {
        closeMobileSidebar();
    }
    
    // Reconfigurar após mudança de orientação
    setTimeout(() => {
        handleResize();
    }, 300);
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
console.log('%c📊 Versão: 2.0 | Bootstrap 5.3.3 | Design Moderno e Responsivo', 'color: #858796; font-size: 14px;');

// Expor funções globalmente
window.showNotification = showNotification;
window.confirmAction = confirmAction;
window.isMobile = isMobile;