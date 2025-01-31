/**
 * global.js - Script de inicialização e manipulação de alertas no sistema.
 * 
 * Este arquivo contém funções para inicializar tooltips, DataTables, configurar modais,
 * configurar mapas interativos, confirmar alertas e atualizar cores das linhas da tabela
 * com base no tempo do alerta.
 * 
 */

(function ($) {
    // Usar jQuery em modo sem conflito
    const $j = $.noConflict();

    $j(document).ready(function () {

        // Inicializa tooltips
        document.querySelectorAll('[data-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el);
        });

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
            const alertData = JSON.parse(button.attr('data-alert')); // Correção: Usa JSON.parse para evitar erro de parsing

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
            modal.find('#modal-date-received').text(new Date(parseInt(alertData.pubMillis, 10)).toLocaleString() || 'N/A');
            modal.find('#modal-confidence').text(alertData.confidence || 'N/A');
            modal.find('#modal-type').text(alertData.type || 'N/A');
            modal.find('#modal-subtype').text(alertData.subtype || 'N/A');
            modal.find('#modal-status').text(alertData.status === "1" ? "Ativo" : "Inativo");
        });
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
