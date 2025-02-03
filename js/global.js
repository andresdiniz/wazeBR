(function ($) {
    const $j = $.noConflict();

    $j(document).ready(function () {
        initializeDataTables();
    });

    /**
     * Inicializa as tabelas DataTables para melhorar a experiência do usuário.
     */
    function initializeDataTables() {
        const tables = ['#accidentsTable', '#trafficTable'];

        tables.forEach(table => {
            if (!$j.fn.DataTable.isDataTable(table) && document.querySelector(table)) {
                const tableConfig = {
                    responsive: true,
                    autoWidth: false,
                    paging: true,
                    searching: true,
                    info: true,
                    dom: 'Bfrtip',
                    language: {
                        search: "Buscar:",
                        paginate: { next: "Próximo", previous: "Anterior" },
                    },
                    buttons: [
                        { extend: 'csv', text: 'Exportar CSV', className: 'btn btn-primary' },
                        { extend: 'excel', text: 'Exportar Excel', className: 'btn btn-success' },
                        { extend: 'pdf', text: 'Exportar PDF', className: 'btn btn-danger' },
                        { extend: 'print', text: 'Imprimir', className: 'btn btn-warning' } // Botão de Imprimir
                    ],
                    // Introduz um pequeno delay no campo de pesquisa para melhorar o desempenho
                    searchDelay: 500,
                    // Ajustar o comportamento das colunas em telas pequenas
                    columnDefs: [
                        {
                            targets: 0, // Exemplo: Ajustar a coluna 0 para não ser exibida em dispositivos móveis
                            visible: true,
                            className: 'd-none d-sm-table-cell' 
                        }
                    ],
                };

                // Inicializar DataTable
                $j(table).DataTable(tableConfig);
            }
        });
    }
})(jQuery);
