document.addEventListener('DOMContentLoaded', () => {
    const formatDate = (dateInput) => {
        const date = typeof dateInput === 'string' ? new Date(dateInput) : dateInput;
        if(isNaN(date)) return 'N/A';
        return date.toLocaleDateString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
    };

    const mapModal = document.getElementById('mapModal');
    let mapInstance = null;
    let routeLayer = null;
    let heatmapChartInstance = null;
    let lineChartInstance = null;

    document.querySelectorAll('.view-route').forEach(button => {
        button.addEventListener('click', async () => {
            const routeId = button.dataset.routeId;
            const modalTitle = mapModal.querySelector('#modalRouteName');
            const loadingIndicator = mapModal.querySelector('#loadingIndicator');
            const mapContainer = mapModal.querySelector('#mapContainer');
            const heatmapChartContainer = mapModal.querySelector('#heatmapChart');
            const insightsContainer = mapModal.querySelector('#insightsContainer');
            const lineChartContainer = mapModal.querySelector('#lineChartContainer');

            modalTitle.textContent = 'Carregando...';

            // Limpar instâncias anteriores
            if (mapInstance) mapInstance.remove();
            if (heatmapChartInstance) heatmapChartInstance.destroy();
            if (lineChartInstance) lineChartInstance.destroy();
            
            mapContainer.innerHTML = '';
            heatmapChartContainer.innerHTML = '';
            insightsContainer.innerHTML = '';
            lineChartContainer.innerHTML = '';

            loadingIndicator.style.display = 'block';

            try {
                const response = await fetch(`/api.php?action=get_route_details&route_id=${routeId}`);
                if (!response.ok) throw new Error(`Erro HTTP! status: ${response.status}`);
                
                const result = await response.json();
                if (result.error) throw new Error(result.error);

                const { route, geometry, historic, heatmap } = result.data;

                modalTitle.textContent = route.name || 'Detalhes da Rota';

                // Renderizar componentes
                renderMap(mapContainer.id, geometry);
                renderHeatmap(heatmapChartContainer.id, heatmap);
                renderInsights(insightsContainer, route, geometry, heatmap);
                
                // Renderizar gráfico de linha se houver dados históricos
                if (historic && historic.length > 0) {
                    renderLineChart(lineChartContainer.id, historic);
                }

            } catch (err) {
                console.error('Erro:', err);
                insightsContainer.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
            } finally {
                loadingIndicator.style.display = 'none';
            }
        });
    });

    function renderMap(containerId, geometry) {
        // ... (manter mesma implementação anterior)
    }

    function renderHeatmap(containerId, heatmapData) {
        // ... (manter mesma implementação anterior)
    }

    function renderInsights(containerElement, route, geometry, heatmapData) {
        if (!containerElement) return;

        // ... (manter mesma implementação anterior de processamento de dados)
        
        // Novo conteúdo HTML simplificado e otimizado
        const insightsHTML = `
            <div class="insights-grid">
                <div class="insight-item">
                    <small class="text-muted">Velocidade Atual</small>
                    <h4 class="text-primary">${avgSpeed} km/h</h4>
                </div>
                
                <div class="insight-item">
                    <small class="text-muted">Velocidade Histórica</small>
                    <h4 class="text-secondary">${historicSpeed} km/h</h4>
                </div>
                
                <div class="insight-item">
                    <small class="text-muted">Variação</small>
                    <h4 class="${speedVariation >= 0 ? 'text-success' : 'text-danger'}">
                        ${speedVariationFormatted}% ${speedVariationArrow}
                    </h4>
                </div>
                
                <div class="insight-item">
                    <small class="text-muted">Congestionamento</small>
                    <div class="d-flex align-items-center">
                        <div class="progress flex-grow-1" style="height: 8px;">
                            <div class="progress-bar bg-${jamLevel > 3 ? 'danger' : 'warning'}" 
                                 style="width: ${(jamLevel / 5) * 100}%">
                            </div>
                        </div>
                        <small class="badge badge-${jamLevel > 3 ? 'danger' : 'warning'} ml-2">
                            Nível ${jamLevel}
                        </small>
                    </div>
                </div>
                
                <div class="insight-item">
                    <small class="text-muted">Melhor Horário</small>
                    <h5 class="mb-0">${best.period}</h5>
                    <small class="text-muted">${best.day}</small>
                </div>
            </div>
        `;

        containerElement.innerHTML = insightsHTML;
    }

    function renderLineChart(containerId, historicData) {
        const container = document.getElementById(containerId);
        if (!container || !historicData.length) return;

        // Processar dados
        const processedData = historicData
            .map(item => ({
                date: new Date(item.date),
                speed: parseFloat(item.avg_speed)
            }))
            .sort((a, b) => a.date - b.date);

        // Configurar eixos
        const categories = processedData.map(item => formatDate(item.date));
        const data = processedData.map(item => item.speed);

        // Criar gráfico
        lineChartInstance = Highcharts.chart(containerId, {
            chart: {
                type: 'line',
                height: 250
            },
            title: { text: 'Desempenho Histórico - Últimos 7 Dias' },
            xAxis: {
                categories,
                title: { text: 'Data' },
                crosshair: true
            },
            yAxis: {
                title: { text: 'Velocidade (km/h)' },
                min: Math.min(...data) - 5
            },
            series: [{
                name: 'Velocidade Média',
                data,
                color: '#4e73df',
                marker: { radius: 4 },
                tooltip: {
                    valueDecimals: 1,
                    valueSuffix: ' km/h'
                }
            }],
            plotOptions: {
                line: {
                    dataLabels: {
                        enabled: true,
                        format: '{y:.1f} km/h'
                    }
                }
            },
            credits: { enabled: false }
        });
    }
});