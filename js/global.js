/**
 * global.js - Script de inicialização e manipulação de alertas no sistema.
 * 
 * Este arquivo contém funções para inicializar tooltips, DataTables, configurar modais,
 * configurar mapas interativos, confirmar alertas e atualizar cores das linhas da tabela
 * com base no tempo do alerta.
 * 
 * Criado em: 31/01/2025, 17:30 (Horário de São Paulo)
 */

document.addEventListener('DOMContentLoaded', function () {
    // Inicializa tooltips do Bootstrap
    document.querySelectorAll('[data-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });

    // Inicializa tabelas com DataTables
    initializeDataTables();

    // Atualiza as cores das linhas com base na data do alerta
    updateRowColors();
    setInterval(updateRowColors, 60000); // Atualiza a cada 1 minuto

    // Configura o evento de clique nos botões que abrem o modal
    setupAlertButtons();
});

/**
 * Inicializa as tabelas DataTables para melhor experiência do usuário.
 */
function initializeDataTables() {
    const tables = ['#accidentsTable', '#trafficTable', '#hazardsTable', '#jamAlertsTable', '#otherAlertsTable'];
    
    tables.forEach(tableId => {
        const tableElement = document.querySelector(tableId);
        if (tableElement && !tableElement.classList.contains('dataTable')) {
            new DataTable(tableElement, {
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
 * Configura os eventos de clique nos botões de alerta.
 */
function setupAlertButtons() {
    // Seleciona todos os botões com o atributo data-target="#alertModal"
    const buttons = document.querySelectorAll('[data-target="#alertModal"]');
    
    // Adiciona o evento de clique a cada botão
    buttons.forEach(function(button) {
        button.addEventListener('click', function(event) {
            console.log("Botão clicado:", button); // Exibe o botão no console
            
            // Obtém os dados do botão (atributo data-alert)
            let alertData;
            try {
                alertData = JSON.parse(button.getAttribute('data-alert')); // Usando getAttribute em vez de jQuery
                console.log("Dados parseados:", alertData);
            } catch (error) {
                console.error("Erro ao parsear JSON:", error, "Dados:", button.getAttribute('data-alert'));
                return;
            }

            // Preenche o modal com os dados extraídos
            const modal = document.getElementById('alertModal'); // Supondo que o ID do modal seja 'alertModal'
            modal.querySelector('#modal-uuid').textContent = alertData.uuid || 'N/A';
            modal.querySelector('#modal-city').textContent = alertData.city || 'N/A';
            modal.querySelector('#modal-street').textContent = alertData.street || 'N/A';
            modal.querySelector('#modal-via-KM').textContent = alertData.km || 'N/A';
            modal.querySelector('#modal-location').textContent = `Lat: ${alertData.location_y || 'N/A'}, Lon: ${alertData.location_x || 'N/A'}`;
            modal.querySelector('#modal-date-received').textContent = alertData.pubMillis ? new Date(parseInt(alertData.pubMillis)).toLocaleString() : 'N/A';
            modal.querySelector('#modal-confidence').textContent = alertData.confidence ? `${alertData.confidence}%` : 'N/A';
            modal.querySelector('#modal-type').textContent = alertData.type || 'N/A';
            modal.querySelector('#modal-subtype').textContent = alertData.subtype || 'N/A';
            modal.querySelector('#modal-status').textContent = alertData.status == 1 ? "Ativo" : "Inativo";
            
            // Abre o modal após carregar os dados
            new bootstrap.Modal(modal).show();
        });
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
