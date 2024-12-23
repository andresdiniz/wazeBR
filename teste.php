<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapa OSM com Leaflet - Plotando Way</title>
    <!-- Incluindo o CSS do Leaflet -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <style>
        #map {
            height: 500px; /* Tamanho do mapa */
        }
    </style>
</head>
<body>
    <h1>Mapa OSM com Leaflet - Way</h1>
    <div id="map"></div>

    <!-- Incluindo o script do Leaflet -->
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script>
        // Inicializando o mapa e definindo o centro e o zoom
        var map = L.map('map').setView([40.7128, -74.0060], 13); // Posição inicial no mapa (coordenadas arbitrárias)

        // Adicionando o tile layer do OpenStreetMap
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        // Função para calcular a distância entre dois pontos geográficos (em metros)
        function calcularDistancia(lat1, lon1, lat2, lon2) {
            const R = 6371000; // Raio da Terra em metros
            const φ1 = lat1 * Math.PI / 180;
            const φ2 = lat2 * Math.PI / 180;
            const Δφ = (lat2 - lat1) * Math.PI / 180;
            const Δλ = (lon2 - lon1) * Math.PI / 180;

            const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2) +
                      Math.cos(φ1) * Math.cos(φ2) *
                      Math.sin(Δλ / 2) * Math.sin(Δλ / 2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            return R * c; // Distância em metros
        }

        // Função para buscar a way (via) no formato XML e extrair as coordenadas dos nós
        function buscarWayEPlotar(wayId) {
            var url = `https://api.openstreetmap.org/api/0.6/way/${wayId}.xml`;

            fetch(url)
                .then(response => response.text())
                .then(xmlText => {
                    var parser = new DOMParser();
                    var xmlDoc = parser.parseFromString(xmlText, "text/xml");

                    // Pegando todos os nós referenciados pela way
                    var nodes = xmlDoc.getElementsByTagName("nd");
                    var coordenadas = [];

                    // Iterando pelos nós e buscando as coordenadas
                    Array.from(nodes).forEach(function(nd) {
                        var ref = nd.getAttribute("ref");

                        // Buscando as coordenadas de cada nó
                        var nodeUrl = `https://api.openstreetmap.org/api/0.6/node/${ref}.json`;
                        
                        fetch(nodeUrl)
                            .then(response => response.json())
                            .then(nodeData => {
                                var lat = nodeData.elements[0].lat;
                                var lon = nodeData.elements[0].lon;

                                coordenadas.push([lat, lon]);

                                // Quando todas as coordenadas forem coletadas, desenha a via
                                if (coordenadas.length === nodes.length) {
                                    // Agora vamos organizar as coordenadas para que a linha não "rabisque"
                                    var pontoInicial = coordenadas[0];
                                    var pontoFinal = coordenadas[coordenadas.length - 1];

                                    // Ordena as coordenadas para formar um caminho contínuo
                                    var coordenadasOrdenadas = [pontoInicial];
                                    coordenadas.splice(0, 1); // Remove o ponto inicial da lista de coordenadas

                                    // Organizando os pontos restantes com base na proximidade
                                    while (coordenadas.length > 0) {
                                        var ultimoPonto = coordenadasOrdenadas[coordenadasOrdenadas.length - 1];
                                        var menorDistancia = Infinity;
                                        var indexPontoMaisProximo = -1;

                                        for (var i = 0; i < coordenadas.length; i++) {
                                            var distancia = calcularDistancia(ultimoPonto[0], ultimoPonto[1], coordenadas[i][0], coordenadas[i][1]);
                                            if (distancia < menorDistancia) {
                                                menorDistancia = distancia;
                                                indexPontoMaisProximo = i;
                                            }
                                        }

                                        // Adiciona o ponto mais próximo à sequência
                                        coordenadasOrdenadas.push(coordenadas[indexPontoMaisProximo]);
                                        coordenadas.splice(indexPontoMaisProximo, 1); // Remove o ponto da lista
                                    }

                                    // Desenhando a via com as coordenadas ordenadas
                                    var polyline = L.polyline(coordenadasOrdenadas, {color: 'blue'}).addTo(map);
                                    map.fitBounds(polyline.getBounds()); // Ajusta o zoom para visualizar a via inteira
                                }
                            })
                            .catch(error => console.error('Erro ao buscar coordenada do nó:', error));
                    });
                })
                .catch(error => console.error('Erro ao buscar a way:', error));
        }

        // ID da "way" que você quer buscar e plotar
        var wayId = 335043011; // Substitua pelo ID da "way" que você deseja buscar

        // Chama a função para buscar e plotar a way
        buscarWayEPlotar(wayId);
    </script>
</body>
</html>
