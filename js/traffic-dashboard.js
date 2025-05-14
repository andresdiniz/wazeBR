/**
 * Traffic Jam Dashboard
 * Script para visualização dos dados de congestionamento
 */

document.addEventListener('DOMContentLoaded', function() {
    // Configurações comuns para os gráficos
    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
            }
        }
    };

    // Função para gerar cores das bordas
    const generateBorderColors = (colorsArray) => 
        colorsArray.map(c => c.replace(/[\d.]+\)$/g, '1)'));

    // Cores e paleta
    const colorPalette = [
        'rgba(13, 110, 253, 0.7)',   // primary
        'rgba(220, 53, 69, 0.7)',    // danger
        'rgba(25, 135, 84, 0.7)',    // success
        'rgba(255, 193, 7, 0.7)',    // warning
        'rgba(13, 202, 240, 0.7)',   // info
        'rgba(108, 117, 125, 0.7)',  // secondary
        'rgba(111, 66, 193, 0.7)',   // purple
        'rgba(253, 126, 20, 0.7)',   // orange
        'rgba(32, 201, 151, 0.7)',   // teal
        'rgba(214, 51, 132, 0.7)'    // pink
    ];
    
    const colorPaletteBorders = generateBorderColors(colorPalette);

    // Função para criação de eixos duplos
    const dualAxisScales = (yTitle, y1Title) => ({
        y: {
            beginAtZero: true,
            position: 'left',
            title: { display: true, text: yTitle }
        },
        y1: {
            beginAtZero: true,
            position: 'right',
            title: { display: true, text: y1Title },
            grid: { drawOnChartArea: false }
        }
    });

    // 1. Gráfico de distribuição por hora
    initHourlyChart();
    // 2. Gráfico de distribuição por dia da semana
    initWeekdayChart();
    // 3. Gráfico de nível de congestionamento
    initLevelChart();
    // 4. Gráfico de distribuição de atrasos
    initDelayDistributionChart();
    // 5. Gráfico de comprimento vs atraso
    timexlenght();
    // 5. Gráfico mensal
    initMonthlyChart();
    // 6. Gráfico de cidades
    //weeklyHourlyHeatmap();
    // 7. Gráfico de tipo de via
    //weeklyHourlyHeatmap();
    // 8. Gráfico de relação entre comprimento e atraso
    //initLengthVsDelayChart();

    function initHourlyChart() {
        const ctx = document.getElementById('hourlyChart');
        if (!ctx) return;
        
        // Adaptado ao novo formato: [{ hora, total }]
        const labels = data.map(item => `${item.hora}:00`);
        const congestionamentos = data.map(item => item.total);

        if (window.hourlyChartInstance) {
            window.hourlyChartInstance.destroy();
        }
    
        window.hourlyChartInstance = new Chart(ctx, createSingleAxisChartConfig(
            labels,
            [congestionamentos, 'Congestionamentos', 'bar', 'rgba(13, 110, 253, 0.7)'],
            [[], 'Atraso Médio (min)', 'line', 'rgba(220, 53, 69, 0.7)'] // Linha vazia, pois não há avg_delay
        ));
    }

    // ✅ Declare no topo, antes de qualquer uso
    var weekdayChartInstance = null;

    function initWeekdayChart() {
        const ctx = document.getElementById('weekdayChart');
        if (!ctx) return;

        // ✅ Isso agora funciona corretamente
        if (weekdayChartInstance) {
            weekdayChartInstance.destroy();
        }

        const rawData = datasemana;
        const dayOrder = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const dayLabelsPT = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        const data = dayOrder.map(dayName => rawData.find(d => d.dia === dayName) || { dia: dayName, total: 0 });

        const labels = data.map(d => {
            const idx = dayOrder.indexOf(d.dia);
            return dayLabelsPT[idx] || d.dia;
        });

        const congestionamentos = data.map(d => d.total);

        weekdayChartInstance = new Chart(ctx, createSingleAxisChartConfig(
            labels,
            [congestionamentos, 'Congestionamentos', 'bar', 'rgba(25, 135, 84, 0.7)']
        ));
    }

    function initLevelChart() {
        const ctx = document.getElementById('levelChart');
        if (!ctx) return;
    
        const data = dadosnivel; // Certifique-se que essa variável esteja definida corretamente via Twig
        
        const labels = data.map(item => `Nível ${item.nivel}`);
        const congestionamentos = data.map(item => item.total);
    
        new Chart(ctx, createSingleAxisChartConfig(
            labels,
            [congestionamentos, 'Congestionamentos', 'bar', 'rgba(13, 202, 240, 0.7)']
        ));
    } 

    function initDelayDistributionChart() {
        const ctx = document.getElementById('delayDistChart');
        if (!ctx) return;
    
        const data = dadosatraso;
    
        const labels = data.map(i => i.rua);
        const values = data.map(i => i.total);
    
        new Chart(ctx, {
            type: 'doughnut', // troca para doughnut para visual mais moderno
            data: {
                labels,
                datasets: [{
                    data: values,
                    backgroundColor: colorPalette,
                    borderColor: colorPaletteBorders,
                    borderWidth: 1,
                    hoverOffset: 10 // destaque ao passar o mouse
                }]
            },
            options: {
                ...chartOptions,
                cutout: '50%', // centraliza melhor no doughnut
                plugins: {
                    legend: {
                        position: 'bottom', // melhor para listas grandes
                        labels: {
                            boxWidth: 12,
                            padding: 15,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const label = context.label || '';
                                const value = context.raw;
                                const total = values.reduce((a, b) => a + b, 0);
                                const percent = ((value / total) * 100).toFixed(1);
                                return `${label}: ${value} (${percent}%)`;
                            }
                        }
                    }
                }
            }
        });
    }  

    function timexlenght() {
        const rawData = atrasocomprimento;

        const dataPoints = rawData.map(item => ({
            x: item.comprimento,
            y: item.atraso,
            label: item.uuid
        }));

        const ctx = document.getElementById('lengthVsDelayChart').getContext('2d');

        new Chart(ctx, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Segmentos (UUID)',
                    data: dataPoints,
                    backgroundColor: 'rgba(255, 99, 132, 0.7)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return `UUID: ${context.raw.label}, Comprimento: ${context.raw.x}m, Atraso: ${context.raw.y}s`;
                            }
                        }
                    },
                    title: {
                        display: true,
                        text: 'Correlação: Comprimento vs Atraso'
                    }
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Comprimento (metros)'
                        }
                    },
                    y: {
                        title: {
                            display: true,
                            text: 'Atraso (segundos)'
                        }
                    }
                }
            }
        });
    }
    
    function createSingleAxisChartConfig(labels, [data, label, type, color]) {
        return {
            type: type,
            data: {
                labels: labels,
                datasets: [{
                    label: label,
                    data: data,
                    backgroundColor: color,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: label
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                }
            }
        };
    }  
    
    function createDualAxisChartConfig(labels, primaryData, secondaryData, yTitle, y1Title) {
        const [pData, pLabel, pType, pColor] = primaryData;
        const [sData, sLabel, sType, sColor] = secondaryData;
        
        return {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: pLabel,
                        data: pData,
                        backgroundColor: pColor,
                        borderColor: generateBorderColors([pColor])[0],
                        borderWidth: 1,
                        yAxisID: 'y',
                        type: pType
                    },
                    {
                        label: sLabel,
                        data: sData,
                        borderColor: generateBorderColors([sColor])[0],
                        backgroundColor: sColor,
                        borderWidth: 2,
                        yAxisID: 'y1',
                        type: sType
                    }
                ]
            },
            options: {
                ...chartOptions,
                scales: dualAxisScales(yTitle, y1Title)
            }
        };
    }

    function initMonthlyChart() {
        const monthlyData = mensal;

        const ctx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: monthlyData.map(d => d.mes),
                datasets: [{
                    label: 'Total de Congestionamentos',
                    data: monthlyData.map(d => d.total),
                    fill: false,
                    borderColor: 'rgba(75, 192, 192, 1)',
                    backgroundColor: 'rgba(75, 192, 192, 0.3)',
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Tendência Mensal de Congestionamentos'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    } // Fechamento correto da função initMonthlyChart

// Assume you have Chart.js, Chart.js Matrix Controller, and chartjs-plugin-datalabels loaded
// <script src="https://cdn.jsdelivr.net/npm/chart.js@4.x/dist/chart.umd.js"></script>
// <script src="https://cdn.jsdelivr.net/npm/chartjs-chart-matrix@1.x/dist/chartjs-chart-matrix.min.js"></script>
// <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.x/dist/chartjs-plugin-datalabels.min.js"></script>


function weeklyHourlyHeatmap() {
    // Certifique-se que a variável diaxsemana esteja definida corretamente via Twig
    // Ex: var diaxsemana = {{ sua_variavel_twig | json_encode() | raw }};
    const semanaldata = diaxsemana;

    // Seleciona o contêiner onde o canvas já existe
    const chartContainer = document.querySelector('.grafico-calor');
    const canvas = document.getElementById('heatmapChart');

    // Verifica se o contêiner, o canvas e os dados existem
    if (!chartContainer || !canvas || !semanaldata || semanaldata.length === 0) {
        console.error("Container do gráfico, canvas ou dados não encontrados.");
        return;
    }

    // Dias da semana
    const dias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    // Calculate min and max speed for color mapping
    const speeds = semanaldata.map(d => d.media_velocidade);
    const minSpeed = Math.min(...speeds);
    const maxSpeed = Math.max(...speeds);

    // Color interpolation function (blue to yellow/green) - mimicking the image's color scale idea
    const getColor = (value) => {
        if (value === undefined || value === null) return 'rgb(220, 220, 220)'; // Light grey for missing data

        // Normalize the speed value to a 0-1 range
        const percent = (value - minSpeed) / (maxSpeed - minSpeed);

        // Interpolate between several color stops for a gradient effect
        const colorStops = [
            { stop: 0, color: [68, 1, 84] },   // Purple
            { stop: 0.25, color: [59, 82, 139] }, // Blue-ish
            { stop: 0.5, color: [36, 152, 147] }, // Green-ish
            { stop: 0.75, color: [138, 201, 87] }, // Yellow-green-ish
            { stop: 1, color: [253, 231, 37] }    // Yellow
        ];

        let c1 = colorStops[0], c2 = colorStops[0];
        for (let i = 0; i < colorStops.length - 1; i++) {
            if (percent >= colorStops[i].stop && percent <= colorStops[i+1].stop) {
                c1 = colorStops[i];
                c2 = colorStops[i+1];
                break;
            }
            // Handle values outside the defined stops range
             if (percent < colorStops[0].stop) {
                 c1 = colorStops[0];
                 c2 = colorStops[0];
             } else if (percent > colorStops[colorStops.length - 1].stop) {
                 c1 = colorStops[colorStops.length - 1];
                 c2 = colorStops[colorStops.length - 1];
             }
        }

        // Avoid division by zero if c1.stop === c2.stop (happens at the edges or with uniform data)
        const rangePercent = (c1.stop === c2.stop) ? 0 : (percent - c1.stop) / (c2.stop - c1.stop);


        const r = Math.round(c1.color[0] + (c2.color[0] - c1.color[0]) * rangePercent);
        const g = Math.round(c1.color[1] + (c2.color[1] - c1.color[1]) * rangePercent);
        const b = Math.round(c1.color[2] + (c2.color[2] - c1.color[2]) * rangePercent);

        return `rgb(${r}, ${g}, ${b})`;
    };

    // Create data points for all possible hours (0-23) and days (0-6)
    // This ensures the grid is complete even if data is missing for a cell.
    const completeMatrixData = [];
    for (let dia = 0; dia < 7; dia++) {
        for (let hora = 0; hora < 24; hora++) {
            const dataPoint = semanaldata.find(d => d.dia === dia && d.hora === hora);
            if (dataPoint) {
                completeMatrixData.push({
                    x: hora,
                    y: dia,
                    v: {
                        quantidade: dataPoint.quantidade,
                        media_nivel: parseFloat(dataPoint.media_nivel),
                        media_velocidade: dataPoint.media_velocidade,
                        media_atraso: parseFloat(dataPoint.media_atraso)
                    }
                });
            } else {
                // Add a placeholder for missing data
                completeMatrixData.push({
                    x: hora,
                    y: dia,
                    v: null // Indicates missing data
                });
            }
        }
    }

    // Destroy existing chart instance if it exists
    if (Chart.getChart('heatmapChart')) {
        Chart.getChart('heatmapChart').destroy();
    }

    const chart = new Chart(canvas, {
        type: 'matrix',
        data: {
            datasets: [{
                label: 'Velocidade Média (km/h)', // Updated label
                data: completeMatrixData,
                backgroundColor: ctx => {
                    if (ctx.raw.v === null) {
                        return 'rgb(240, 240, 240)'; // Lighter grey for missing data
                    }
                    return getColor(ctx.raw.v.media_velocidade);
                },
                borderColor: 'rgba(0, 0, 0, 0.1)', // Border between cells
                borderWidth: 1,
                width: ({ chart }) => chart.chartArea.width / 24,
                height: ({ chart }) => chart.chartArea.height / 7,
                hoverBackgroundColor: '#ffff66', // Example hover effect
                hoverBorderColor: '#ffff00'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            aspectRatio: 2, // Adjust aspect ratio if needed to match the image shape
            plugins: {
                title: {
                    display: true,
                    text: 'Velocidade Média por Dia da Semana e Hora', // Updated title
                    font: {
                        size: 16
                    }
                },
                tooltip: {
                    callbacks: {
                        title: ctx => {
                            const data = ctx[0].raw.v;
                            if (data === null) return 'Dados Indisponíveis';
                            return `${data.hora}:00 - ${dias[data.dia]}`;
                        },
                        label: ctx => {
                            const data = ctx.raw.v;
                            if (data === null) return 'Sem dados para este período';
                            return [
                                `Quantidade: ${data.quantidade}`,
                                `Nível Médio: ${data.media_nivel.toFixed(2)}`,
                                `Velocidade Média: ${data.media_velocidade.toFixed(2)} km/h`,
                                `Atraso Médio: ${data.media_atraso.toFixed(2)}`
                            ];
                        }
                    }
                },
                legend: {
                    display: false // Hide default legend, a color scale is needed (often done manually or with a plugin)
                },
                datalabels: { // Configuration for chartjs-plugin-datalabels
                    color: '#000', // Color of the text
                    font: {
                        size: 9, // Smaller font size for readability in cells
                        weight: 'bold'
                    },
                    formatter: function(value, context) {
                        // Display average speed rounded to 1 decimal place
                        if (value && value.v && value.v.media_velocidade !== undefined) {
                            return value.v.media_velocidade.toFixed(1);
                        }
                        return ''; // Don't display anything for missing data
                    },
                    display: function(context) {
                        // Display label only if there is data for the cell
                        return context.dataset.data[context.dataIndex].v !== null;
                    }
                }
            },
            scales: {
                x: {
                    type: 'linear',
                    position: 'bottom', // X-axis at the bottom as in the image
                    ticks: {
                        callback: val => {
                            // Ensure ticks are integers and within bounds
                            if (Number.isInteger(val) && val >= 0 && val < 24) {
                                return `${val}`; // Display just the hour number
                            }
                            return ''; // Hide ticks outside 0-23
                        },
                        stepSize: 1, // Ensure a tick for each hour
                        autoSkip: false // Prevent skipping hours if space is limited
                    },
                    title: {
                        display: true,
                        text: 'Hora do Dia'
                    },
                    grid: {
                        display: false // Hide grid lines for a cleaner look
                    }
                },
                y: {
                    type: 'linear',
                    ticks: {
                        callback: val => {
                            // Ensure ticks are integers and within bounds
                            if (Number.isInteger(val) && val >= 0 && val < 7) {
                                return dias[val];
                            }
                            return ''; // Hide ticks outside 0-6
                        },
                        stepSize: 1 // Ensure a tick for each day
                    },
                    title: {
                        display: true,
                        text: 'Dia da Semana'
                    },
                    reverse: true, // Keep Sunday (0) at the top as in the image
                    grid: {
                        display: false // Hide grid lines
                    }
                }
            }
        }
    });

    // Note: A color scale legend like the one in your image
    // is not automatically generated by the Chart.js Matrix plugin.
    // You would typically need a separate plugin or custom code
    // to create that legend alongside the chart.
}

// Assuming diaxsemana is populated elsewhere, call the function to render the chart:
// weeklyHourlyHeatmap();


});