$(document).ready(function () {
    // Variáveis para o mapa
    let map;
    const markersLayer = L.layerGroup(); // Para gerenciar os marcadores

    // Função para inicializar o mapa no modal
    function initMap(lat, lon, alertType, city, street, uuid, status, confidence) {
        if (!map) {
            map = L.map('map').setView([lat, lon], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }).addTo(map);
        }

        markersLayer.clearLayers(); // Limpar marcadores antigos

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
        map.setView([lat, lon], 13); // Ajustar visualização do mapa
    }

    // Função para verificar dados de localização antes de inicializar o mapa
    function isValidLocation(lat, lon) {
        return lat && lon && lat !== 'N/A' && lon !== 'N/A';
    }

    // Quando o botão "Visualizar no mapa" for clicado, abrir o modal do mapa
    $('#view-on-map').click(function () {
        const lat = $('#modal-location').data('lat');
        const lon = $('#modal-location').data('lon');
        const alertType = $('#modal-type').text();
        const city = $('#modal-city').text();
        const street = $('#modal-street').text();
        const uuid = $('#modal-uuid').text();
        const status = $('#modal-status').text();
        const confidence = $('#modal-confidence').text();

        if (!isValidLocation(lat, lon)) {
            alert('Dados de localização inválidos. Não foi possível mostrar o mapa.');
            return;
        }

        initMap(lat, lon, alertType, city, street, uuid, status, confidence);

        $('#mapModal').modal('show').one('shown.bs.modal', function () {
            map.invalidateSize(); // Atualiza o tamanho do mapa
        });
    });

    // Preencher o modal com os dados do alerta
    $('button[data-toggle="modal"]').click(function () {
        const alertData = $(this).data('alert');
    
        if (!alertData) {
            console.error('No alert data provided.');
            return;
        }
    
        // Limpar o conteúdo do modal antes de preencher com novos dados
        $('#modal-type-title').text('');
        $('#modal-uuid').text('');
        $('#modal-city').text('');
        $('#modal-street').text('');
        $('#modal-type').text('');
        $('#modal-status').text('');
        $('#modal-location').text('');
        $('#modal-date-received').text('');
        $('#modal-subtype').text('');
        $('#modal-confidence').text('');
        $('#modal-km').text('');  // Limpar o valor de 'km'
    
        // Preencher os dados do modal
        $('#modal-type-title').text(alertData.type);
        $('#modal-uuid').text(alertData.uuid || 'N/A');
        $('#modal-city').text(alertData.city || 'N/A');
        $('#modal-street').text(alertData.street || 'N/A');
        $('#modal-type').text(alertData.type || 'N/A');
        $('#modal-status').text(alertData.status === 1 ? "Ativo" : "Inativo");
        $('#modal-location').text(`${alertData.location_x || 'N/A'}, ${alertData.location_y || 'N/A'}`);
        $('#modal-date-received').text(alertData.date_received);
        $('#modal-subtype').text(alertData.subtype || 'N/A');
        $('#modal-confidence').text(alertData.confidence || 'N/A');
        // Definir a latitude e longitude para o uso no modal de mapa
        $('#modal-location').data('lat', alertData.location_y || 'N/A');
        $('#modal-location').data('lon', alertData.location_x || 'N/A');
    
        // Adicionando a chamada ao DENIT
        const denitUrl = `https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm?lng=${alertData.location_x}&lat=${alertData.location_y}&r=250&data=${new Date().toISOString().split('T')[0]}`;
        console.log('Consultando o DENIT com a URL:', denitUrl);
    
        // Fazer a requisição
        $.ajax({
            url: denitUrl,
            type: 'GET',
            success: function (response) {
                if (response && response.length > 0) {
                    const result = response[0]; // Obtém o primeiro resultado da lista
                    console.log('Resposta do DENIT:', result);
    
                    // Verificar e exibir o km se estiver presente, com 2 casas decimais
                    if (result.km) {
                        const kmFormatted = parseFloat(result.km).toFixed(2);
                        $('#modal-km').text(kmFormatted); // Inserir o valor de km no modal
                    }
                } else {
                    alert('Nenhum dado encontrado para as coordenadas fornecidas.');
                }
            },
            error: function (xhr, status, error) {
                console.error('Erro ao consultar o DENIT:', error);
                alert('Erro ao consultar os dados do DENIT. Tente novamente.');
            }
        });
    });
    
        

    // Atualizar o mapa quando a janela for redimensionada
    $(window).on('resize', function () {
        if (map) {
            map.invalidateSize(); // Redimensiona o mapa quando a janela for redimensionada
        }
    });
});

// Atualizar cores das linhas com base na data do alerta
document.addEventListener("DOMContentLoaded", function () {
    const dateCells = document.querySelectorAll(".alert-date");

    // Função para calcular a cor com base na diferença de tempo
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
            return { bgColor: "", textColor: "" }; // Cor padrão
        }
    }

    // Função para atualizar as cores das linhas
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

    updateRowColors(); // Atualiza cores na inicialização
    setInterval(updateRowColors, 60000); // Atualiza periodicamente a cada 1 minuto
});


document.addEventListener("DOMContentLoaded", function () {
    const mapModal = document.getElementById('mapModal');
    const routeMapContainer = document.getElementById('mapContainer');
    const loadingIndicator = document.getElementById('loadingIndicator');
    let routeMap;

    // Inicializa o mapa de rotas se ainda não existir
    function initializeRouteMap() {
        if (!routeMap) {
            console.log("Inicializando mapa...");
            routeMap = L.map(routeMapContainer).setView([0, 0], 2); // Centro inicial genérico
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(routeMap);
        }
    }

    // Limpa o mapa de rotas
    function clearRouteMap() {
        console.log("Limpando o mapa...");
        if (routeMap) {
            routeMap.eachLayer((layer) => {
                if (!(layer instanceof L.TileLayer)) { // Mantém apenas a camada base
                    routeMap.removeLayer(layer);
                }
            });
        }
    }

    // Mostra o indicador de carregamento
    function showLoadingIndicator() {
        console.log("Mostrando indicador de carregamento...");
        loadingIndicator.style.display = 'block';
    }

    // Oculta o indicador de carregamento
    function hideLoadingIndicator() {
        console.log("Ocultando indicador de carregamento...");
        loadingIndicator.style.display = 'none';
    }

    // Função para carregar as linhas de rota com subrotas ativas e velocidades
    function loadRouteLinesWithSubroutes(routeId) {
        showLoadingIndicator(); // Mostrar indicador de carregamento

        // Carregar as linhas da rota principal
        fetch(`../wazeportal/api.php?action=get_route_lines&route_id=${routeId}`)
            .then(response => response.json())
            .then(routeData => {
                console.log("Dados da rota:", routeData);
                if (Array.isArray(routeData) && routeData.length > 0) {
                    const lineCoordinates = [];
                    let latSum = 0, lngSum = 0;
                    let validPointsCount = 0;

                    // Iterar sobre os pontos da rota principal e adicionar ao array de coordenadas
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

                    // Calcular a posição central da rota
                    const latAvg = latSum / validPointsCount;
                    const lngAvg = lngSum / validPointsCount;

                    // Desenhar a rota principal no mapa
                    L.polyline(lineCoordinates, { color: 'blue' }).addTo(routeMap);
                    routeMap.setView([latAvg, lngAvg], 12); // Zoom ajustado para se adaptar melhor à rota

                    // Agora, carregar as subrotas ativas
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

    // Função para desenhar as subrotas corretamente usando os pontos de rota
   // Função para desenhar as subrotas corretamente
function drawSubrouteOnSegments(subroute) {
    const subrouteCoordinates = subroute.route_points.map(point => [parseFloat(point.y), parseFloat(point.x)]);

    // Calcular a cor da subrota com base na velocidade média
    const color = getColorForSpeed(subroute.avg_speed);

    // Desenha a linha da subrota, conectando os pontos
    const polyline = L.polyline(subrouteCoordinates, {
        color: color, // Cor definida pela velocidade média
        weight: 6,
        opacity: 0.8
    }).addTo(routeMap);

    // Armazenar a cor original para restaurar mais tarde
    polyline.originalColor = color;

    // Adicionar informações ao clicar sobre a subrota
    polyline.on('click', function () {
        const distance = calculateDistance(subrouteCoordinates);
        
        // Garantir que avg_speed seja um número válido antes de usar toFixed()
        const avgSpeed = parseFloat(subroute.avg_speed);
        const formattedSpeed = !isNaN(avgSpeed) ? avgSpeed.toFixed(2) : 'N/A'; // Valor padrão caso não seja um número

        const time = (distance / avgSpeed) * 60; // Tempo em minutos (distância / velocidade)
        const formattedTime = !isNaN(time) && time > 0 ? time.toFixed(2) : 'N/A'; // Valor padrão caso não seja um número
        const formattedDistance = distance.toFixed(2); // Distância formatada

        // Exibir o balão com as informações de velocidade, tempo e distância
        polyline.bindPopup(`
            <strong>Detalhes do trecho</strong><br>
            <b>Velocidade Média:</b> ${formattedSpeed} km/h<br>
            <b>Distância:</b> ${formattedDistance} km<br>
            <b>Tempo estimado:</b> ${formattedTime} minutos
        `).openPopup();

        // Centralizar o mapa no segmento clicado
        routeMap.setView(polyline.getBounds().getCenter(), 14); // Centraliza no meio da linha com zoom 14

        // Destacar o trecho clicado com uma cor diferente
        polyline.setStyle({
            color: 'purple', // Cor para destacar o trecho
            weight: 8,       // Aumentar a espessura da linha para destacá-la
            opacity: 1       // Aumentar a opacidade para tornar a linha mais visível
        });

        // Restaurar a cor original de outras linhas
        routeMap.eachLayer(function(layer) {
            if (layer instanceof L.Polyline && layer !== polyline) {
                layer.setStyle({
                    color: layer.originalColor, // Restaura a cor original baseada na velocidade média
                    weight: 6,
                    opacity: 0.8
                });
            }
        });
    });

    // Evento para restaurar a cor da linha ao fechar o popup (ao clicar fora)
    polyline.on('popupclose', function () {
        polyline.setStyle({
            color: polyline.originalColor, // Restaurar a cor original
            weight: 6,
            opacity: 0.8
        });
    });
}

// Função para calcular a distância entre dois pontos (em quilômetros)
function calculateDistance(coords) {
    let totalDistance = 0;

    for (let i = 0; i < coords.length - 1; i++) {
        const lat1 = coords[i][0], lon1 = coords[i][1];
        const lat2 = coords[i + 1][0], lon2 = coords[i + 1][1];

        // Calcular a distância usando a fórmula de Haversine
        const R = 6371; // Raio da Terra em km
        const φ1 = lat1 * Math.PI / 180;
        const φ2 = lat2 * Math.PI / 180;
        const Δφ = (lat2 - lat1) * Math.PI / 180;
        const Δλ = (lon2 - lon1) * Math.PI / 180;

        const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2) +
                  Math.cos(φ1) * Math.cos(φ2) *
                  Math.sin(Δλ / 2) * Math.sin(Δλ / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        totalDistance += R * c; // Distância em km
    }

    return totalDistance;
}

    // Função para calcular a distância Haversine entre dois pontos (em km)
    function haversineDistance(lat1, lon1, lat2, lon2) {
        const R = 6371; // Raio da Terra em km
        const dLat = toRad(lat2 - lat1);
        const dLon = toRad(lon2 - lon1);
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                  Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
                  Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c; // Distância em km
    }

    // Função para converter graus para radianos
    function toRad(degrees) {
        return degrees * Math.PI / 180;
    }

    // Função para carregar as subrotas e desenhá-las sobre a rota principal
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
                console.log("Dados das subrotas:", subroutes);
                if (Array.isArray(subroutes) && subroutes.length > 0) {
                    subroutes.forEach((subroute, index) => {
                        // Desenhar a rota dentro da caixa com a cor baseada na velocidade média
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

    // Função para obter cor baseada na velocidade média
    function getColorForSpeed(avgSpeed) {
        if (avgSpeed < 30) {
            return 'red'; // Atraso (velocidade baixa)
        } else if (avgSpeed < 60) {
            return 'orange'; // Velocidade moderada
        } else {
            return 'green'; // Alta velocidade
        }
    }

    // Evento para botão "VER +" associado a rotas
    document.querySelectorAll('.view-route').forEach(button => {
        button.addEventListener('click', function () {
            const routeId = this.getAttribute('data-route-id');
            console.log(`Botão clicado, carregando a rota com ID: ${routeId}`);

            clearRouteMap();         // Limpar o mapa antes de carregar nova rota
            initializeRouteMap();    // Inicializar o mapa, se necessário

            // Carregar a rota com subrotas
            loadRouteLinesWithSubroutes(routeId);

            // Mostrar o modal
            $('#mapModal').modal('show');
        });
    });

    // Atualizar tamanho do mapa quando o modal for exibido
    $('#mapModal').on('shown.bs.modal', function () {
        console.log("Modal aberto, atualizando o tamanho do mapa...");
        if (routeMap) {
            routeMap.invalidateSize(); // Corrige problemas de redimensionamento do mapa
        }
    });
});

