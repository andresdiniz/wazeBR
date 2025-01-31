
// Usar jQuery em modo sem conflito
var $j = jQuery.noConflict();

$j(document).ready(function() {
    // Inicializa os DataTables
    initializeDataTables();
    
    // Configura o modal de alerta
    setupAlertModal();

    // Configura o mapa e eventos relacionados
    setupMap();

    // Configura a atualização das cores das linhas com base na data do alerta
    setupRowColorUpdate();

    // Configura o mapa de rotas e subrotas
    setupRouteMap();

    // Configura o logout
    setupLogout();

    // Configura a confirmação de alerta
    setupAlertConfirmation();
});

function initializeDataTables() {
    const tables = ['#accidentsTable', '#trafficTable', '#jamsTable', '#hazardsTable', '#jamAlertsTable', '#otherAlertsTable'];
    tables.forEach(table => {
        if (!$j.fn.DataTable.isDataTable(table)) {
            $j(table).DataTable({
                responsive: true,
                autoWidth: false,
                paging: true,
                searching: true,
                info: true,
                language: {
                    search: "Buscar:",
                    paginate: {
                        next: "Próximo",
                        previous: "Anterior",
                    },
                },
            });
        }
    });
}

function setupAlertModal() {
    $j('#alertModal').on('show.bs.modal', function(event) {
        var button = $j(event.relatedTarget);
        var alertData = button.data('alert');

        if (!alertData) {
            console.error("Erro: Não foi possível obter os dados do alerta.");
            return;
        }

        var modal = $j(this);
        modal.find('#modal-uuid').text(alertData.uuid || 'N/A');
        modal.find('#modal-city').text(alertData.city || 'N/A');
        modal.find('#modal-street').text(alertData.street || 'N/A');
        modal.find('#modal-location').text(`Lat: ${alertData.location_x || 'N/A'}, Lon: ${alertData.location_y || 'N/A'}`);
        modal.find('#modal-date-received').text(new Date(alertData.pubMillis).toLocaleString() || 'N/A');
        modal.find('#modal-confidence').text(alertData.confidence || 'N/A');
        modal.find('#modal-type').text('Alerta');
        modal.find('#modal-subtype').text('N/A');

        var status = alertData.status === 1 ? 'Ativo' : (alertData.status === 0 ? 'Inativo' : 'N/A');
        modal.find('#modal-status').text(status);
    });
}

function setupMap() {
    let map;
    const markersLayer = L.layerGroup();

    function initMap(lat, lon, alertType, city, street, uuid, status, confidence) {
        if (!map) {
            map = L.map('map').setView([lat, lon], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }).addTo(map);
        }

        markersLayer.clearLayers();

        const popupContent = `
            <strong>${alertType}</strong><br>
            <strong>Cidade:</strong> ${city || 'N/A'}<br>
            <strong>Rua:</strong> ${street || 'N/A'}<br>
            <strong>UUID:</strong> ${uuid || 'N/A'}<br>
            <strong>Status:</strong> ${status || 'N/A'}<br>
            <strong>Confiança:</strong> ${confidence || 'N/A'}
        `;

        const marker = L.marker([lat, lon])
            .addTo(markersLayer)
            .bindPopup(popupContent)
            .openPopup();

        markersLayer.addTo(map);
        map.setView([lat, lon], 13);
    }

    function isValidLocation(lat, lon) {
        return lat && lon && lat !== 'N/A' && lon !== 'N/A';
    }

    $j('#view-on-map').click(function () {
        const lat = $j('#modal-location').data('lat');
        const lon = $j('#modal-location').data('lon');
        const alertType = $j('#modal-type').text();
        const city = $j('#modal-city').text();
        const street = $j('#modal-street').text();
        const uuid = $j('#modal-uuid').text();
        const status = $j('#modal-status').text();
        const confidence = $j('#modal-confidence').text();

        if (!isValidLocation(lat, lon)) {
            alert('Dados de localização inválidos. Não foi possível mostrar o mapa.');
            return;
        }

        initMap(lat, lon, alertType, city, street, uuid, status, confidence);

        $j('#mapModal').modal('show').one('shown.bs.modal', function () {
            map.invalidateSize();
        });
    });

    $j(window).on('resize', function () {
        if (map) {
            map.invalidateSize();
        }
    });
}

function setupRowColorUpdate() {
    const dateCells = document.querySelectorAll(".alert-date");

    function getRowColor(minutesDiff) {
        if (minutesDiff <= 5) {
            return { bgColor: "#ff0000", textColor: "blue" };
        } else if (minutesDiff <= 15) {
            return { bgColor: "#ff3333", textColor: "blue" };
        } else if (minutesDiff <= 30) {
            return { bgColor: "#ff6666", textColor: "blue" };
        } else if (minutesDiff <= 60) {
            return { bgColor: "#ff9999", textColor: "blue" };
        } else if (minutesDiff <= 90) {
            return { bgColor: "#ffcccc", textColor: "blue" };
        } else {
            return { bgColor: "", textColor: "" };
        }
    }

    function updateRowColors() {
        const now = new Date().getTime();

        dateCells.forEach((cell) => {
            const eventMillis = parseInt(cell.getAttribute("data-pubmillis"), 10);
            const minutesDiff = (now - eventMillis) / (1000 * 60);

            const row = cell.parentElement;
            const { bgColor, textColor } = getRowColor(minutesDiff);

            row.style.backgroundColor = bgColor;
            row.style.color = textColor;
        });
    }

    updateRowColors();
    setInterval(updateRowColors, 60000);
}

function setupRouteMap() {
    const mapModal = document.getElementById('mapModal');
    const routeMapContainer = document.getElementById('mapContainer');
    const loadingIndicator = document.getElementById('loadingIndicator');
    let routeMap;

    function initializeRouteMap() {
        if (!routeMap) {
            routeMap = L.map(routeMapContainer).setView([0, 0], 2);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(routeMap);
        }
    }

    function clearRouteMap() {
        if (routeMap) {
            routeMap.eachLayer((layer) => {
                if (!(layer instanceof L.TileLayer)) {
                    routeMap.removeLayer(layer);
                }
            });
        }
    }

    function showLoadingIndicator() {
        loadingIndicator.style.display = 'block';
    }

    function hideLoadingIndicator() {
        loadingIndicator.style.display = 'none';
    }

    function loadRouteLinesWithSubroutes(routeId) {
        showLoadingIndicator();

        fetch(`../wazeportal/api.php?action=get_route_lines&route_id=${routeId}`)
            .then(response => response.json())
            .then(routeData => {
                if (Array.isArray(routeData) && routeData.length > 0) {
                    const lineCoordinates = [];
                    let latSum = 0, lngSum = 0;
                    let validPointsCount = 0;

                    routeData.forEach(point => {
                        const x = parseFloat(point.x);
                        const y = parseFloat(point.y);

                        if (!isNaN(x) && !isNaN(y) && x >= -180 && x <= 180 && y >= -90 && y <= 90) {
                            lineCoordinates.push([y, x]);
                            latSum += y;
                            lngSum += x;
                            validPointsCount++;
                        }
                    });

                    const latAvg = latSum / validPointsCount;
                    const lngAvg = lngSum / validPointsCount;

                    L.polyline(lineCoordinates, { color: 'blue' }).addTo(routeMap);
                    routeMap.setView([latAvg, lngAvg], 12);

                    loadSubroutes(routeId, lineCoordinates);
                } else {
                    console.error("Nenhum dado válido para a rota.");
                    alert("Não foi possível carregar os dados da rota.");
                }
            })
            .catch(error => {
                console.error("Erro ao carregar ou processar a rota:", error);
                alert("Ocorreu um erro ao carregar a rota.");
            })
            .finally(() => {
                hideLoadingIndicator();
            });
    }

    function drawSubrouteOnSegments(subroute) {
        const subrouteCoordinates = subroute.route_points.map(point => [parseFloat(point.y), parseFloat(point.x)]);
        const color = getColorForSpeed(subroute.avg_speed);

        const polyline = L.polyline(subrouteCoordinates, {
            color: color,
            weight: 6,
            opacity: 0.8
        }).addTo(routeMap);

        polyline.originalColor = color;

        polyline.on('click', function () {
            const distance = calculateDistance(subrouteCoordinates);
            const avgSpeed = parseFloat(subroute.avg_speed);
            const formattedSpeed = !isNaN(avgSpeed) ? avgSpeed.toFixed(2) : 'N/A';
            const time = (distance / avgSpeed) * 60;
            const formattedTime = !isNaN(time) && time > 0 ? time.toFixed(2) : 'N/A';
            const formattedDistance = distance.toFixed(2);

            polyline.bindPopup(
                `<strong>Detalhes do trecho</strong><br>
                <b>Velocidade Média:</b> ${formattedSpeed} km/h<br>
                <b>Distância:</b> ${formattedDistance} km<br>
                <b>Tempo estimado:</b> ${formattedTime} minutos`
            ).openPopup();

            routeMap.setView(polyline.getBounds().getCenter(), 14);

            polyline.setStyle({
                color: 'purple',
                weight: 8,
                opacity: 1
            });

            routeMap.eachLayer(function(layer) {
                if (layer instanceof L.Polyline && layer !== polyline) {
                    layer.setStyle({
                        color: layer.originalColor,
                        weight: 6,
                        opacity: 0.8
                    });
                }
            });
        });

        polyline.on('popupclose', function () {
            polyline.setStyle({
                color: polyline.originalColor,
                weight: 6,
                opacity: 0.8
            });
        });
    }

    function calculateDistance(coords) {
        let totalDistance = 0;

        for (let i = 0; i < coords.length - 1; i++) {
            const lat1 = coords[i][0], lon1 = coords[i][1];
            const lat2 = coords[i + 1][0], lon2 = coords[i + 1][1];

            const R = 6371;
            const φ1 = lat1 * Math.PI / 180;
            const φ2 = lat2 * Math.PI / 180;
            const Δφ = (lat2 - lat1) * Math.PI / 180;
            const Δλ = (lon2 - lon1) * Math.PI / 180;

            const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2) +
                        Math.cos(φ1) * Math.cos(φ2) *
                        Math.sin(Δλ / 2) * Math.sin(Δλ / 2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            totalDistance += R * c;
        }

        return totalDistance;
    }

    function loadSubroutes(routeId, routeCoordinates) {
        showLoadingIndicator();

        fetch(`../wazeportal/api.php?action=get_subroutes&route_id=${routeId}&is_active=1`)
            .then(response => {
                if (!response.ok) {
                    throw new Error("Erro ao carregar as subrotas");
                }
                return response.json();
            })
            .then(subroutes => {
                if (Array.isArray(subroutes) && subroutes.length > 0) {
                    subroutes.forEach((subroute, index) => {
                        drawSubrouteOnSegments(subroute);
                    });
                } else {
                    console.log("Nenhuma subrota ativa encontrada.");
                }
            })
            .catch(error => {
                console.error("Erro ao carregar ou processar as subrotas:", error);
                alert("Ocorreu um erro ao carregar as subrotas.");
            })
            .finally(() => {
                hideLoadingIndicator();
            });
    }

    function getColorForSpeed(avgSpeed) {
        if (avgSpeed < 30) {
            return 'red';
        } else if (avgSpeed < 60) {
            return 'orange';
        } else {
            return 'green';
        }
    }

    document.querySelectorAll('.view-route').forEach(button => {
        button.addEventListener('click', function () {
            const routeId = this.getAttribute('data-route-id');
            console.log(`Botão clicado, carregando a rota com ID: ${routeId}`);

            clearRouteMap();
            initializeRouteMap();

            loadRouteLinesWithSubroutes(routeId);

            $('#mapModal').modal('show');
        });
    });

    $('#mapModal').on('shown.bs.modal', function () {
        if (routeMap) {
            routeMap.invalidateSize();
        }
    });
}

function setupLogout() {
    function deleteAllCookies() {
        var cookies = document.cookie.split(";");

        for (var i = 0; i < cookies.length; i++) {
            var cookie = cookies[i];
            var eqPos = cookie.indexOf("=");
            var name = eqPos > -1 ? cookie.substr(0, eqPos) : cookie;

            document.cookie = name + "=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/";
        }
    }

    function destroySession() {
        sessionStorage.clear();
        localStorage.clear();
    }

    function logout() {
        deleteAllCookies();
        destroySession();
        window.location.href = "login.html";
    }

    document.querySelector('.btn-primary').addEventListener('click', logout);
}

function setupAlertConfirmation() {
    function confirmarAlerta(uuid) {
        console.log('Confirmar alerta:', uuid);
        $j.ajax({
            url: '/api.php?action=confirm_alert',
            type: 'POST',
            data: { uuid: uuid, status: 1 },
            success: function(response) {
                alert('Alerta confirmado com sucesso!');
                $('#alertModal').modal('hide');
            },
            error: function(xhr, status, error) {
                alert('Erro ao confirmar o alerta. Tente novamente.');
            },
        });
    }

    function confirmarAlertaModal(uuid) {
        console.log('Confirmar alerta clicado');

        if (uuid) {
            console.log('UUID:', uuid);
            confirmarAlerta(uuid);
        } else {
            console.log('Erro: UUID não encontrado');
        }
    }

    document.querySelectorAll('.confirm-alert').forEach(button => {
        button.addEventListener('click', function () {
            const uuid = this.getAttribute('data-uuid');
            confirmarAlertaModal(uuid);
        });
    });
}