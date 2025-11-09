/**
 * Home Dashboard - Scripts Customizados
 * Sistema de Dados WAZE
 */

// Configurações globais
const DashboardConfig = {
    map: {
        defaultCenter: [-20.66, -43.79],
        defaultZoom: 13,
        maxZoom: 18,
        minZoom: 10
    },
    notifications: {
        position: "top right",
        autoHideDelay: 4000
    },
    dataTable: {
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Todos"]]
    }
};

// Classe para gerenciar o Dashboard
class WazeDashboard {
    constructor() {
        this.map = null;
        this.jamLayers = [];
        this.init();
    }

    init() {
        this.initTooltips();
        this.initModals();
        this.initDataTables();
        this.initMap();
        this.initCounterAnimation();
        this.showWelcomeNotification();
    }

    // Inicializar tooltips
    initTooltips() {
        const tooltipTriggerList = [].slice.call(
            document.querySelectorAll('[data-bs-toggle="tooltip"]')
        );
        
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl, {
                customClass: 'custom-tooltip',
                boundary: 'window'
            });
        });

        console.log('✓ Tooltips inicializados');
    }

    // Inicializar modais com mapas
    initModals() {
        const self = this;
        
        $(".open-modal").on("click", function () {
            const lat = parseFloat($(this).data("lat"));
            const lon = parseFloat($(this).data("lon"));
            const modalId = $(this).attr("data-bs-target");
            const mapId = modalId.replace("#alertModal", "map-");

            $(modalId).on('shown.bs.modal', function () {
                self.createModalMap(mapId, lat, lon);
            });
        });

        console.log('✓ Modais inicializados');
    }

    // Criar mapa dentro do modal
    createModalMap(mapId, lat, lon) {
        const mapElement = document.getElementById(mapId);
        
        if (!mapElement) {
            console.error('Elemento do mapa não encontrado:', mapId);
            return;
        }

        if (mapElement._leaflet_id) {
            return; // Mapa já inicializado
        }

        try {
            const map = L.map(mapId).setView([lat, lon], 15);

            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                attribution: "&copy; OpenStreetMap contributors",
                maxZoom: 19
            }).addTo(map);

            // Adicionar marcador customizado
            const customIcon = L.divIcon({
                className: 'custom-marker',
                html: '<i class="fas fa-map-marker-alt fa-3x text-danger"></i>',
                iconSize: [30, 42],
                iconAnchor: [15, 42]
            });

            L.marker([lat, lon], { icon: customIcon })
                .addTo(map)
                .bindPopup(`
                    <div class="text-center">
                        <strong>Localização do Alerta</strong><br>
                        <small>Lat: ${lat.toFixed(6)}<br>Lon: ${lon.toFixed(6)}</small>
                    </div>
                `)
                .openPopup();

            setTimeout(() => map.invalidateSize(), 100);
            
        } catch (error) {
            console.error('Erro ao criar mapa:', error);
            this.showNotification('Erro ao carregar mapa', 'error');
        }
    }

    // Inicializar DataTables
    initDataTables() {
        const self = this;
        
        const dataTableConfig = {
            language: {
                url: '//cdn.datatables.net/plug-ins/1.10.25/i18n/Portuguese-Brasil.json'
            },
            scrollX: true,
            responsive: true,
            pageLength: DashboardConfig.dataTable.pageLength,
            lengthMenu: DashboardConfig.dataTable.lengthMenu,
            order: [[2, 'desc']],
            columnDefs: [
                { orderable: false, targets: [4] },
                { width: "15%", targets: [0] },
                { width: "10%", targets: [4] }
            ],
            dom: '<"row mb-3"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                 '<"row"<"col-sm-12"tr>>' +
                 '<"row mt-3"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
            drawCallback: function(settings) {
                // Reinicializar tooltips após redesenhar a tabela
                self.initTooltips();
            }
        };

        // Inicializar tabela de acidentes
        if ($('#accidentsTable').length) {
            const accidentsTable = $('#accidentsTable').DataTable(dataTableConfig);
            console.log('✓ Tabela de acidentes inicializada');
        }

        // Inicializar tabela de congestionamentos
        if ($('#trafficTable').length) {
            const trafficTable = $('#trafficTable').DataTable(dataTableConfig);
            console.log('✓ Tabela de congestionamentos inicializada');
        }
    }

    // Inicializar mapa principal
    initMap() {
        const mapElement = document.getElementById('congestionMap');
        if (!mapElement) {
            console.warn('Elemento do mapa principal não encontrado');
            return;
        }

        const mapLoading = document.getElementById('mapLoading');
        if (mapLoading) mapLoading.classList.remove('d-none');

        try {
            this.map = L.map('congestionMap').setView(
                DashboardConfig.map.defaultCenter,
                DashboardConfig.map.defaultZoom
            );

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: DashboardConfig.map.maxZoom,
                minZoom: DashboardConfig.map.minZoom
            }).addTo(this.map);

            // Adicionar controle de camadas
            this.addLayerControl();

            // Carregar dados de congestionamento
            this.loadJamsData();

            console.log('✓ Mapa principal inicializado');

        } catch (error) {
            console.error('Erro ao inicializar mapa:', error);
            this.showNotification('Erro ao carregar mapa principal', 'error');
            if (mapLoading) mapLoading.classList.add('d-none');
        }
    }

    // Adicionar controle de camadas
    addLayerControl() {
        const baseMaps = {
            "OpenStreetMap": L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }),
            "Satélite": L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                attribution: 'Tiles &copy; Esri'
            })
        };

        // A camada padrão já foi adicionada, então remova antes de adicionar o controle
        this.map.eachLayer(layer => {
            if (layer instanceof L.TileLayer) {
                this.map.removeLayer(layer);
            }
        });

        baseMaps["OpenStreetMap"].addTo(this.map);
        L.control.layers(baseMaps).addTo(this.map);
    }

    // Carregar dados de congestionamento
    loadJamsData() {
        const mapLoading = document.getElementById('mapLoading');
        
        // Obter dados do Twig (já renderizados na página)
        const jamsDataElement = document.getElementById('jamsData');
        let jamsData = [];

        try {
            if (jamsDataElement) {
                jamsData = JSON.parse(jamsDataElement.textContent);
            }
        } catch (error) {
            console.error('Erro ao parsear dados de congestionamento:', error);
        }

        if (!Array.isArray(jamsData) || jamsData.length === 0) {
            setTimeout(() => {
                if (mapLoading) mapLoading.classList.add('d-none');
                this.showNotification('Nenhum congestionamento ativo no momento', 'info');
            }, 500);
            return;
        }

        let processedJams = 0;

        jamsData.forEach(jam => {
            if (jam.lines && Array.isArray(jam.lines) && jam.lines.length > 0) {
                this.addJamToMap(jam);
                processedJams++;
            }
        });

        setTimeout(() => {
            if (mapLoading) mapLoading.classList.add('d-none');
            this.showNotification(
                `${processedJams} congestionamento(s) carregado(s)`,
                'success'
            );
        }, 500);

        console.log(`✓ ${processedJams} congestionamentos carregados no mapa`);
    }

    // Adicionar congestionamento ao mapa
    addJamToMap(jam) {
        const latlngs = jam.lines.map(line => [line.latitude, line.longitude]);
        const color = this.getColorForLevel(jam.level);
        const levelText = this.getLevelText(jam.level);

        const polyline = L.polyline(latlngs, {
            color: color,
            weight: 6,
            opacity: 0.8,
            lineJoin: 'round',
            lineCap: 'round'
        }).addTo(this.map);

        polyline.bindPopup(this.createJamPopup(jam, levelText));
        this.jamLayers.push(polyline);

        // Adicionar efeito de animação
        polyline.on('mouseover', function() {
            this.setStyle({ weight: 8, opacity: 1 });
        });

        polyline.on('mouseout', function() {
            this.setStyle({ weight: 6, opacity: 0.8 });
        });
    }

    // Criar popup para congestionamento
    createJamPopup(jam, levelText) {
        return `
            <div style="min-width: 220px; max-width: 300px;">
                <h6 class="mb-2 text-primary">
                    <i class="fas fa-traffic-light me-2"></i>${levelText}
                </h6>
                <hr class="my-2">
                <p class="mb-1">
                    <strong><i class="fas fa-road me-2"></i>Rua:</strong><br>
                    ${jam.street || 'Não informada'}
                </p>
                <p class="mb-1">
                    <strong><i class="fas fa-city me-2"></i>Cidade:</strong><br>
                    ${jam.city || 'Não informada'}
                </p>
                <p class="mb-1">
                    <strong><i class="fas fa-chart-line me-2"></i>Nível:</strong> 
                    <span class="badge bg-${this.getBadgeClass(jam.level)}">${jam.level}/5</span>
                </p>
                ${jam.length ? `
                <p class="mb-1">
                    <strong><i class="fas fa-ruler me-2"></i>Extensão:</strong> 
                    ${(jam.length / 1000).toFixed(2)} km
                </p>` : ''}
                ${jam.delay ? `
                <p class="mb-0">
                    <strong><i class="fas fa-clock me-2"></i>Atraso:</strong> 
                    ${Math.floor(jam.delay / 60)} min
                </p>` : ''}
            </div>
        `;
    }

    // Obter cor baseado no nível
    getColorForLevel(level) {
        const colors = {
            5: '#dc3545', // Vermelho - Parado
            4: '#fd7e14', // Laranja - Muito Lento
            3: '#ffc107', // Amarelo - Lento
            2: '#28a745', // Verde - Moderado
            1: '#17a2b8'  // Azul - Leve
        };
        return colors[level] || '#6c757d';
    }

    // Obter texto do nível
    getLevelText(level) {
        const texts = {
            5: 'Tráfego Parado',
            4: 'Tráfego Muito Lento',
            3: 'Tráfego Lento',
            2: 'Tráfego Moderado',
            1: 'Tráfego Leve'
        };
        return texts[level] || 'Desconhecido';
    }

    // Obter classe do badge
    getBadgeClass(level) {
        const classes = {
            5: 'danger',
            4: 'warning',
            3: 'warning',
            2: 'success',
            1: 'info'
        };
        return classes[level] || 'secondary';
    }

    // Animação do contador de motoristas
    initCounterAnimation() {
        const driversCountElement = document.getElementById('driversCount');
        
        if (!driversCountElement) return;

        const targetValue = parseInt(driversCountElement.textContent.replace(/\D/g, ''));
        if (isNaN(targetValue)) return;

        let currentValue = 0;
        const duration = 2000; // 2 segundos
        const increment = targetValue / (duration / 16); // 60fps
        const startTime = Date.now();

        function updateCounter() {
            const elapsed = Date.now() - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Easing function para animação suave
            const easeOutQuart = 1 - Math.pow(1 - progress, 4);
            currentValue = Math.floor(targetValue * easeOutQuart);
            
            driversCountElement.textContent = currentValue.toLocaleString('pt-BR');

            if (progress < 1) {
                requestAnimationFrame(updateCounter);
            } else {
                driversCountElement.textContent = targetValue.toLocaleString('pt-BR');
            }
        }

        updateCounter();
        console.log('✓ Animação de contador iniciada');
    }

    // Mostrar notificação
    showNotification(message, type = 'info') {
        const classNames = {
            success: 'success',
            error: 'error',
            warn: 'warn',
            info: 'info'
        };

        $.notify(message, {
            position: DashboardConfig.notifications.position,
            className: classNames[type] || 'info',
            autoHideDelay: DashboardConfig.notifications.autoHideDelay
        });
    }

    // Notificação de boas-vindas
    showWelcomeNotification() {
        setTimeout(() => {
            this.showNotification(
                '🚗 Bem-vindo ao Dashboard WAZE! Monitoramento em tempo real.',
                'success'
            );
        }, 1000);
    }
}

// Inicializar dashboard quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Inicializando Waze Dashboard...');
    
    try {
        const dashboard = new WazeDashboard();
        
        // Tornar dashboard disponível globalmente para debug
        window.wazeDashboard = dashboard;
        
        console.log('✅ Dashboard inicializado com sucesso!');
    } catch (error) {
        console.error('❌ Erro ao inicializar dashboard:', error);
    }
});