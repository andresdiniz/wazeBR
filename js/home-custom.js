/**
 * Home Dashboard - Funções Auxiliares
 * Sistema de Dados WAZE
 */

// Configurações globais
const DashboardConfig = {
    notifications: {
        position: "top right",
        autoHideDelay: 4000
    }
};

// Funções utilitárias para notificações
const WazeNotify = {
    success: function(message) {
        if (typeof $.notify !== 'undefined') {
            $.notify(message, {
                position: DashboardConfig.notifications.position,
                className: "success",
                autoHideDelay: DashboardConfig.notifications.autoHideDelay
            });
        }
    },

    error: function(message) {
        if (typeof $.notify !== 'undefined') {
            $.notify(message, {
                position: DashboardConfig.notifications.position,
                className: "error",
                autoHideDelay: DashboardConfig.notifications.autoHideDelay
            });
        }
    },

    warning: function(message) {
        if (typeof $.notify !== 'undefined') {
            $.notify(message, {
                position: DashboardConfig.notifications.position,
                className: "warn",
                autoHideDelay: DashboardConfig.notifications.autoHideDelay
            });
        }
    },

    info: function(message) {
        if (typeof $.notify !== 'undefined') {
            $.notify(message, {
                position: DashboardConfig.notifications.position,
                className: "info",
                autoHideDelay: DashboardConfig.notifications.autoHideDelay
            });
        }
    }
};

// Funções para mapa
const WazeMap = {
    getColorForLevel: function(level) {
        const colors = {
            5: '#dc3545',
            4: '#fd7e14',
            3: '#ffc107',
            2: '#28a745',
            1: '#17a2b8'
        };
        return colors[level] || '#6c757d';
    },

    getLevelText: function(level) {
        const texts = {
            5: 'Tráfego Parado',
            4: 'Tráfego Muito Lento',
            3: 'Tráfego Lento',
            2: 'Tráfego Moderado',
            1: 'Tráfego Leve'
        };
        return texts[level] || 'Desconhecido';
    },

    getBadgeClass: function(level) {
        const classes = {
            5: 'danger',
            4: 'warning',
            3: 'warning',
            2: 'success',
            1: 'info'
        };
        return classes[level] || 'secondary';
    }
};

// Função para formatar números
function formatNumber(num) {
    return num.toLocaleString('pt-BR');
}

// Função para formatar data/hora
function formatDateTime(timestamp) {
    const date = new Date(timestamp);
    return {
        date: date.toLocaleDateString('pt-BR'),
        time: date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
    };
}

// Log de inicialização
console.log('🚀 Waze Dashboard Utilities carregadas');

// Exportar para uso global
window.WazeNotify = WazeNotify;
window.WazeMap = WazeMap;
window.DashboardConfig = DashboardConfig;