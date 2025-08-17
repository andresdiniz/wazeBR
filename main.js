// Inicializar o mapa no div com id 'map'
var map = L.map('map').setView([-23.5505, -46.6333], 13); // Coordenadas de São Paulo, ajuste conforme necessário
var kmlLayer = null; // Variável global para a camada KML

// Adicionar a camada base do OpenStreetMap
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
}).addTo(map);

// Selecionar o input de arquivo
var fileInput = document.getElementById('kmlFile');

// Adicionar um 'listener' para o evento de mudança do input
fileInput.addEventListener('change', function(event) {
    var file = event.target.files[0];
    if (!file) {
        return;
    }

    // Remover a camada KML anterior se existir
    if (kmlLayer) {
        map.removeLayer(kmlLayer);
    }

    var reader = new FileReader();
    reader.onload = function(e) {
        var kmltext = e.target.result;
        try {
            // Criar a camada KML
            var parser = new DOMParser();
            var kml = parser.parseFromString(kmltext, 'text/xml');
            kmlLayer = new L.KML(kml);

            // Adicionar a camada KML ao mapa
            map.addLayer(kmlLayer);

            // Fazer o 'fit' para que o mapa se ajuste aos limites dos pontos
            var bounds = kmlLayer.getBounds();
            if (bounds.isValid()) {
                map.fitBounds(bounds);
            }
        } catch (error) {
            console.error('Erro ao processar o arquivo KML:', error);
            alert('Erro ao processar o arquivo. Verifique se ele é um KML válido.');
        }
    };
    reader.onerror = function(e) {
        console.error('Erro ao ler o arquivo:', e);
        alert('Não foi possível ler o arquivo.');
    };
    reader.readAsText(file); // Ler o arquivo como texto
});