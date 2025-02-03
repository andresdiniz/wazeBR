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
                    dom: 'Bfrtip',
                    language: {
                        search: "Buscar:",
                        paginate: { next: "Próximo", previous: "Anterior" },
                    },
                    buttons: [
                        { extend: 'csv', text: 'Exportar CSV', className: 'btn btn-primary' },
                        { extend: 'excel', text: 'Exportar Excel', className: 'btn btn-success' },
                        { extend: 'pdf', text: 'Exportar PDF', className: 'btn btn-danger' },
                        { extend: 'print', text: 'Imprimir', className: 'btn btn-warning' }
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
