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
                renderHeatmap(heatmap);
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

    function renderHeatmap(heatmapData) {
        const categories = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        const data = heatmapData.map(item => [
            parseInt(item.hour), 
            parseInt(item.day_of_week) - 1,
            parseFloat(item.avg_speed)
        ]);

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
                min: 0,
                minColor: '#FFFFFF',
                maxColor: '#FF0000'  // Ajusta para vermelho mais escuro nas velocidades mais baixas
            },
            legend: { enabled: false },
            series: [{
                name: 'Velocidade Média',
                borderWidth: 1,
                data: data,
                dataLabels: {
                    enabled: true,
                    color: '#000',
                    format: '{point.value:.1f}'
                }
            }]
        });
    }

    function renderInsights(route, geometry) {
        const avgSpeed = parseFloat(route.avg_speed || 0);
        const historicSpeed = parseFloat(route.historic_speed || 0);
        const irregularities = geometry.filter(p => p.irregularity_id != null).length;
        const jamLevel = parseFloat(route.jam_level || 0);

        // Velocidade
        document.querySelector('#mapModal .card-body .text-primary').innerText = `${avgSpeed.toFixed(1)} km/h`;
        document.querySelector('#mapModal .card-body .text-secondary').innerText = `${historicSpeed.toFixed(1)} km/h`;

        const speedDiff = avgSpeed - historicSpeed;
        const progressBar = document.querySelector('#mapModal .progress-bar');
        progressBar.style.width = `${Math.abs(speedDiff)}%`;
        progressBar.classList.remove('bg-danger', 'bg-success');
        progressBar.classList.add(speedDiff >= 0 ? 'bg-success' : 'bg-danger');

        // Irregularidades
        document.querySelector('#mapModal .card-body .text-danger').innerText = irregularities;

        // Congestionamento
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
    }
});
