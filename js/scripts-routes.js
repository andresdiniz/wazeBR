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

    function renderInsights(route, geometry) {
        const avgSpeed = parseFloat(route.avg_speed || 0);
        const historicSpeed = parseFloat(route.historic_speed || 0);
        const irregularities = geometry.filter(p => p.irregularity_id != null).length;
        const jamLevel = parseFloat(route.jam_level || 0);
        
        // Melhor dia e horário
        const bestDay = "12/12/2025"; // Exemplo de data; substitua pela lógica real
        const bestTime = "15:00"; // Exemplo de horário; substitua pela lógica real
        
        const maxSpeed = Math.max(...geometry.map(p => p.speed));
        const minSpeed = Math.min(...geometry.map(p => p.speed));
    
        // Exibindo as informações
        document.querySelector('#mapModal .card-body .text-primary').innerText = `${avgSpeed.toFixed(1)} km/h`;
        document.querySelector('#mapModal .card-body .text-secondary').innerText = `${historicSpeed.toFixed(1)} km/h`;
    
        const speedDiff = avgSpeed - historicSpeed;
        const progressBar = document.querySelector('#mapModal .progress-bar');
        progressBar.style.width = `${Math.abs(speedDiff)}%`;
        progressBar.classList.remove('bg-danger', 'bg-success');
        progressBar.classList.add(speedDiff >= 0 ? 'bg-success' : 'bg-danger');
    
        document.querySelector('#mapModal .card-body .text-danger').innerText = irregularities;
    
        const jamPercent = (jamLevel / 5) * 100;
        const jamBar = document.querySelector('#mapModal .card-body .progress-bar.bg-warning, .progress-bar.bg-danger');
        if (jamBar) {
            jamBar.style.width = `${jamPercent}%`;
        }
    
        const jamBadge = document.querySelector('#mapModal .badge');
        if (jamBadge) {
            jamBadge.innerText = `Nível ${jamLevel}`;
            jamBadge.classList.remove('badge-warning', 'badge-danger');
            jamBadge.classList.add(jamLevel >= 4 ? 'badge-danger' : 'badge-warning');
        }
    
        // "Pitada especial" com base no estado atual da via
        const specialInsight = getSpecialInsight(avgSpeed, historicSpeed, jamLevel, bestDay, bestTime, maxSpeed, minSpeed, irregularities);
        document.querySelector('#mapModal .card-body .special-insight').innerText = specialInsight;
    }
    
    function getSpecialInsight(avgSpeed, historicSpeed, jamLevel, bestDay, bestTime, maxSpeed, minSpeed, irregularities) {
        let insight = '';
    
        // Comparando a velocidade média com a histórica
        if (avgSpeed > historicSpeed) {
            insight += `A velocidade média de hoje está melhor do que a histórica. `;
        } else {
            insight += `A velocidade média de hoje está abaixo da histórica. `;
        }
    
        // Detalhes sobre o congestionamento
        if (jamLevel >= 4) {
            insight += `O nível de congestionamento está alto (Nível ${jamLevel}), evite sair agora. `;
        } else if (jamLevel >= 2) {
            insight += `O tráfego está moderado (Nível ${jamLevel}), pode ser um bom momento para viajar. `;
        } else {
            insight += `O tráfego está fluindo bem, aproveite para sair agora! `;
        }
    
        // Melhores horários e dias
        insight += `O melhor dia para essa rota foi ${bestDay}, e o melhor horário foi por volta de ${bestTime}.`;
    
        // Maior e menor velocidade
        insight += `A maior velocidade registrada foi ${maxSpeed} km/h, e a menor foi ${minSpeed} km/h.`;
    
        // Irregularidades detectadas
        if (irregularities > 0) {
            insight += `Foram detectadas ${irregularities} irregularidades ao longo da rota, fique atento.`;
        }
    
        return insight;
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
