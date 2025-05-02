document.addEventListener('DOMContentLoaded', () => {
    const formatDate = (dateInput) => {
        const date = typeof dateInput === 'string' ? new Date(dateInput) : dateInput;
        return isNaN(date) ? 'N/A' : date.toLocaleDateString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
    };

    // Estado global para gerenciar instâncias
    const state = {
        map: null,
        routeLayer: null,
        heatmapChart: null,
        lineChart: null
    };

    // Configurações
    const config = {
        map: {
            tileLayer: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            attribution: '© OpenStreetMap contributors',
            routeStyle: {
                color: level => {
                    const colors = ['#4CAF50', '#FFEB3B', '#FF9800', '#F44336', '#D32F2F', '#B71C1C'];
                    return colors[Math.min(Math.max(level, 0), 5)];
                },
                weight: 6,
                opacity: 0.8
            }
        },
        defaultZoom: 14
    };

    // Elementos DOM
    const elements = {
        mapModal: document.getElementById('mapModal'),
        modalTitle: document.querySelector('#modalRouteName'),
        loadingIndicator: document.querySelector('#loadingIndicator'),
        mapContainer: document.querySelector('#mapContainer'),
        insightsContainer: document.querySelector('#insightsContainer')
    };

    // Gerenciamento do Modal
    const initModal = () => {
        $(elements.mapModal).on('hidden.bs.modal', () => {
            cleanUpResources();
        });
    };

    const cleanUpResources = () => {
        if (state.map) {
            state.map.remove();
            state.map = null;
        }
        if (state.heatmapChart) {
            state.heatmapChart.destroy();
            state.heatmapChart = null;
        }
        if (state.lineChart) {
            state.lineChart.destroy();
            state.lineChart = null;
        }
        elements.insightsContainer.innerHTML = '';
    };

    // Mapa
    const initMap = (geometry) => {
        if (!geometry || geometry.length === 0) return null;

        const map = L.map(elements.mapContainer, {
            preferCanvas: true,
            fadeAnimation: true
        }).setView([geometry[0].y, geometry[0].x], config.defaultZoom);

        L.tileLayer(config.map.tileLayer, {
            attribution: config.map.attribution
        }).addTo(map);

        return map;
    };

    const plotRoute = (geometry, level) => {
        if (!state.map) return;

        if (state.routeLayer) {
            state.map.removeLayer(state.routeLayer);
        }

        const latLngs = geometry.map(p => [p.y, p.x]);
        state.routeLayer = L.polyline(latLngs, {
            color: config.map.routeStyle.color(level),
            weight: config.map.routeStyle.weight,
            opacity: config.map.routeStyle.opacity
        }).addTo(state.map);

        if (latLngs.length > 1) {
            state.map.fitBounds(state.routeLayer.getBounds());
        }
    };

    // Gráficos
    const renderHeatmap = (containerId, data) => {
        // Implementação do heatmap mantida
    };

    const renderLineChart = (containerId, data) => {
        // Implementação do line chart mantida
    };

    // Insights
    const renderInsights = (route, geometry, heatmapData) => {
        const insightsHTML = `
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card insight-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <i class="fas fa-tachometer-alt fa-2x text-primary me-3"></i>
                                <div>
                                    <small class="text-muted">Velocidade Atual</small>
                                    <h3 class="mb-0">${route.avg_speed} km/h</h3>
                                </div>
                            </div>
                            <hr>
                            <div class="d-flex align-items-center">
                                <i class="fas fa-history fa-2x text-secondary me-3"></i>
                                <div>
                                    <small class="text-muted">Velocidade Histórica</small>
                                    <h3 class="mb-0">${route.historic_speed} km/h</h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card insight-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="progress flex-grow-1 me-3" style="height: 20px;">
                                    <div class="progress-bar bg-danger" 
                                         style="width: ${(route.jam_level/5)*100}%">
                                    </div>
                                </div>
                                <div>
                                    <small class="text-muted">Nível de Congestionamento</small>
                                    <h3 class="mb-0">${route.jam_level}/5</h3>
                                </div>
                            </div>
                            <hr>
                            <div class="d-flex align-items-center">
                                <i class="fas fa-clock fa-2x text-warning me-3"></i>
                                <div>
                                    <small class="text-muted">Melhor Horário</small>
                                    <h3 class="mb-0">${calculateBestTime(heatmapData)}</h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        elements.insightsContainer.innerHTML = insightsHTML;
    };

    // Event Handlers
    const setupRouteButtons = () => {
        document.querySelectorAll('.view-route').forEach(button => {
            button.addEventListener('click', async () => {
                try {
                    elements.loadingIndicator.style.display = 'block';
                    const routeId = button.dataset.routeId;
                    
                    const response = await fetch(`/api.php?action=get_route_details&route_id=${routeId}`);
                    const result = await response.json();
                    
                    if (result.error) throw new Error(result.error);
                    
                    // Inicialização após abertura do modal
                    $(elements.mapModal).on('shown.bs.modal', () => {
                        state.map = initMap(result.data.geometry);
                        if (state.map) {
                            plotRoute(result.data.geometry, result.data.route.jam_level);
                            state.map.invalidateSize();
                        }
                    }, {once: true});

                    renderInsights(result.data.route, result.data.geometry, result.data.heatmap);
                    $(elements.mapModal).modal('show');

                } catch (err) {
                    console.error('Erro:', err);
                    elements.insightsContainer.innerHTML = `
                        <div class="alert alert-danger mt-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            ${err.message}
                        </div>
                    `;
                } finally {
                    elements.loadingIndicator.style.display = 'none';
                }
            });
        });
    };

    // Inicialização
    const init = () => {
        initModal();
        setupRouteButtons();
    };

    init();
});