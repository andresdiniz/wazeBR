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
  
      plotRoute: (geometry) => {
        if (!state.map) {
          console.error("Mapa não inicializado.");
          return;
        }
  
        if (state.layers.route) {
          state.map.removeLayer(state.layers.route);
        }
  
        const latLngs = geometry.map(p => [p.y, p.x]);
  
        state.layers.route = L.polyline(latLngs, CONFIG.map.routeStyle)
          .addTo(state.map);
  
        if (latLngs.length > 1) {
          const bounds = state.layers.route.getBounds();
          const calculatedZoom = state.map.getBoundsZoom(bounds);
          const MIN_DESIRED_ZOOM = 12;
  
          if (calculatedZoom < MIN_DESIRED_ZOOM) {
            state.map.setView(latLngs[0], MIN_DESIRED_ZOOM);
            console.warn(`Rota longa. Zoom ajustado para ${MIN_DESIRED_ZOOM} e centralizado no início.`);
          } else {
            state.map.fitBounds(bounds);
          }
        } else if (latLngs.length === 1 && latLngs[0]) {
          state.map.setView(latLngs[0], CONFIG.map.zoom);
        } else {
          console.warn("Geometria inválida ou vazia para plotar rota.");
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
                const routeId = button.dataset.routeId;
                DOM.modalElements.title.textContent = 'Carregando...';
                DOM.loadingIndicator.style.display = 'block';
    
                // Busca dados da API
                const data = await dataManager.fetchRouteData(routeId);
                if (!data.geometry || data.geometry.length === 0) {
                    throw new Error('Geometria da rota vazia ou inválida.');
                }
    
                // Atualiza título e insights
                DOM.modalElements.title.textContent = data.metadata.street;
                insightsRenderer.update(data);
    
                // Abre o modal
                const modalInstance = new bootstrap.Modal(DOM.mapModal);
                modalInstance.show();
    
                // Aguarda o modal abrir completamente
                DOM.mapModal.addEventListener(
                    'shown.bs.modal',
                    () => {
                        mapController.init('mapContainer', data.geometry[0]);
    
                        setTimeout(() => {
                            state.map.invalidateSize(); // Corrige tamanho do mapa
                            mapController.plotRoute(data.geometry); // Plota e centraliza
                        }, 150);
                    },
                    { once: true }
                );
            } catch (error) {
                utils.handleError(error, DOM.modalElements.insightsContainer);
            } finally {
                DOM.loadingIndicator.style.display = 'none';
            }
        },
    
        onModalClose: () => {
            DOM.modalElements.insightsContainer.innerHTML = '';
            mapController.clear(); // Boa prática: limpa o mapa ao fechar
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
  