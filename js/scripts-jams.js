document.addEventListener('DOMContentLoaded', () => {
    // Cache de elementos DOM
    const DOM = {
        mapModal: document.getElementById('mapModal'),
        viewRouteButtons: document.querySelectorAll('.view-route'),
        loadingIndicator: document.querySelector('#loadingIndicator'),
        modalElements: {
            title: document.querySelector('#modalRouteName'),
            mapContainer: document.querySelector('#mapContainer'),
            insightsContainer: document.querySelector('#insightsContainer')
        }
    };

    // Estado da aplicação
    const state = {
        map: null,
        layers: {
            route: null
        }
    };

    // Configurações globais
    const CONFIG = {
        map: {
            tileLayer: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            attribution: '© OpenStreetMap contributors',
            zoom: 14,
            routeStyle: {
                color: '#3366cc',
                weight: 5,
                opacity: 0.7
            }
        }
    };

    // Utilidades
    const utils = {
        formatDate: (dateInput) => {
            const options = {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };
            
            try {
                const date = new Date(dateInput);
                return isNaN(date) ? 'N/A' : date.toLocaleString('pt-BR', options);
            } catch (e) {
                return 'N/A';
            }
        },

        handleError: (error, container) => {
            console.error(error);
            const errorHTML = `
                <div class="alert alert-danger mt-3">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    ${error.message || 'Erro ao carregar dados'}
                </div>
            `;
            container.innerHTML = errorHTML;
        }
    };

    // Controle do mapa
    const mapController = {
        init: (containerId, coords) => {
            if (state.map) {
                state.map.remove();
            }
    
            state.map = L.map(containerId, {
                preferCanvas: true,
                fadeAnimation: true
            }).setView([coords.y, coords.x], CONFIG.map.zoom);
    
            L.tileLayer(CONFIG.map.tileLayer, {
                attribution: CONFIG.map.attribution
            }).addTo(state.map);
    
            return state.map;
        },
    
        plotRoute: (geometry) => {
            if (state.layers.route) {
                state.map.removeLayer(state.layers.route);
            }
    
            const latLngs = geometry.map(p => [p.y, p.x]);
            state.layers.route = L.polyline(latLngs, CONFIG.map.routeStyle)
                .addTo(state.map);
    
            if (latLngs.length > 1) {
                state.map.fitBounds(state.layers.route.getBounds());
            }
        },
    
        clear: () => { // <--- Método adicionado
            if (state.map) {
                state.map.remove();
                state.map = null;
            }
            state.layers.route = null;
        }
    };

    // Gerenciador de dados
    // Gerenciador de dados corrigido
    const dataManager = {
        fetchRouteData: async (routeId) => {
            try {
                const response = await fetch(`/api.php?action=get_jams_details&route_id=${routeId}`);
                
                if (!response.ok) {
                    throw new Error(`Erro HTTP: ${response.status}`);
                }

                const data = await response.json();
                
                if (data.error) {
                    throw new Error(data.error);
                }

                // Corrigindo a referência ao processData
                return dataManager.processData(data);
            } catch (error) {
                throw error;
            }
        },

        processData: (rawData) => {
            return {
                metadata: {
                    id: rawData.jam.uuid,
                    street: rawData.jam.street,
                    lastUpdate: utils.formatDate(rawData.jam.pubMillis)
                },
                geometry: rawData.lines,
                stats: {
                    speed: rawData.jam.speedKMH,
                    length: rawData.jam.length,
                    delay: rawData.jam.delay
                },
                segments: rawData.segments
            };
        }
    };

    // Renderização de insights
    const insightsRenderer = {
        update: (data) => {
            const insightsHTML = `
                <div class="col-md-6">
                    <div class="insight-item mb-3">
                        <small class="text-muted d-block">Velocidade Atual</small>
                        <h4 class="text-primary">${data.stats.speed} km/h</h4>
                    </div>
                    
                    <div class="insight-item mb-3">
                        <small class="text-muted d-block">Extensão</small>
                        <h4 class="text-secondary">${data.stats.length} metros</h4>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="insight-item mb-3">
                        <small class="text-muted d-block">Atraso</small>
                        <h4 class="text-danger">${data.stats.delay} segundos</h4>
                    </div>
                    
                    <div class="insight-item">
                        <small class="text-muted d-block">Última Atualização</small>
                        <h5 class="mb-0">${data.metadata.lastUpdate}</h5>
                    </div>
                </div>
            `;
            
            DOM.modalElements.insightsContainer.innerHTML = insightsHTML;
        }
    };

    // Handlers de eventos
    const eventHandlers = {
        onModalOpen: async (event) => {
            const button = event.target.closest('.view-route');
            if (!button) return;

            try {
                const routeId = button.dataset.routeId;
                DOM.modalElements.title.textContent = 'Carregando...';
                DOM.loadingIndicator.style.display = 'block';
                const data = await dataManager.fetchRouteData(routeId);

                DOM.modalElements.title.textContent = data.metadata.street;
                
                mapController.init('mapContainer', data.geometry[0]);
                mapController.plotRoute(data.geometry);
                insightsRenderer.update(data);

            } catch (error) {
                utils.handleError(error, DOM.modalElements.insightsContainer);
            } finally {
                DOM.loadingIndicator.style.display = 'none';
            }
        },

        onModalClose: () => {
            DOM.modalElements.insightsContainer.innerHTML = '';
        }
    };

    // Inicialização
    const init = () => {
        DOM.viewRouteButtons.forEach(button => {
            button.addEventListener('click', eventHandlers.onModalOpen);
        });

        DOM.mapModal.addEventListener('hidden.bs.modal', eventHandlers.onModalClose);
    };

    init();
});