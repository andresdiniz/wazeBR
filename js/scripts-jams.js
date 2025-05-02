document.addEventListener('DOMContentLoaded', () => {
    // ... (mantenha as constantes DOM, state e CONFIG originais)

    // Atualize o CONFIG para incluir cores dinâmicas
    const CONFIG = {
        map: {
            tileLayer: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            attribution: '© OpenStreetMap contributors',
            zoom: 14,
            getColorForLevel: (level) => {
                const colors = [
                    '#4CAF50', // Nível 0 - Verde
                    '#FFEB3B', // Nível 1 - Amarelo
                    '#FF9800', // Nível 2 - Laranja
                    '#F44336', // Nível 3 - Vermelho
                    '#D32F2F', // Nível 4 - Vermelho escuro
                    '#B71C1C'  // Nível 5 - Vermelho muito escuro
                ];
                return colors[Math.min(Math.max(level, 0), 5)];
            },
            routeStyle: {
                weight: 6,
                opacity: 0.8,
                lineJoin: 'round'
            }
        }
    };

    // Modifique o mapController para melhor controle
    const mapController = {
        init: (containerId, coords) => {
            if (state.map) {
                this.clear();
            }

            state.map = L.map(containerId, {
                preferCanvas: true,
                fadeAnimation: true,
                zoomControl: false
            }).setView([coords.y, coords.x], CONFIG.map.zoom);

            L.tileLayer(CONFIG.map.tileLayer, {
                attribution: CONFIG.map.attribution
            }).addTo(state.map);

            L.control.zoom({ position: 'bottomright' }).addTo(state.map);
            return state.map;
        },

        plotRoute: (geometry, level) => {
            if (!state.map) return;

            // Remove camadas anteriores
            if (state.layers.route) {
                state.map.removeLayer(state.layers.route);
            }

            const color = CONFIG.map.getColorForLevel(level);
            const latLngs = geometry.map(p => [p.y, p.x]);

            state.layers.route = L.polyline(latLngs, {
                color: color,
                weight: CONFIG.map.routeStyle.weight,
                opacity: CONFIG.map.routeStyle.opacity,
                lineJoin: CONFIG.map.routeStyle.lineJoin
            }).addTo(state.map);

            // Ajuste de zoom responsivo
            if (latLngs.length > 1) {
                const bounds = state.layers.route.getBounds();
                state.map.fitBounds(bounds, {
                    padding: [30, 30], // Espaçamento para elementos de UI
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

    // Atualize o insightsRenderer para melhor visualização
    const insightsRenderer = {
        update: (data) => {
            const insightsHTML = `
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title text-primary mb-4">
                                    <i class="fas fa-road me-2"></i>${data.metadata.street}
                                </h5>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="icon-circle bg-primary me-3">
                                                <i class="fas fa-tachometer-alt text-white"></i>
                                            </div>
                                            <div>
                                                <small class="text-muted">Velocidade Média</small>
                                                <h4 class="mb-0">${data.stats.speed.toFixed(1)} km/h</h4>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="icon-circle bg-warning me-3">
                                                <i class="fas fa-clock text-white"></i>
                                            </div>
                                            <div>
                                                <small class="text-muted">Atraso Estimado</small>
                                                <h4 class="mb-0">${data.stats.delay} segundos</h4>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="icon-circle bg-danger me-3">
                                                <i class="fas fa-traffic-light text-white"></i>
                                            </div>
                                            <div>
                                                <small class="text-muted">Nível de Congestionamento</small>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1 me-2" style="height: 20px;">
                                                        <div class="progress-bar bg-${this.getCongestionColor(data.stats.level)}" 
                                                            role="progressbar" 
                                                            style="width: ${(data.stats.level/5)*100}%">
                                                        </div>
                                                    </div>
                                                    <h4 class="mb-0">${data.stats.level}/5</h4>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex align-items-center">
                                            <div class="icon-circle bg-info me-3">
                                                <i class="fas fa-city text-white"></i>
                                            </div>
                                            <div>
                                                <small class="text-muted">Localização</small>
                                                <h4 class="mb-0">${data.metadata.city}</h4>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-4 text-muted small">
                                    <i class="fas fa-sync-alt me-1"></i>
                                    Atualizado em: ${data.metadata.lastUpdate}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            DOM.modalElements.insightsContainer.innerHTML = insightsHTML;
        },

        getCongestionColor: (level) => {
            return ['success', 'warning', 'orange', 'danger', 'dark-danger', 'darkest-danger'][level];
        }
    };

    // Atualize os event handlers para fluxo otimizado
    const eventHandlers = {
        onModalOpen: async (event) => {
            const button = event.target.closest('.view-route');
            if (!button) return;

            try {
                const routeId = button.dataset.routeId;
                DOM.loadingIndicator.style.display = 'block';
                DOM.modalElements.insightsContainer.innerHTML = '';

                // Limpar estado anterior
                mapController.clear();

                // Buscar dados
                const data = await dataManager.fetchRouteData(routeId);

                // Mostrar modal primeiro
                const modal = new bootstrap.Modal(DOM.mapModal);
                modal.show();

                // Configurar mapa após o modal estar visível
                DOM.mapModal.addEventListener('shown.bs.modal', () => {
                    mapController.init('mapContainer', data.geometry[0]);
                    mapController.plotRoute(data.geometry, data.stats.level);
                    
                    // Ajuste final do mapa
                    setTimeout(() => {
                        if (state.map) {
                            state.map.invalidateSize();
                            state.map.panBy([0, -30]); // Ajuste para controles de zoom
                        }
                    }, 50);
                }, { once: true });

                // Atualizar dados
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

    // ... (mantenha o restante da inicialização)
});
    
        DOM.mapModal.addEventListener('show.bs.modal', eventHandlers.onModalOpen);
        DOM.mapModal.addEventListener('hidden.bs.modal', eventHandlers.onModalClose);
    });
    // Adicione os event listeners para os botões de abrir o modal
    const viewRouteButtons = document.querySelectorAll('.view-route');
    viewRouteButtons.forEach(button => {
        button.addEventListener('click', eventHandlers.onModalOpen);
    });
    // Adicione o event listener para o fechamento do modal
    DOM.mapModal.addEventListener('hidden.bs.modal', eventHandlers.onModalClose);
    // Adicione o event listener para o botão de fechar o modal
    const closeModalButton = document.querySelector('.btn-close');
    closeModalButton.addEventListener('click', eventHandlers.onModalClose);
    // Adicione o event listener para o botão de fechar o modal
    const closeModalButton = document.querySelector('.btn-close');