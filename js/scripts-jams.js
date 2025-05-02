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
    
                const latLngs = geometry.map(p => [p.y, p.x]); // Já convertido no processData
                state.layers.route = L.polyline(latLngs, CONFIG.map.routeStyle)
                    .addTo(state.map);
    
                if (latLngs.length > 1) {
                    // O fitBounds inicial pode não funcionar perfeitamente se o modal não estiver visível.
                    // Ele será chamado novamente no handler onModalShown.
                    state.map.fitBounds(state.layers.route.getBounds());
                }
            },
    
            // Método para limpar o mapa e remover a camada de rota
            clear: () => {
                if (state.map) {
                    state.map.remove(); // Remove o mapa do DOM e libera recursos
                    state.map = null;
                }
                state.layers.route = null;
            }
        };
    
        // Gerenciador de dados
        const dataManager = {
            fetchRouteData: async (routeId) => {
                try {
                    const response = await fetch(`/api.php?action=get_jams_details&route_id=${routeId}`);
    
                    if (!response.ok) {
                        throw new Error(`Erro HTTP: ${response.status}`);
                    }
    
                    const responseData = await response.json();
    
                    if (!responseData.data || !responseData.data.jam) {
                        throw new Error('Estrutura de dados inválida da API');
                    }
    
                    return dataManager.processData(responseData.data); // Referenciar dataManager diretamente
                } catch (error) {
                    throw error;
                }
            },
    
            processData: (rawData) => {
                if (!rawData.jam || !rawData.lines) {
                    throw new Error('Dados essenciais faltando');
                }
    
                const geometry = rawData.lines.map(line => ({
                    x: parseFloat(line.x),
                    y: parseFloat(line.y)
                }));
    
                return {
                    metadata: {
                        id: rawData.jam.uuid,
                        street: rawData.jam.street,
                        lastUpdate: utils.formatDate(rawData.jam.pubMillis),
                        city: rawData.jam.city
                    },
                    geometry: geometry,
                    stats: {
                        speed: rawData.jam.speedKMH || 0,
                        length: rawData.jam.length || 0,
                        delay: rawData.jam.delay || 0,
                        level: rawData.jam.level
                    },
                    segments: rawData.segments || []
                };
            }
        };
    
        // Renderização de insights
        const insightsRenderer = {
            update: (data) => {
                const insightsHTML = `
                    <div class="col-md-6">
                        <div class="insight-item mb-3">
                            <small class="text-muted d-block">Velocidade</small>
                            <h4 class="text-primary">${data.stats.speed.toFixed(1)} km/h</h4>
                        </div>
    
                        <div class="insight-item mb-3">
                            <small class="text-muted d-block">Extensão</small>
                            <h4 class="text-secondary">${data.stats.length} metros</h4>
                        </div>
    
                        <div class="insight-item mb-3">
                            <small class="text-muted d-block">Nível de Congestionamento</small>
                            <h4 class="text-warning">${data.stats.level}/5</h4>
                        </div>
                    </div>
    
                    <div class="col-md-6">
                        <div class="insight-item mb-3">
                            <small class="text-muted d-block">Atraso</small>
                            <h4 class="text-danger">${data.stats.delay} segundos</h4>
                        </div>
    
                        <div class="insight-item mb-3">
                            <small class="text-muted d-block">Cidade</small>
                            <h5 class="mb-0">${data.metadata.city}</h5>
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
    
                // Assume que o clique no botão também dispara a abertura do modal Bootstrap
                // (geralmente via data-toggle="modal" data-target="#mapModal" no HTML do botão)
    
                try {
                    const routeId = button.dataset.routeId;
                    DOM.modalElements.title.textContent = 'Carregando...';
                    DOM.loadingIndicator.style.display = 'block';
    
                    // Fetch data first
                    const data = await dataManager.fetchRouteData(routeId);
    
                    // Update modal title
                    DOM.modalElements.title.textContent = data.metadata.street;
    
                    // Initialize map and plot route
                    // We initialize it here, but leaflet might not size it correctly yet
                    // data.geometry[0] is used here as a fallback center point for initialization
                    // if plotRoute doesn't have enough points or fitBounds fails initially.
                    mapController.init('mapContainer', data.geometry[0]);
                    mapController.plotRoute(data.geometry);
    
                    // Update insights
                    insightsRenderer.update(data);
    
                } catch (error) {
                    utils.handleError(error, DOM.modalElements.insightsContainer);
                } finally {
                    DOM.loadingIndicator.style.display = 'none';
                }
            },
    
            onModalClose: () => {
                // Clear insights
                DOM.modalElements.insightsContainer.innerHTML = '';
                // Clear the map when modal is hidden
                mapController.clear();
            },
    
            // *** NOVO HANDLER PARA QUANDO O MODAL TERMINA DE APARECER ***
            onModalShown: () => {
                // Force Leaflet to recalculate its size now that the modal is fully visible
                if (state.map) {
                    state.map.invalidateSize();
    
                    // Also, re-fit the bounds to the route to ensure it's centered and zoomed correctly
                    if (state.layers.route) {
                        state.map.fitBounds(state.layers.route.getBounds());
                    }
                }
            }
            // *********************************************************
        };
    
        // Inicialização
        const init = () => {
            DOM.viewRouteButtons.forEach(button => {
                button.addEventListener('click', eventHandlers.onModalOpen);
                // Certifique-se de que esses botões tenham os atributos
                // data-toggle="modal" data-target="#mapModal" no seu HTML
                // para que o Bootstrap controle a abertura do modal.
            });
    
            // Listen for modal hidden event
            DOM.mapModal.addEventListener('hidden.bs.modal', eventHandlers.onModalClose);
    
            // *** NOVO LISTENER PARA O EVENTO SHOWN ***
            // Listen for modal shown event
            DOM.mapModal.addEventListener('shown.bs.modal', eventHandlers.onModalShown);
            // ***************************************
        };
    
        init();
    });