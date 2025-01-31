/**
 * global.js - Script de inicialização e manipulação de alertas no sistema.
 * 
 * Este arquivo contém funções para inicializar tooltips, DataTables, configurar modais,
 * configurar mapas interativos, confirmar alertas e atualizar cores das linhas da tabela
 * com base no tempo do alerta.
 */

(function ($) {
    // Usar jQuery em modo sem conflito
    const $j = $.noConflict();

    $j(document).ready(function () {
        // Inicializa tooltips do Bootstrap
        $j('[data-toggle="tooltip"]').tooltip();

        // Inicializa tabelas com DataTables
        initializeDataTables();

        // Configura modal de alerta
        setupAlertModal();

        // Configura o mapa interativo (se o elemento #map existir)
        if (document.getElementById('map')) {
            setupMap();
        }

        // Configura confirmação de alerta
        setupAlertConfirmation();

        // Atualiza as cores das linhas com base na data do alerta
        updateRowColors();
        setInterval(updateRowColors, 60000); // Atualiza a cada 1 minuto
    });

    /**
     * Inicializa as tabelas DataTables para melhor experiência do usuário.
     */
    function initializeDataTables() {
        const tables = ['#accidentsTable', '#trafficTable', '#hazardsTable', '#jamAlertsTable', '#otherAlertsTable'];
        tables.forEach(table => {
            if (!$j.fn.DataTable.isDataTable(table) && document.querySelector(table)) {
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

    /**
     * Configura o modal de alerta, preenchendo os dados corretamente.
     */
    function setupAlertModal() {
        $j('#alertModal').on('show.bs.modal', function (event) {
            const button = $j(event.relatedTarget);
            const alertData = button.data('alert');

            if (!alertData) {
                console.error("Erro: Não foi possível obter os dados do alerta.");
                return;
            }

            const modal = $j(this);
            modal.find('#modal-uuid').text(alertData.uuid || 'N/A');
            modal.find('#modal-city').text(alertData.city || 'N/A');
            modal.find('#modal-street').text(alertData.street || 'N/A');
            modal.find('#modal-via-KM').text(alertData.km || 'N/A');
            modal.find('#modal-location').text(`Lat: ${alertData.location_x || 'N/A'}, Lon: ${alertData.location_y || 'N/A'}`);
            modal.find('#modal-date-received').text(new Date(alertData.pubMillis).toLocaleString() || 'N/A');
            modal.find('#modal-confidence').text(alertData.confidence || 'N/A');
            modal.find('#modal-type').text(alertData.type || 'N/A');
            modal.find('#modal-subtype').text(alertData.subtype || 'N/A');
            modal.find('#modal-status').text(alertData.status === 1 ? 'Ativo' : 'Inativo');
        });
    }

    /**
     * Configura e exibe o mapa interativo para visualização dos alertas.
     */
    function setupMap() {
        let map;
        const markersLayer = L.layerGroup();

        function initMap(lat, lon, alertType, city, street, uuid, status, confidence) {
            if (!map) {
                map = L.map('map').setView([lat, lon], 13);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors',
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

            L.marker([lat, lon])
                .addTo(markersLayer)
                .bindPopup(popupContent)
                .openPopup();

            markersLayer.addTo(map);
            map.setView([lat, lon], 13);
        }

        // Verifica se o botão #view-on-map existe antes de adicionar o evento
        const viewOnMapButton = document.getElementById('view-on-map');
        if (viewOnMapButton) {
            viewOnMapButton.addEventListener('click', function () {
                const lat = $j('#modal-location').data('lat');
                const lon = $j('#modal-location').data('lon');

                if (!lat || !lon || lat === 'N/A' || lon === 'N/A') {
                    alert('Dados de localização inválidos. Não foi possível mostrar o mapa.');
                    return;
                }

                initMap(lat, lon);
                $j('#mapModal').modal('show').one('shown.bs.modal', function () {
                    map.invalidateSize();
                });
            });
        }
    }

    /**
     * Configura a confirmação de alertas via AJAX.
     */
    function setupAlertConfirmation() {
        const confirmAlertButton = document.getElementById('confirm-alert');
        if (confirmAlertButton) {
            confirmAlertButton.addEventListener('click', function () {
                const uuid = $j('#modal-uuid').text();
                const km = $j('#modal-via-KM').text();

                if (!uuid || uuid === 'N/A') {
                    console.error('Erro: UUID não encontrado.');
                    return;
                }

                $j.ajax({
                    url: '/api.php?action=confirm_alert',
                    type: 'POST',
                    data: { uuid: uuid, km: km, status: 1 },
                    success: function () {
                        alert('Alerta confirmado com sucesso!');
                        $j('#alertModal').modal('hide');
                    },
                    error: function () {
                        alert('Erro ao confirmar o alerta. Tente novamente.');
                    },
                });
            });
        }
    }

    /**
     * Atualiza as cores das linhas da tabela conforme a data do alerta.
     */
    function updateRowColors() {
        const dateCells = document.querySelectorAll(".alert-date");
        const now = new Date().getTime();

        dateCells.forEach((cell) => {
            const eventMillis = parseInt(cell.getAttribute("data-pubmillis"), 10);
            const minutesDiff = (now - eventMillis) / (1000 * 60);

            let bgColor = "";
            let textColor = "";

            if (minutesDiff <= 5) bgColor = "#ff0000";
            else if (minutesDiff <= 15) bgColor = "#ff3333";
            else if (minutesDiff <= 30) bgColor = "#ff6666";
            else if (minutesDiff <= 60) bgColor = "#ff9999";
            else if (minutesDiff <= 90) bgColor = "#ffcccc";

            const row = cell.parentElement;
            row.style.backgroundColor = bgColor;
            row.style.color = textColor;
        });
    }
})(jQuery);