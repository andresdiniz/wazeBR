document.addEventListener('DOMContentLoaded', () => {
    // Cache de elementos DOM
    const DOM = {
        mapModal: document.getElementById('mapModal'),
        viewRouteButtons: document.querySelectorAll('.view-route'),
        loadingIndicator: document.querySelector('#loadingIndicator'),
        modalElements: {
            title: document.querySelector('#modalRouteName'),
            mapContainer: document.querySelector('#mapContainer'),
            heatmapChart: document.querySelector('#heatmapChart'),
            insightsContainer: document.querySelector('#insightsContainer'),
            lineChartContainer: document.querySelector('#lineChartContainer')
        }
    };

    // Estado da aplicação
    const state = {
        map: null,
        layers: {
            route: null,
            markers: null
        },
        charts: {
            heatmap: null,
            line: null
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
        },
        charts: {
            heatmap: {
                height: 300,
                colorStops: [
                    [0, '#ff474c'],
                    [0.5, '#f7d54a'],
                    [1, '#4bd15f']
                ]
            },
            line: {
                height: 280,
                colors: {
                    line: '#3366cc',
                    marker: '#ffffff'
                }
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

                const data = await response.json();
                
                if (data.error) {
                    throw new Error(data.error);
                }

                return this.processData(data);
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

    // Handlers de eventos
    const eventHandlers = {
        onModalOpen: async (event) => {
            const button = event.target.closest('.view-route');
            if (!button) return;

            try {
                const routeId = button.dataset.routeId;
                DOM.modalElements.title.textContent = 'Carregando...';
                DOM.loadingIndicator.style.display = 'block';

                chartController.destroy();
                mapController.clear();

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
            chartController.destroy();
            mapController.clear();
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