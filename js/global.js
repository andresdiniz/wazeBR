(function ($) {
    const $j = $.noConflict();

    $j(document).ready(function () {
        initializeDataTables();
    });

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
                    dom: 'Bfrtip', // B = Buttons, f = Filtering, r = Processing, t = Table, i = Information, p = Pagination
                    language: {
                        search: "Buscar:",
                        paginate: { next: "Próximo", previous: "Anterior" },
                    },
                    buttons: [
                        { 
                            extend: 'csv', 
                            text: 'Exportar CSV', 
                            className: 'btn btn-primary',
                            exportOptions: {
                                // Aqui você pode configurar quais colunas exportar
                                columns: [0, 1, 2, 3, 4] // Index das colunas que serão exportadas (pode incluir colunas ocultas)
                            }
                        },
                        { 
                            extend: 'excel', 
                            text: 'Exportar Excel', 
                            className: 'btn btn-success',
                            exportOptions: {
                                columns: [0, 1, 2, 3, 4] // Ajuste conforme necessário
                            }
                        },
                        { 
                            extend: 'pdf', 
                            text: 'Exportar PDF', 
                            className: 'btn btn-danger',
                            exportOptions: {
                                columns: [0, 1, 2, 3, 4], // Aqui, você também pode especificar as colunas para exportação
                                modifier: {
                                    page: 'all', // Para garantir que todas as páginas sejam exportadas
                                    search: 'none' // Evita filtrar durante a exportação
                                }
                            }
                        },
                        { 
                            extend: 'print', 
                            text: 'Imprimir', 
                            className: 'btn btn-warning',
                            exportOptions: {
                                columns: [0, 1, 2, 3, 4], // Escolha as colunas a serem impressas
                                modifier: {
                                    page: 'all', 
                                    search: 'none'
                                }
                            }
                        }
                    ],
                    searchDelay: 500,
                    columnDefs: [
                        {
                            targets: 0,
                            visible: true,
                            className: 'd-none d-sm-table-cell'
                        }
                    ],
                };

                $j(table).DataTable(tableConfig);
            }
        });
    }
})(jQuery);
