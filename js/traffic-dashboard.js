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
    hourlyChart();
    // 7. Gráfico de tipo de via
    //initRoadTypeChart();
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
        document.addEventListener('DOMContentLoaded', function () {
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
        });
    }

    function hourlyChart(data) {
    document.addEventListener('DOMContentLoaded', function () {
        const hourlyData = data;

        const maxTotal = Math.max(...hourlyData.map(d => d.total));
        const getColor = (value) => {
            const percent = value / maxTotal;
            const r = Math.round(255 * percent);
            const g = Math.round(255 * (1 - percent));
            return `rgb(${r},${g},0)`;
        };

        const chartContainer = document.createElement('div');
        const canvas = document.createElement('canvas');
        canvas.id = 'hourlyChart';
        chartContainer.appendChild(canvas);

        const container = document.querySelector('#time .row');
        if (container) {
            container.prepend(chartContainer);
        }

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: hourlyData.map(d => `${d.hora}:00`),
                datasets: [{
                    label: 'Congestionamentos por Hora',
                    data: hourlyData.map(d => d.total),
                    backgroundColor: hourlyData.map(d => getColor(d.total))
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Heatmap de Congestionamentos por Hora'
                    },
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    });
    }
});