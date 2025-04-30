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
        // Exibindo as velocidades (atual e histórica)
        const avgSpeed = parseFloat(route.avg_speed || 0);
        const historicSpeed = parseFloat(route.historic_speed || 0);
        
        // Exibindo irregularidades (pontos críticos na rota)
        const irregularities = geometry.filter(p => p.irregularity_id != null).length;
    
        // Exibindo o nível de congestionamento (de 0 a 5)
        const jamLevel = parseFloat(route.jam_level || 0);
    
        // Definindo variáveis para o melhor dia e horário
        let bestDay = '';
        let bestTime = '';
        let maxSpeed = -Infinity;
        let minSpeed = Infinity;
    
        let totalSpeedByDay = {};  // Armazenando a soma das velocidades por dia
        let totalSpeedByHour = {};  // Armazenando a soma das velocidades por hora
        let countByDay = {};  // Contador para dias
        let countByHour = {};  // Contador para horas
    
        heatmapData.forEach(item => {
            const day = parseInt(item.day_of_week);  // Dia da semana (0 a 6)
            const hour = parseInt(item.hour);  // Hora do dia (0 a 23)
            const speed = parseFloat(item.avg_speed);
    
            // Identificando a maior e a menor velocidade
            if (speed > maxSpeed) maxSpeed = speed;
            if (speed < minSpeed) minSpeed = speed;
    
            // Somando as velocidades por dia e hora
            if (!totalSpeedByDay[day]) totalSpeedByDay[day] = 0;
            if (!totalSpeedByHour[hour]) totalSpeedByHour[hour] = 0;
            if (!countByDay[day]) countByDay[day] = 0;
            if (!countByHour[hour]) countByHour[hour] = 0;
    
            totalSpeedByDay[day] += speed;
            totalSpeedByHour[hour] += speed;
            countByDay[day] += 1;
            countByHour[hour] += 1;
        });
    
        // Calculando a média de velocidade por dia e hora
        let maxAvgSpeedByDay = -Infinity;
        let maxAvgSpeedByHour = -Infinity;
        
        for (let day in totalSpeedByDay) {
            const avgSpeedByDay = totalSpeedByDay[day] / countByDay[day];
            if (avgSpeedByDay > maxAvgSpeedByDay) {
                maxAvgSpeedByDay = avgSpeedByDay;
                bestDay = `${(day + 1).toString().padStart(2, '0')}/${(new Date().getFullYear()).toString()}`
            }
        }
        
        for (let hour in totalSpeedByHour) {
            const avgSpeedByHour = totalSpeedByHour[hour] / countByHour[hour];
            if (avgSpeedByHour > maxAvgSpeedByHour) {
                maxAvgSpeedByHour = avgSpeedByHour;
                bestTime = `${hour.toString().padStart(2, '0')}h`;
            }
        }
    
        // Exibindo as informações no modal
        document.querySelector('#mapModal .card-body .text-primary').innerText = `${avgSpeed.toFixed(1)} km/h`;
        document.querySelector('#mapModal .card-body .text-secondary').innerText = `${historicSpeed.toFixed(1)} km/h`;
    
        // Calculando a diferença entre a velocidade atual e a histórica
        const speedDiff = avgSpeed - historicSpeed;
    
        // Atualizando a barra de progresso para comparar a velocidade
        const progressBar = document.querySelector('#mapModal .progress-bar');
        progressBar.style.width = `${Math.abs(speedDiff)}%`;
        progressBar.classList.remove('bg-danger', 'bg-success');
        progressBar.classList.add(speedDiff >= 0 ? 'bg-success' : 'bg-danger');
    
        // Irregularidades
        document.querySelector('#mapModal .card-body .text-danger').innerText = irregularities;
    
        // Nível de congestionamento
        const jamPercent = (jamLevel / 5) * 100;
        const jamBar = document.querySelector('#mapModal .card-body .progress-bar.bg-warning, #mapModal .card-body .progress-bar.bg-danger');
        if (jamBar) {
            jamBar.style.width = `${jamPercent}%`;
        }
    
        const jamBadge = document.querySelector('#mapModal .badge');
        if (jamBadge) {
            jamBadge.innerText = `Nível ${jamLevel}`;
            jamBadge.classList.remove('badge-warning', 'badge-danger');
            jamBadge.classList.add(jamLevel >= 4 ? 'badge-danger' : 'badge-warning');
        }
    
        // Exibindo as informações calculadas
        const bestDayElement = document.querySelector('#mapModal .best-day');
        const bestTimeElement = document.querySelector('#mapModal .best-time');
        const maxSpeedElement = document.querySelector('#mapModal .max-speed');
        const minSpeedElement = document.querySelector('#mapModal .min-speed');
    
        if (bestDayElement) bestDayElement.innerText = `Melhor Dia: ${bestDay}`;
        if (bestTimeElement) bestTimeElement.innerText = `Melhor Horário: ${bestTime}`;
        if (maxSpeedElement) maxSpeedElement.innerText = `Maior Velocidade: ${maxSpeed.toFixed(1)} km/h`;
        if (minSpeedElement) minSpeedElement.innerText = `Menor Velocidade: ${minSpeed.toFixed(1)} km/h`;
    }    

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
