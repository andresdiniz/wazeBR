/**
 * global.js - Script de inicialização e manipulação de alertas no sistema.
 * 
 * Este arquivo contém funções para inicializar tooltips, DataTables, configurar modais,
 * configurar mapas interativos, confirmar alertas e atualizar cores das linhas da tabela
 * com base no tempo do alerta.
 * 
 * Criado em: 31/01/2025, 17:30 (Horário de São Paulo)
 */

(function ($) {
    // Usar jQuery em modo sem conflito
    const $j = $.noConflict();

    $j(document).ready(function () {
        // Inicializa tooltips do Bootstrap
        document.querySelectorAll('[data-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el);
        });

        // Inicializa tabelas com DataTables
        initializeDataTables();

        // Configura o mapa interativo (se o elemento #map existir)
        if (document.getElementById('map')) {
            //setupMap();
        }

        // Configura confirmação de alerta
        //setupAlertConfirmation();

        // Atualiza as cores das linhas com base na data do alerta
        updateRowColors();
        setInterval(updateRowColors, 60000); // Atualiza a cada 1 minuto

        // Configura modal de alerta
        setupAlertModal();
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
                    dom: 'Bfrtip', // Adiciona os botões acima da tabela
                    buttons: [
                        {
                            extend: 'csv',
                            text: 'Exportar CSV',
                            titleAttr: 'Exportar para CSV',
                            className: 'btn btn-primary'
                        },
                        {
                            extend: 'excel',
                            text: 'Exportar Excel',
                            titleAttr: 'Exportar para Excel',
                            className: 'btn btn-success'
                        },
                        {
                            extend: 'pdf',
                            text: 'Exportar PDF',
                            titleAttr: 'Exportar para PDF',
                            className: 'btn btn-danger'
                        }
                    ],
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
        // Configura o evento no botão para abrir o modal
        $j('[data-target="#vermais"]').on('click', function (event) {
            const button = $j(this); // O botão que acionou o modal

            console.log("Botão acionador:", button);

            let alertData;
            try {
                alertData = JSON.parse(button.attr('data-alert')); // Usar .attr() do jQuery
                console.log("Dados parseados:", alertData);
            } catch (error) {
                console.error("Erro ao parsear JSON:", error, "Dados:", button.attr('data-alert'));
                return;
            }

            const modal = $j('#alertModal'); // Supondo que o ID do modal seja 'alertModal'
            modal.find('#modal-uuid').text(alertData.uuid || 'N/A');
            modal.find('#modal-city').text(alertData.city || 'N/A');
            modal.find('#modal-street').text(alertData.street || 'N/A');
            modal.find('#modal-via-KM').text(alertData.km || 'N/A');
            modal.find('#modal-location').text(`Lat: ${alertData.location_y || 'N/A'}, Lon: ${alertData.location_x || 'N/A'}`);
            modal.find('#modal-date-received').text(alertData.pubMillis ? new Date(parseInt(alertData.pubMillis)).toLocaleString() : 'N/A');
            modal.find('#modal-confidence').text(alertData.confidence ? `${alertData.confidence}%` : 'N/A');
            modal.find('#modal-type').text(alertData.type || 'N/A');
            modal.find('#modal-subtype').text(alertData.subtype || 'N/A');
            modal.find('#modal-status').text(alertData.status == 1 ? "Ativo" : "Inativo");
            
            // Abre o modal após carregar os dados
            modal.modal('show');
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
            
            if (isNaN(eventMillis)) return; // Corrigido: Verifica se é número válido

            const minutesDiff = (now - eventMillis) / (1000 * 60);
            let bgColor = "";

            if (minutesDiff <= 5) bgColor = "#ff0000";
            else if (minutesDiff <= 15) bgColor = "#ff3333";
            else if (minutesDiff <= 30) bgColor = "#ff6666";
            else if (minutesDiff <= 60) bgColor = "#ff9999";
            else if (minutesDiff <= 90) bgColor = "#ffcccc";

            const row = cell.parentElement;
            row.style.backgroundColor = bgColor;
        });
    }

})(jQuery);
