// Inicializar o mapa no div com id 'map'
var map = L.map('map').setView([-23.5505, -46.6333], 13); // Coordenadas de São Paulo, ajuste conforme necessário

// Adicionar a camada base do OpenStreetMap
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
}).addTo(map);

// Função para carregar o arquivo KML
function loadKML() {
    // Fazer a requisição para o arquivo KML
    fetch('seu_arquivo.kml')
        .then(res => res.text()) // Converter a resposta para texto
        .then(kmltext => {
            // Criar a camada KML
            var parser = new DOMParser();
            var kml = parser.parseFromString(kmltext, 'text/xml');
            var track = new L.KML(kml);

            // Adicionar a camada KML ao mapa
            map.addLayer(track);

            // Fazer o 'fit' para que o mapa se ajuste aos limites dos pontos
            // Isso centraliza e dá zoom automaticamente
            var bounds = track.getBounds();
            if (bounds.isValid()) {
                map.fitBounds(bounds);
            }
        })
        .catch(error => {
            console.error('Erro ao carregar o arquivo KML:', error);
            alert('Não foi possível carregar o arquivo KML. Verifique se o nome do arquivo está correto e se ele está no mesmo diretório.');
        });
}

// Chamar a função para carregar o KML quando a página for carregada
document.addEventListener('DOMContentLoaded', loadKML);