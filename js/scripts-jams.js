document.addEventListener('DOMContentLoaded', () => {
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
  
    const state = {
      map: null,
      layers: {
        route: null
      }
    };
  
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
  
    const mapController = {
      init: (containerId, coords) => {
        if (state.map) {
          state.map.remove();
          state.map = null;
        }
  
        // Garante que o container está limpo
        document.getElementById(containerId).innerHTML = '';
  
        state.map = L.map(containerId, {
          preferCanvas: true,
          fadeAnimation: true
        }).setView([coords.y, coords.x], CONFIG.map.zoom);
  
        L.tileLayer(CONFIG.map.tileLayer, {
          attribution: CONFIG.map.attribution
        }).addTo(state.map);
  
        return state.map;
      },
  
      // Modifique o mapController.plotRoute para adicionar logs de depuração
    plotRoute: (geometry) => {
        console.log('Iniciando plotagem da rota...', geometry);
        
        if (!state.map) {
            console.error("Mapa não inicializado. State:", state);
            return;
        }

        if (state.layers.route) {
            console.log('Removendo camada existente...');
            state.map.removeLayer(state.layers.route);
        }

        try {
            console.log('Convertendo coordenadas...');
            const latLngs = geometry.map(p => {
                const latLng = [parseFloat(p.y), parseFloat(p.x)];
                console.log('Ponto convertido:', latLng);
                if (isNaN(latLng[0]) || isNaN(latLng[1])) {
                    throw new Error(`Coordenada inválida: ${p.y}, ${p.x}`);
                }
                return latLng;
            });

            console.log('Coordenadas válidas:', latLngs);
            
            state.layers.route = L.polyline(latLngs, CONFIG.map.routeStyle)
                .addTo(state.map);
            console.log('Rota adicionada ao mapa:', state.layers.route);

            if (latLngs.length > 1) {
                console.log('Ajustando visualização...');
                const bounds = state.layers.route.getBounds();
                console.log('Bounds calculados:', bounds);
                
                const calculatedZoom = state.map.getBoundsZoom(bounds);
                console.log('Zoom calculado:', calculatedZoom);
                
                state.map.fitBounds(bounds);
                console.log('Visualização ajustada com sucesso');
            } else if (latLngs.length === 1) {
                console.log('Centralizando em único ponto:', latLngs[0]);
                state.map.setView(latLngs[0], CONFIG.map.zoom);
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
  
    const dataManager = {
      async fetchRouteData(routeId) {
        try {
          const response = await fetch(`/api.php?action=get_jams_details&route_id=${routeId}`);
          if (!response.ok) throw new Error(`Erro HTTP: ${response.status}`);
          const responseData = await response.json();
          if (!responseData.data || !responseData.data.jam || !responseData.data.lines) {
            throw new Error('Estrutura de dados inválida da API ou dados essenciais faltando');
          }
          return this.processData(responseData.data);
        } catch (error) {
          throw error;
        }
      },
  
      processData(rawData) {
        if (!rawData || !rawData.jam || !rawData.lines) {
          throw new Error('Dados crus (rawData) essenciais faltando para processamento');
        }
  
        const geometry = rawData.lines.map((line) => ({
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
          geometry,
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
  
    const eventHandlers = {
        onModalOpen: async (event) => {
            const button = event.target.closest('.view-route');
            if (!button) return;
    
            try {
                console.log('Iniciando abertura do modal...');
                const routeId = button.dataset.routeId;
                DOM.modalElements.title.textContent = 'Carregando...';
                DOM.loadingIndicator.style.display = 'block';
    
                console.log('Buscando dados da rota...');
                const data = await dataManager.fetchRouteData(routeId);
                console.log('Dados recebidos:', data);
    
                if (!data.geometry || data.geometry.length === 0) {
                    throw new Error('Geometria da rota vazia ou inválida');
                }
    
                // Abre o modal primeiro
                const modal = new bootstrap.Modal(DOM.mapModal);
                modal.show();
    
                // Aguarda o modal estar totalmente visível
                DOM.mapModal.addEventListener('shown.bs.modal', () => {
                    console.log('Modal visível - Inicializando mapa...');
                    
                    try {
                        mapController.init('mapContainer', data.geometry[0]);
                        
                        // Força redimensionamento do mapa
                        setTimeout(() => {
                            console.log('Ajustando tamanho do mapa...');
                            if (state.map) {
                                state.map.invalidateSize(true);
                                console.log('Tamanho do mapa ajustado');
                                
                                console.log('Plotando rota...');
                                mapController.plotRoute(data.geometry);
                            }
                        }, 300);
                    } catch (error) {
                        console.error('Erro na inicialização do mapa:', error);
                        utils.handleError(error, DOM.modalElements.insightsContainer);
                    }
                }, {once: true});
    
                // Atualiza a interface
                DOM.modalElements.title.textContent = data.metadata.street;
                insightsRenderer.update(data);
    
            } catch (error) {
                console.error('Erro geral:', error);
                utils.handleError(error, DOM.modalElements.insightsContainer);
            } finally {
                DOM.loadingIndicator.style.display = 'none';
            }
        },
    
        onModalClose: () => {
            console.log('Limpando estado...');
            DOM.modalElements.insightsContainer.innerHTML = '';
            mapController.clear();
        }
    };    
  
    const init = () => {
      DOM.viewRouteButtons.forEach(button => {
        button.addEventListener('click', eventHandlers.onModalOpen);
      });
      DOM.mapModal.addEventListener('hidden.bs.modal', eventHandlers.onModalClose);
    };
  
    init();
  });
  