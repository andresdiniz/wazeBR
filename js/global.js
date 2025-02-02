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
        document.querySelectorAll("[data-toggle='modal']").forEach(btn => {
            btn.addEventListener("click", function () {
                const alertData = this.getAttribute("data-alert");

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

                // Preenchendo os dados no modal
                modal.querySelector("#modal-uuid").textContent = parsedData.uuid || "N/A";
                modal.querySelector("#modal-city").textContent = parsedData.city || "N/A";
                modal.querySelector("#modal-street").textContent = parsedData.street || "N/A";
                modal.querySelector("#modal-via-KM").textContent = parsedData.km || "N/A";
                modal.querySelector("#modal-location").textContent = `Lat: ${parsedData.location_y || 'N/A'}, Lon: ${parsedData.location_x || 'N/A'}`;
                modal.querySelector("#modal-date-received").textContent = parsedData.pubMillis 
                    ? new Date(parseInt(parsedData.pubMillis, 10)).toLocaleString() 
                    : "N/A";
                modal.querySelector("#modal-confidence").textContent = parsedData.confidence || "N/A";
                modal.querySelector("#modal-type").textContent = parsedData.type || "N/A";
                modal.querySelector("#modal-subtype").textContent = parsedData.subtype || "N/A";
                modal.querySelector("#modal-status").textContent = parsedData.status == "1" ? "Ativo" : "Inativo";

                // Abre o modal corretamente
                const bootstrapModal = new bootstrap.Modal(modal);
                bootstrapModal.show();
            });
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
