// scripts-routes.js

document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('mapModal');
    const mapContainer = document.getElementById('mapContainer');
    let map, heatLayer;

    // Inicializa mapa quando o modal abre
    $('#mapModal').on('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const routeId = button.getAttribute('data-route-id');

        document.getElementById('loadingIndicator').style.display = 'block';
        fetchRouteDetails(routeId);
    });

    function fetchRouteDetails(routeId) {
        fetch(`/api/index.php?action=get_route_details&route_id=${routeId}`)
            .then(res => res.json())
            .then(data => {
                document.getElementById('loadingIndicator').style.display = 'none';
                renderMap(data.geometry);
                renderHeatmap(data.heatmap);
                renderInsights(data);
            })
            .catch(error => {
                document.getElementById('loadingIndicator').style.display = 'none';
                alert('Erro ao carregar os detalhes da rota');
                console.error(error);
            });
    }

    function renderMap(geometry) {
        if (!map) {
            map = L.map(mapContainer).setView([-15.7801, -47.9292], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap'
            }).addTo(map);
        }

        if (heatLayer) heatLayer.remove();

        const latlngs = geometry.map(point => [point.y, point.x]);
        L.polyline(latlngs, { color: 'blue' }).addTo(map);
        map.fitBounds(latlngs);
    }

    function renderHeatmap(heatmapData) {
        const chartContainer = document.getElementById('heatmapChart');

        const hours = [...Array(24).keys()];
        const days = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        const matrix = Array.from({ length: 7 }, () => Array(24).fill(0));

        heatmapData.forEach(row => {
            const day = (row.day_of_week + 5) % 7; // Ajusta para começar no domingo
            matrix[day][row.hour] = row.avg_speed;
        });

        Highcharts.chart(chartContainer, {
            chart: {
                type: 'heatmap',
                height: 250
            },
            title: null,
            xAxis: {
                categories: hours
            },
            yAxis: {
                categories: days,
                title: null
            },
            colorAxis: {
                min: 0,
                minColor: '#FFFFFF',
                maxColor: '#007BFF'
            },
            legend: {
                enabled: false
            },
            series: [{
                borderWidth: 1,
                data: [].concat(...matrix.map((row, y) => row.map((val, x) => [x, y, val]))),
                dataLabels: {
                    enabled: false
                }
            }]
        });
    }

    function renderInsights(data) {
        document.getElementById('modalRouteName').textContent = data.route.name;

        const avg = data.route.avg_speed;
        const hist = data.route.historic_speed;
        const percentDiff = ((avg - hist) / hist * 100).toFixed(0);
        const jamLevel = data.route.jam_level;
        const irregularities = data.geometry.filter(p => p.irregularity_id !== null).length;

        const insights = {
            avg_speed_comparison: {
                percent_diff: percentDiff
            },
            irregularities,
            jam_level: {
                trend: jamLevel >= 4 ? 'high' : 'moderate'
            }
        };

        // Atualiza DOM
        document.querySelector('.card-body h4.text-primary').textContent = `${avg.toFixed(1)} km/h`;
        document.querySelector('.card-body h4.text-secondary').textContent = `${hist.toFixed(1)} km/h`;
        document.querySelector('.progress-bar.bg-success, .progress-bar.bg-danger').style.width = `${Math.abs(percentDiff)}%`;
        document.querySelector('.text-danger.h2').textContent = insights.irregularities;
        document.querySelector('.progress-bar.bg-danger, .progress-bar.bg-warning').style.width = `${(jamLevel / 5) * 100}%`;
        document.querySelector('.badge.ml-2').textContent = `Nível ${jamLevel}`;
    }
});
