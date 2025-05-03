document.addEventListener('DOMContentLoaded', () => {
    // Elementos DOM
    const DOM = {
        mapModal: document.getElementById('mapModal'),
        viewRouteButtons: document.querySelectorAll('.view-route'),
        loadingIndicator: document.getElementById('loadingIndicator'),
        modalElements: {
            title: document.getElementById('modalRouteName'),
            mapContainer: document.getElementById('mapContainer'),
            insightsContainer: document.getElementById('insightsContainer')
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
                // Verifica se dateInput é em milissegundos
                const date = typeof dateInput === 'number' ? new Date(dateInput) : new Date(dateInput);
                return isNaN(date) ? 'N/A' : date.toLocaleString('pt-BR', options);
            } catch (e) {
                return 'N/A';
            }
        },
        handleError: (error, container) => {
            console.error('Erro:', error);
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
            console.log('Inicializando mapa...');
            
            // Limpar mapa anterior se existir
            if (state.map) {
                mapController.clear();
            }
            
            // Obter elemento do container
            const containerEl = typeof containerId === 'string' 
                ? document.getElementById(containerId) 
                : containerId;
                
            if (!containerEl) {
                console.error('Container do mapa não encontrado');
                return;
            }
            
            // Garantir que o container esteja visível
            containerEl.style.display = 'block';
            containerEl.style.height = '500px';
            
            try {
                // Criar instância do mapa
                state.map = L.map(containerEl, {
                    preferCanvas: true,
                    fadeAnimation: true,
                    zoomControl: false
                }).setView([coords.y, coords.x], CONFIG.map.zoom);
                
                // Adicionar camada de tiles
                L.tileLayer(CONFIG.map.tileLayer, {
                    attribution: CONFIG.map.attribution
                }).addTo(state.map);
                
                // Adicionar controles
                L.control.zoom({ position: 'bottomright' }).addTo(state.map);
                
                console.log('Mapa inicializado com sucesso');
                
                // Forçar recálculo de tamanho
                setTimeout(() => {
                    if (state.map) {
                        state.map.invalidateSize(true);
                    }
                }, 300);
                
            } catch (err) {
                console.error('Erro ao inicializar mapa:', err);
            }
        },

        plotRoute: (geometry, level) => {
            console.log('Iniciando plotagem da rota...', geometry);
            
            if (!state.map) {
                console.error("Mapa não inicializado");
                return;
            }
    
            if (state.layers.route) {
                console.log('Removendo camada existente...');
                state.map.removeLayer(state.layers.route);
            }
    
            try {
                console.log('Convertendo coordenadas...');
                
                // Validar e converter coordenadas
                const latLngs = geometry
                    .filter(p => p && p.x !== undefined && p.y !== undefined)
                    .map(p => {
                        // Converter para números flutuantes corretamente
                        const lat = parseFloat(String(p.y).replace(',', '.'));
                        const lng = parseFloat(String(p.x).replace(',', '.'));
                        
                        if (isNaN(lat) || isNaN(lng)) {
                            console.warn(`Coordenada inválida ignorada: ${p.y}, ${p.x}`);
                            return null;
                        }
                        return [lat, lng];
                    })
                    .filter(coord => coord !== null);
    
                if (latLngs.length === 0) {
                    throw new Error('Nenhuma coordenada válida encontrada');
                }
                
                console.log('Coordenadas válidas:', latLngs);
                
                // Criar linha da rota
                const color = CONFIG.map.getColorForLevel(level);
                state.layers.route = L.polyline(latLngs, {
                    color,
                    ...CONFIG.map.routeStyle
                }).addTo(state.map);
    
                console.log('Rota adicionada ao mapa');
    
                // Ajustar visualização
                if (latLngs.length > 1) {
                    console.log('Ajustando visualização...');
                    const bounds = L.latLngBounds(latLngs);
                    state.map.fitBounds(bounds, {
                        padding: [30, 30],
                        maxZoom: 17
                    });
                }
            } catch (error) {
                console.error('Erro ao plotar rota:', error);
                throw error;
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
                console.log('Dados recebidos:', responseData);
                
                if (!responseData.data?.jam) throw new Error('Estrutura de dados inválida');

                return this.processData(responseData.data);
            } catch (error) {
                console.error('Erro ao buscar dados:', error);
                throw error;
            }
        },

        processData(rawData) {
            if (!rawData.jam || !rawData.lines) {
                throw new Error('Dados essenciais faltando');
            }

            // Processar e validar dados
            return {
                metadata: {
                    id: rawData.jam.uuid,
                    street: rawData.jam.street || 'Rua não identificada',
                    lastUpdate: utils.formatDate(rawData.jam.pubMillis),
                    city: rawData.jam.city || 'Cidade não identificada'
                },
                geometry: Array.isArray(rawData.lines) ? rawData.lines.map(line => ({
                    x: parseFloat(String(line.x).replace(',', '.')),
                    y: parseFloat(String(line.y).replace(',', '.'))
                })) : [],
                stats: {
                    speed: parseFloat(rawData.jam.speedKMH || 0),
                    length: parseInt(rawData.jam.length || 0, 10),
                    delay: parseInt(rawData.jam.delay || 0, 10),
                    level: parseInt(rawData.jam.level || 0, 10)
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
                            ${insightsRenderer.renderMetric('Atraso', (data.stats.delay / 60).toFixed(1) + ' minutos', 'clock', 'warning')}
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
                    <div class="d-flex align-items-center p-3 bg-light rounded-3 border-${color}">
                        <div class="bg-${color} p-2 rounded-circle me-3 text-center" style="width: 40px; height: 40px;">
                            <i class="fas fa-${icon} text-white"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block">${title}</small>
                            <h5 class="mb-0">${value}</h5>
                        </div>
                    </div>
                </div>
            `;
        },

        renderCongestionLevel(level) {
            const levelPercent = (level / 5) * 100;
            return `
                <div class="col-12">
                    <div class="p-3 bg-light rounded-3 border-danger">
                        <div class="d-flex align-items-center">
                            <div class="bg-danger p-2 rounded-circle me-3 text-center" style="width: 40px; height: 40px;">
                                <i class="fas fa-traffic-light text-white"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="text-muted d-block">Nível de Congestionamento</small>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 20px;">
                                        <div class="progress-bar bg-danger" style="width: ${levelPercent}%">
                                        </div>
                                    </div>
                                    <h5 class="mb-0">${level}/5</h5>
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
                    <div class="p-3 bg-light rounded-3 border-success">
                        <div class="d-flex align-items-center">
                            <div class="bg-success p-2 rounded-circle me-3 text-center" style="width: 40px; height: 40px;">
                                <i class="fas fa-city text-white"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="text-muted d-block">Localização</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">${city}</h5>
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
        onViewRouteClick: async (event) => {
            const button = event.currentTarget;
            if (!button) return;

            try {
                const routeId = button.getAttribute('data-route-id');
                console.log('Visualizando rota:', routeId);
                
                // Mostrar loading e limpar estado anterior
                DOM.loadingIndicator.style.display = 'block';
                DOM.modalElements.insightsContainer.innerHTML = '';
                DOM.modalElements.mapContainer.style.display = 'none';
                
                // Buscar dados da rota
                const data = await dataManager.fetchRouteData(routeId);
                
                // Atualizar título do modal
                DOM.modalElements.title.textContent = data.metadata.street;
                
                // Criar ou mostrar modal usando Bootstrap 5
                const modalEl = DOM.mapModal;
                const modal = new bootstrap.Modal(modalEl);
                
                // Armazenar a instância do modal para referência posterior
                modalEl._bootstrapModal = modal;
                
                modal.show();
                
                // Esperar o modal estar totalmente visível antes de inicializar o mapa
                modalEl.addEventListener('shown.bs.modal', () => {
                    console.log('Modal visível, inicializando mapa...');
                    
                    // Garantir que o container do mapa esteja visível
                    DOM.modalElements.mapContainer.style.display = 'block';
                    
                    // Inicializar mapa com primeira coordenada
                    if (data.geometry && data.geometry.length > 0) {
                        mapController.init(DOM.modalElements.mapContainer, data.geometry[0]);
                        
                        // Adicionar rota ao mapa após um curto delay
                        setTimeout(() => {
                            mapController.plotRoute(data.geometry, data.stats.level);
                        }, 300);
                    }
                    
                    // Renderizar insights
                    insightsRenderer.update(data);
                    
                    // Ocultar loading
                    DOM.loadingIndicator.style.display = 'none';
                }, { once: true });

            } catch (error) {
                utils.handleError(error, DOM.modalElements.insightsContainer);
                DOM.loadingIndicator.style.display = 'none';
            }
        },
        
        onModalClose: () => {
            console.log('Modal fechado, limpando mapa...');
            mapController.clear();
            DOM.modalElements.insightsContainer.innerHTML = '';
            DOM.modalElements.mapContainer.style.display = 'none';
            
            // Remover backdrop e classes que podem estar causando o bloqueio
            document.body.classList.remove('modal-open');
            
            // Remover qualquer backdrop que possa ter ficado
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.remove();
            });
            
            // Garantir que o overflow do body seja restaurado
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }
    };

    // Inicialização
    const init = () => {
        console.log('Inicializando aplicação...');
        
        // Verificar se o Bootstrap está disponível
        if (typeof bootstrap === 'undefined') {
            console.error('Bootstrap não está disponível');
        }
        
        // Verificar se o Leaflet está disponível
        if (typeof L === 'undefined') {
            console.error('Leaflet não está disponível');
        }
        
        // Associar eventos aos botões
        DOM.viewRouteButtons.forEach(button => {
            button.addEventListener('click', eventHandlers.onViewRouteClick);
        });
        
        // Associar evento ao fechamento do modal
        DOM.mapModal.addEventListener('hidden.bs.modal', eventHandlers.onModalClose);
        
        // Inicialização completa - verificar se há problemas de modal aberto
        setTimeout(() => {
            // Verificar e remover qualquer modal-backdrop órfão
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
                backdrop.remove();
            });
            
            // Remover classe modal-open do body se não houver modal aberto
            if (!document.querySelector('.modal.show')) {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }
        }, 500);
        
        console.log('Aplicação inicializada com sucesso!');
    };

    // Iniciar aplicação
    init();
});