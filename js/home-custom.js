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

// Objeto para rastrear instâncias de mapa (necessário para o fix do modal)
window.mapInstances = {};

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
    /**
     * Retorna a cor hexadecimal para o nível de congestionamento do Waze.
     * @param {number} level - Nível de 1 (Leve) a 5 (Parado).
     * @returns {string} Código de cor hex.
     */
    getColorForLevel: function(level) {
        const colors = {
            5: '#dc3545', // Vermelho (Perigo) - Tráfego Parado
            4: '#ff4d4d', // Vermelho Claro - Tráfego Muito Lento
            3: '#ffc107', // Amarelo (Aviso) - Tráfego Lento
            2: '#28a745', // Verde (Sucesso) - Tráfego Moderado
            1: '#17a2b8'  // Ciano (Info) - Tráfego Leve
        };
        return colors[level] || '#6c757d';
    },

    /**
     * Retorna o texto descritivo para o nível de congestionamento.
     * @param {number} level - Nível de 1 (Leve) a 5 (Parado).
     * @returns {string} Texto descritivo.
     */
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

    /**
     * Retorna a classe de badge (Bootstrap) para o nível de congestionamento.
     * Usada no Twig para o novo card de resumo.
     * @param {number} level - Nível de 1 (Leve) a 5 (Parado).
     * @returns {string} Nome da classe CSS.
     */
    getBadgeClass: function(level) {
        const classes = {
            5: 'danger',    // Vermelho (Perigo)
            4: 'danger',    // Vermelho (Perigo)
            3: 'warning',   // Amarelo (Aviso)
            2: 'success',   // Verde (Sucesso)
            1: 'info'       // Ciano (Info)
        };
        return classes[level] || 'secondary';
    },

    /**
     * Inicializa o mapa Leaflet para exibição em modais.
     * Esta função é crucial para o fix do modal, pois armazena a instância do mapa.
     * (Você deve adaptá-la para sua lógica de inicialização de mapa).
     * @param {string} mapId - O ID do elemento DIV do mapa no modal (ex: 'modalMap123').
     * @param {number} lat - Latitude central.
     * @param {number} lng - Longitude central.
     */
    initModalMap: function(mapId, lat, lng) {
        // Se o mapa já foi inicializado, apenas centraliza e sai
        if (window.mapInstances[mapId]) {
            window.mapInstances[mapId].setView([lat, lng], 15);
            return;
        }

        // 1. Inicializa o mapa
        const map = L.map(mapId).setView([lat, lng], 15);

        // 2. Adiciona o Tile Layer (OpenStreetMap ou outro)
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        // 3. Armazena a instância no objeto global para ser acessada pelo fix do modal
        window.mapInstances[mapId] = map;
        
        // 4. (Opcional) Adiciona um marcador
        L.marker([lat, lng]).addTo(map);
    }
};

// Função para formatar números
function formatNumber(num) {
    return num.toLocaleString('pt-BR');
}

// Função para formatar data/hora
function formatDateTime(timestamp) {
    const date = new Date(timestamp);
    return date.toLocaleString('pt-BR', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

// ... Outras funções customizadas ...