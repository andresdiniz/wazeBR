$(document).ready(function () {
    // Inicializa DataTables
    initializeDataTables();

    // Configura o modal de alerta
    setupAlertModal();

    // Configura o mapa e eventos relacionados
    setupMap();

    // Configura a confirmação de alerta
    setupAlertConfirmation();
});

function initializeDataTables() {
    const tables = ['#accidentsTable', '#trafficTable', '#jamsTable', '#hazardsTable', '#jamAlertsTable', '#otherAlertsTable'];
    tables.forEach(table => {
        if (!$.fn.DataTable.isDataTable(table)) {
            $(table).DataTable({
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
    $('#alertModal').on('show.bs.modal', function (event) {
        const button = $(event.relatedTarget);
        const alertData = button.data('alert');

        if (!alertData) {
            console.error("Erro: Não foi possível obter os dados do alerta.");
            return;
        }

        const modal = $(this);
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

    $('#view-on-map').click(function () {
        const lat = $('#modal-location').data('lat');
        const lon = $('#modal-location').data('lon');
        const alertType = $('#modal-type').text();
        const city = $('#modal-city').text();
        const street = $('#modal-street').text();
        const uuid = $('#modal-uuid').text();
        const status = $('#modal-status').text();
        const confidence = $('#modal-confidence').text();

        if (!lat || !lon || lat === 'N/A' || lon === 'N/A') {
            alert('Dados de localização inválidos. Não foi possível mostrar o mapa.');
            return;
        }

        initMap(lat, lon, alertType, city, street, uuid, status, confidence);
        $('#mapModal').modal('show').one('shown.bs.modal', function () {
            map.invalidateSize();
        });
    });

    $(window).on('resize', function () {
        if (map) {
            map.invalidateSize();
        }
    });
}

function setupAlertConfirmation() {
    $('#confirm-alert').click(function () {
        const uuid = $('#modal-uuid').text();
        const km = $('#modal-via-KM').text();

        if (!uuid || uuid === 'N/A') {
            console.error('Erro: UUID não encontrado.');
            return;
        }

        confirmarAlerta(uuid, km);
    });

    function confirmarAlerta(uuid, km) {
        $.ajax({
            url: '/api.php?action=confirm_alert',
            type: 'POST',
            data: { uuid: uuid, km: km, status: 1 },
            success: function (response) {
                alert('Alerta confirmado com sucesso!');
                $('#alertModal').modal('hide');
            },
            error: function (xhr, status, error) {
                alert('Erro ao confirmar o alerta. Tente novamente.');
            },
        });
    }
}