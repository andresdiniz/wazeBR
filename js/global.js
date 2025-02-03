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
})(jQuery);
