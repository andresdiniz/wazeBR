document.addEventListener('DOMContentLoaded', () => {
    // Elementos DOM
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

    // Configurações
    const CONFIG = {
        map: {
            tileLayer: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            attribution: '© OpenStreetMap contributors',
            zoom: 14,
            getColorForLevel: (level) => {
                const colors = ['#4CAF50', '#FFEB3B', '#FF9800', '#F44336', '#D32F2F', '#B71C1C'];
                return colors[Math.min(Math.max(level, 0), 5)];
            },
            routeStyle: {
                weight: 6,
                opacity: 0.8,
                lineJoin: 'round'
            }
        }
    };

    // Utilitários
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
            container.innerHTML = `
                <div class="alert alert-danger mt-3">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    ${error.message || 'Erro ao carregar dados'}
                </div>
            `;
        }
    };

    // Controle do Mapa
    const mapController = {
        init: (containerId, coords) => {
            if (state.map) mapController.clear();

            state.map = L.map(containerId, {
                preferCanvas: true,
                fadeAnimation: true,
                zoomControl: false
            }).setView([coords.y, coords.x], CONFIG.map.zoom);

            L.tileLayer(CONFIG.map.tileLayer, {
                attribution: CONFIG.map.attribution
            }).addTo(state.map);

            L.control.zoom({ position: 'bottomright' }).addTo(state.map);
        },

        plotRoute: (geometry, level) => {
            if (!state.map || !geometry?.length) return;

            if (state.layers.route) {
                state.map.removeLayer(state.layers.route);
            }

            const color = CONFIG.map.getColorForLevel(level);
            const latLngs = geometry.map(p => [p.y, p.x]);

            state.layers.route = L.polyline(latLngs, {
                color,
                ...CONFIG.map.routeStyle
            }).addTo(state.map);

            if (latLngs.length > 1) {
                state.map.fitBounds(state.layers.route.getBounds(), {
                    padding: [30, 30],
                    maxZoom: 17
                });
            }
        },

        clear: () => {
            if (state.map) {
                state.map.remove();
                state.map = null;
            }
            state.layers.route = null;
        }
    };

    // Gerenciamento de Dados
    const dataManager = {
        async fetchRouteData(routeId) {
            try {
                const response = await fetch(`/api.php?action=get_jams_details&route_id=${routeId}`);
                if (!response.ok) throw new Error(`Erro HTTP: ${response.status}`);

                const responseData = await response.json();
                if (!responseData.data?.jam) throw new Error('Estrutura de dados inválida');

                return this.processData(responseData.data);
            } catch (error) {
                throw error;
            }
        },

        processData(rawData) {
            if (!rawData.jam || !rawData.lines) {
                throw new Error('Dados essenciais faltando');
            }

            return {
                metadata: {
                    id: rawData.jam.uuid,
                    street: rawData.jam.street,
                    lastUpdate: utils.formatDate(rawData.jam.pubMillis),
                    city: rawData.jam.city
                },
                geometry: rawData.lines.map(line => ({
                    x: parseFloat(line.x),
                    y: parseFloat(line.y)
                })),
                stats: {
                    speed: rawData.jam.speedKMH || 0,
                    length: rawData.jam.length || 0,
                    delay: rawData.jam.delay || 0,
                    level: rawData.jam.level
                }
            };
        }
    };

    // Renderização de Insights
    const insightsRenderer = {
        update: (data) => {
            DOM.modalElements.insightsContainer.innerHTML = `
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="row g-3">
                            ${insightsRenderer.renderMetric('Velocidade Média', data.stats.speed.toFixed(1) + ' km/h', 'tachometer-alt', 'primary')}
                            ${insightsRenderer.renderMetric('Extensão', data.stats.length + ' metros', 'ruler', 'info')}
                            ${insightsRenderer.renderMetric('Atraso', data.stats.delay + ' segundos', 'clock', 'warning')}
                            ${insightsRenderer.renderCongestionLevel(data.stats.level)}
                            ${insightsRenderer.renderLocation(data.metadata.city, data.metadata.lastUpdate)}
                        </div>
                    </div>
                </div>
            `;
        },

        renderMetric(title, value, icon, color) {
            return `
                <div class="col-md-6">
                    <div class="d-flex align-items-center p-3 bg-${color}-light rounded-3">
                        <div class="icon-circle bg-${color} me-3">
                            <i class="fas fa-${icon} text-white"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block">${title}</small>
                            <h4 class="mb-0">${value}</h4>
                        </div>
                    </div>
                </div>
            `;
        },

        renderCongestionLevel(level) {
            return `
                <div class="col-12">
                    <div class="p-3 bg-danger-light rounded-3">
                        <div class="d-flex align-items-center">
                            <div class="icon-circle bg-danger me-3">
                                <i class="fas fa-traffic-light text-white"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="text-muted d-block">Nível de Congestionamento</small>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 20px;">
                                        <div class="progress-bar bg-danger" style="width: ${(level / 5) * 100}%">
                                        </div>
                                    </div>
                                    <h4 class="mb-0">${level}/5</h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        renderLocation(city, lastUpdate) {
            return `
                <div class="col-12">
                    <div class="p-3 bg-success-light rounded-3">
                        <div class="d-flex align-items-center">
                            <div class="icon-circle bg-success me-3">
                                <i class="fas fa-city text-white"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="text-muted d-block">Localização</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <h4 class="mb-0">${city}</h4>
                                    <small class="text-muted">
                                        <i class="fas fa-sync-alt me-1"></i>
                                        ${lastUpdate}
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
    };

    // Handlers de Eventos
    const eventHandlers = {
        onModalOpen: async (event) => {
            const button = event.target.closest('.view-route');
            if (!button) return;

            try {
                const routeId = button.dataset.routeId;
                DOM.loadingIndicator.style.display = 'block';
                DOM.modalElements.insightsContainer.innerHTML = '';
                mapController.clear();

                const data = await dataManager.fetchRouteData(routeId);
                const modal = new bootstrap.Modal(DOM.mapModal);
                modal.show();

                DOM.mapModal.addEventListener('shown.bs.modal', () => {
                    mapController.init('mapContainer', data.geometry[0]);
                    mapController.plotRoute(data.geometry, data.stats.level);
                    setTimeout(() => {
                        if (state.map) {
                            state.map.invalidateSize();
                            state.map.panBy([0, -30]);
                        }
                    }, 50);
                }, { once: true });

                DOM.modalElements.title.textContent = data.metadata.street;
                insightsRenderer.update(data);
            } catch (error) {
                utils.handleError(error, DOM.modalElements.insightsContainer);
            } finally {
                DOM.loadingIndicator.style.display = 'none';
            }
        },

        onModalClose: () => {
            mapController.clear();
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
