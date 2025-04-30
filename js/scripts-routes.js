document.addEventListener('DOMContentLoaded', () => {
    const mapModal = document.getElementById('mapModal');
    let mapInstance = null;
    let routeLayer = null;

    // Abre o modal e carrega a rota
    document.querySelectorAll('.view-route').forEach(button => {
        button.addEventListener('click', async () => {
            const routeId = button.dataset.routeId;
            const modalTitle = document.getElementById('modalRouteName');
            const loadingIndicator = document.getElementById('loadingIndicator');
            
            modalTitle.textContent = 'Carregando...';
            document.getElementById('mapContainer').innerHTML = '';
            document.getElementById('heatmapChart').innerHTML = '';
            
            loadingIndicator.style.display = 'block';

            try {
                const response = await fetch(`/api.php?action=get_route_details&route_id=${routeId}`);
                const result = await response.json();

                if (result.error) {
                    alert('Erro ao buscar detalhes: ' + result.error);
                    return;
                }

                const { route, geometry, historic, heatmap, subroutes } = result.data;

                modalTitle.textContent = route.name;
                renderMap(geometry);
                renderHeatmap(heatmap, route);  // Passa os dados da rota para o heatmap
                renderInsights(route, geometry, heatmap);  // Passa os dados da rota e do heatmap para as análises
            } catch (err) {
                console.error('Erro ao carregar rota:', err);
                alert('Erro ao carregar rota. Veja o console para mais detalhes.');
            } finally {
                loadingIndicator.style.display = 'none';
            }
        });
    });

    function renderMap(geometry) {
        if (!geometry || geometry.length === 0) return;

        if (mapInstance) {
            mapInstance.remove();
        }

        mapInstance = L.map('mapContainer').setView([geometry[0].y, geometry[0].x], 14);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(mapInstance);

        const latlngs = geometry.map(p => [p.y, p.x]);
        routeLayer = L.polyline(latlngs, { color: 'blue', weight: 5 }).addTo(mapInstance);
        mapInstance.fitBounds(routeLayer.getBounds());
    }

    function renderInsights(route, geometry, heatmapData) {
        // Dados iniciais
        const daysOfWeek = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        const periodos = {
            'Madrugada': [0, 1, 2, 3, 4, 5],
            'Manhã': [6, 7, 8, 9, 10, 11],
            'Tarde': [12, 13, 14, 15, 16, 17],
            'Noite': [18, 19, 20, 21, 22, 23]
        };
        const jamLevels = ['Fluido', 'Leve', 'Moderado', 'Intenso', 'Congestionado', 'Parado'];
    
        // Processamento dos dados
        const avgSpeed = parseFloat(route.avg_speed || 0);
        const historicSpeed = parseFloat(route.historic_speed || 0);
        const speedVariation = historicSpeed !== 0 ? ((avgSpeed - historicSpeed) / historicSpeed * 100) : 0;
        
        // Análise de velocidades
        const speeds = heatmapData.map(item => parseFloat(item.avg_speed)).sort((a, b) => a - b);
        const percentis = {
            p25: speeds[Math.floor(speeds.length * 0.25)] || 0,
            p50: speeds[Math.floor(speeds.length * 0.5)] || 0,
            p75: speeds[Math.floor(speeds.length * 0.75)] || 0
        };
    
        // Otimização do processamento
        let totals = { days: {}, hours: {}, periods: {} };
        let counts = { days: {}, hours: {}, periods: {} };
        let maxValues = { day: -Infinity, hour: -Infinity, period: -Infinity };
        let best = { day: '', hour: '', period: '' };
    
        heatmapData.forEach(item => {
            const day = parseInt(item.day_of_week);
            const hour = parseInt(item.hour);
            const speed = parseFloat(item.avg_speed);
    
            // Atualizar totais por dia
            totals.days[day] = (totals.days[day] || 0) + speed;
            counts.days[day] = (counts.days[day] || 0) + 1;
    
            // Atualizar totais por hora
            totals.hours[hour] = (totals.hours[hour] || 0) + speed;
            counts.hours[hour] = (counts.hours[hour] || 0) + 1;
    
            // Atualizar períodos
            const period = Object.entries(periodos).find(([_, hours]) => hours.includes(hour))?.[0];
            if (period) {
                totals.periods[period] = (totals.periods[period] || 0) + speed;
                counts.periods[period] = (counts.periods[period] || 0) + 1;
            }
        });
    
        // Calcular melhores momentos
        Object.entries(totals.days).forEach(([day, total]) => {
            const avg = total / counts.days[day];
            if (avg > maxValues.day) {
                maxValues.day = avg;
                best.day = `${daysOfWeek[day]} (${(new Date().getMonth() + 1).toString().padStart(2, '0')}/${new Date().getFullYear()})`;
            }
        });
    
        Object.entries(totals.hours).forEach(([hour, total]) => {
            const avg = total / counts.hours[hour];
            if (avg > maxValues.hour) {
                maxValues.hour = avg;
                best.hour = `${hour.toString().padStart(2, '0')}h`;
            }
        });
    
        Object.entries(totals.periods).forEach(([period, total]) => {
            const avg = total / counts.periods[period];
            if (avg > maxValues.period) {
                maxValues.period = avg;
                best.period = period;
            }
        });
    
        // Atualização da UI
        const updateElement = (selector, content, attribute = 'innerText') => {
            const element = document.querySelector(selector);
            if (element) element[attribute] = content;
        };
    
        // Velocidades e variação
        updateElement('#mapModal .current-speed', `${avgSpeed.toFixed(1)} km/h`);
        updateElement('#mapModal .historic-speed', `${historicSpeed.toFixed(1)} km/h`);
        updateElement('#mapModal .speed-variation', 
            `${speedVariation.toFixed(1)}% ${speedVariation >= 0 ? '↑' : '↓'}`, 
            'innerHTML'
        );
    
        // Nível de congestionamento
        const jamLevel = parseFloat(route.jam_level || 0);
        updateElement('#mapModal .jam-level', `${jamLevel} - ${jamLevels[jamLevel]}`);
        
        // Melhores momentos
        updateElement('#mapModal .best-day', best.day);
        updateElement('#mapModal .best-hour', best.hour);
        updateElement('#mapModal .best-period', best.period);
    
        // Estatísticas de velocidade
        updateElement('#mapModal .p25-speed', `${percentis.p25.toFixed(1)} km/h`);
        updateElement('#mapModal .p50-speed', `${percentis.p50.toFixed(1)} km/h`);
        updateElement('#mapModal .p75-speed', `${percentis.p75.toFixed(1)} km/h`);
    
        // Visualizações
        updateElement('#mapModal .speed-sparkline', 
            `<div class="sparkline" data-values="${speeds.join(',')}"></div>`, 
            'innerHTML'
        );
    
        // Atualizar elementos interativos
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            new bootstrap.Tooltip(el, { trigger: 'hover' });
        });
    
        // Atualizar progress bars
        const speedDiff = avgSpeed - historicSpeed;
        const progressBar = document.querySelector('#mapModal .speed-progress');
        if (progressBar) {
            progressBar.style.width = `${Math.min(Math.abs(speedDiff), 100)}%`;
            progressBar.className = `progress-bar ${speedDiff >= 0 ? 'bg-success' : 'bg-danger'}`;
            progressBar.setAttribute('data-bs-toggle', 'tooltip');
            progressBar.setAttribute('title', `Diferença: ${speedDiff.toFixed(1)} km/h`);
        }
    
        // Irregularidades
        const irregularities = geometry.filter(p => p.irregularity_id != null).length;
        updateElement('#mapModal .irregularities-count', irregularities);
    }
    
    // Inicializar tooltips ao carregar
    document.addEventListener('DOMContentLoaded', () => {
        const sparklines = document.querySelectorAll('.sparkline');
        sparklines.forEach(spark => {
            const values = spark.dataset.values.split(',').map(Number);
            new Sparkline(spark, {
                width: 100,
                height: 30,
                lineColor: '#4e73df',
                fillColor: '#d1d3e2',
                spotRadius: 3
            });
        });
    });

    function renderHeatmap(heatmapData, route) {
        // Verifica as velocidades mínima e máxima para a rota
        const speeds = heatmapData.map(item => parseFloat(item.avg_speed));
        const minSpeed = Math.min(...speeds);  // Velocidade mínima
        const maxSpeed = Math.max(...speeds);  // Velocidade máxima
    
        // Se não houver dados de velocidade, não cria o gráfico
        if (isNaN(minSpeed) || isNaN(maxSpeed)) {
            alert("Não há dados suficientes para calcular o heatmap de velocidade.");
            return;
        }
    
        // Para garantir que a escala de cores será visualmente distinta,
        // se a variação entre min e max for pequena, vamos ajustar o valor máximo para ser um pouco maior que o máximo.
        const range = maxSpeed - minSpeed;
        const adjustedMax = range < 5 ? maxSpeed + 5 : maxSpeed; // Se a diferença for muito pequena, aumenta o máximo para dar uma melhor distinção
    
        const categories = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        const data = heatmapData.map(item => [
            parseInt(item.hour),
            parseInt(item.day_of_week) - 1,  // Ajustando para os dias da semana começarem em 0
            parseFloat(item.avg_speed)
        ]);
    
        // Criando o gráfico de heatmap com a nova escala de cores
        Highcharts.chart('heatmapChart', {
            chart: {
                type: 'heatmap',
                plotBorderWidth: 1,
                height: 200
            },
            title: null,
            xAxis: {
                categories: Array.from({ length: 24 }, (_, i) => `${i}h`),
                title: null
            },
            yAxis: {
                categories: categories,
                title: null,
                reversed: true
            },
            colorAxis: {
                min: minSpeed,  // Define a velocidade mínima da rota
                max: adjustedMax,  // Define a velocidade máxima ajustada
                minColor: '#FFFFFF',  // Cor para a velocidade mais alta
                maxColor: '#FF0000'  // Cor para a velocidade mais baixa (vermelho)
            },
            legend: { enabled: false },
            series: [{
                name: 'Velocidade Média',
                borderWidth: 1,
                data: data,
                dataLabels: {
                    enabled: true,
                    color: '#000',
                    format: '{point.value:.1f}'  // Exibe a velocidade média
                }
            }]
        });
    }    
});
