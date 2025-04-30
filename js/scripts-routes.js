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
                renderInsights(route, geometry);
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
        // Isso evita que a escala de cores seja muito estreita e o gráfico todo se torne vermelho.
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
