(function ($) {
    const $j = $.noConflict();

    $j(document).ready(function () {
        // Inicializa tooltips do Bootstrap
        document.querySelectorAll('[data-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

        // Inicializa tabelas com DataTables
        initializeDataTables();

        // Configura modal de alerta
        setupAlertModal();

        // Atualiza cores das linhas dinamicamente
        updateRowColors();
        setInterval(updateRowColors, 60000); // Atualiza a cada 1 minuto
    });

    /**
     * Inicializa as tabelas DataTables para melhorar a experiência do usuário.
     */
    function initializeDataTables() {
        const tables = ['#accidentsTable', '#trafficTable'];

        tables.forEach(table => {
            if (!$j.fn.DataTable.isDataTable(table) && document.querySelector(table)) {
                $j(table).DataTable({
                    responsive: true,
                    autoWidth: false,
                    paging: true,
                    searching: true,
                    info: true,
                    dom: 'Bfrtip',
                    buttons: [
                        { extend: 'csv', text: 'Exportar CSV', className: 'btn btn-primary' },
                        { extend: 'excel', text: 'Exportar Excel', className: 'btn btn-success' },
                        { extend: 'pdf', text: 'Exportar PDF', className: 'btn btn-danger' }
                    ],
                    language: {
                        search: "Buscar:",
                        paginate: { next: "Próximo", previous: "Anterior" },
                    },
                });
            }
        });
    }

    /**
     * Configura o modal de alerta e preenche os dados corretamente.
     */
    function setupAlertModal() {
        // Delegação de eventos para elementos dinâmicos
        $j(document).on("click", "[data-toggle='modal']", function () {
            const alertData = $j(this).data("alert"); // Usando jQuery.data()

            if (!alertData) {
                console.error("Erro: Nenhum dado de alerta encontrado.");
                return;
            }

            let parsedData;
            try {
                parsedData = JSON.parse(alertData);
            } catch (error) {
                console.error("Erro ao analisar JSON:", error);
                return;
            }

            const modal = document.getElementById("alertModal");
            if (!modal) {
                console.error("Erro: Modal não encontrado.");
                return;
            }

            // Preenchendo os dados no modal usando jQuery
            $j("#modal-uuid").text(parsedData.uuid || "N/A");
            $j("#modal-city").text(parsedData.city || "N/A");
            $j("#modal-street").text(parsedData.street || "N/A");
            $j("#modal-via-KM").text(parsedData.km || "N/A");
            $j("#modal-location").text(`Lat: ${parsedData.location_y || 'N/A'}, Lon: ${parsedData.location_x || 'N/A'}`);
            $j("#modal-date-received").text(parsedData.pubMillis ? new Date(parseInt(parsedData.pubMillis, 10)).toLocaleString() : "N/A");
            $j("#modal-confidence").text(parsedData.confidence || "N/A");
            $j("#modal-type").text(parsedData.type || "N/A");
            $j("#modal-subtype").text(parsedData.subtype || "N/A");
            $j("#modal-status").text(parsedData.status == "1" ? "Ativo" : "Inativo");

            // Abre o modal corretamente
            const bootstrapModal = new bootstrap.Modal(modal);
            bootstrapModal.show();
        });
    }

    /**
     * Atualiza as cores das linhas da tabela conforme o tempo do alerta.
     */
    function updateRowColors() {
        const dateCells = document.querySelectorAll(".alert-date");
        const now = new Date().getTime();

        dateCells.forEach(cell => {
            const eventMillis = parseInt(cell.getAttribute("data-pubmillis"), 10);
            if (isNaN(eventMillis)) return;

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