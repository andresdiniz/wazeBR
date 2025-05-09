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
    // 5. Gráfico mensal
    initMonthlyChart();
    // 6. Gráfico de cidades
    initCityChart();
    // 7. Gráfico de tipo de via
    initRoadTypeChart();
    // 8. Gráfico de relação entre comprimento e atraso
    initLengthVsDelayChart();

    function initHourlyChart() {
        const ctx = document.getElementById('hourlyChart');
        if (!ctx) return;
    
        const data = dashboardData.horario;
    
        // Adaptado ao novo formato: [{ hora, total }]
        const labels = data.map(item => `${item.hora}:00`);
        const congestionamentos = data.map(item => item.total);
    
        new Chart(ctx, createDualAxisChartConfig(
            labels,
            [congestionamentos, 'Congestionamentos', 'bar', 'rgba(13, 110, 253, 0.7)'],
            [[], 'Atraso Médio (min)', 'line', 'rgba(220, 53, 69, 0.7)'], // Linha vazia, pois não há avg_delay
            'Número de Congestionamentos',
            'Atraso Médio (min)' // Esse eixo ficará vazio
        ));
    }
    

    function initWeekdayChart() {
        const ctx = document.getElementById('weekdayChart');
        if (!ctx) return;
    
        const rawData = dashboardData.semanal; // ajuste conforme a real chave
        if (!Array.isArray(rawData)) {
            console.error('Dados semanais inválidos');
            return;
        }
    
        // Ordem desejada dos dias da semana (em inglês)
        const dayOrder = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const dayLabelsPT = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
    
        // Ordenar dados conforme dia da semana
        const data = dayOrder.map(dayName => rawData.find(d => d.dia === dayName) || { dia: dayName, total: 0 });
        
        console.log(data); // Para depuração
        
        const labels = data.map(d => {
            const idx = dayOrder.indexOf(d.dia);
            return dayLabelsPT[idx] || d.dia;
        });
    
        const congestionamentos = data.map(d => d.total);
    
        new Chart(ctx, createSingleAxisChartConfig(
            labels,
            [congestionamentos, 'Congestionamentos', 'bar', 'rgba(25, 135, 84, 0.7)']
        ));
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

    function initLevelChart() {
        const ctx = document.getElementById('levelChart');
        if (!ctx) return;

        const data = dashboardData.congestion_by_level;
        const labels = data.map(item => `Nível ${item.level}`);
        
        new Chart(ctx, createDualAxisChartConfig(
            labels,
            [data.map(i => i.jam_count), 'Congestionamentos', 'bar', 'rgba(13, 202, 240, 0.7)'],
            [data.map(i => i.avg_delay/60), 'Atraso Médio (min)', 'line', 'rgba(220, 53, 69, 0.7)'],
            'Número de Congestionamentos',
            'Atraso Médio (min)'
        ));
    }

    function initDelayDistributionChart() {
        const ctx = document.getElementById('delayDistChart');
        if (!ctx) return;

        const data = dashboardData.delay_distribution;
        
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: data.map(i => i.delay_range),
                datasets: [{
                    data: data.map(i => i.jam_count),
                    backgroundColor: colorPalette,
                    borderColor: colorPaletteBorders,
                    borderWidth: 1
                }]
            },
            options: {
                ...chartOptions,
                plugins: {
                    legend: { position: 'right' }
                }
            }
        });
    }

    function initMonthlyChart() {
        const ctx = document.getElementById('monthlyChart');
        if (!ctx) return;

        const months = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dec'];
        const data = dashboardData.monthly_trend.map(item => ({
            ...item,
            month_name: months[item.month - 1] || item.month
        }));
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(i => i.month_name),
                datasets: [
                    {
                        label: 'Congestionamentos',
                        data: data.map(i => i.jam_count),
                        backgroundColor: 'rgba(13, 110, 253, 0.7)',
                        borderColor: 'rgba(13, 110, 253, 1)',
                        borderWidth: 2,
                        yAxisID: 'y',
                        tension: 0.4
                    },
                    {
                        label: 'Atraso Médio (min)',
                        data: data.map(i => i.avg_delay/60),
                        backgroundColor: 'rgba(220, 53, 69, 0.7)',
                        borderColor: 'rgba(220, 53, 69, 1)',
                        borderWidth: 2,
                        yAxisID: 'y1',
                        tension: 0.4
                    }
                ]
            },
            options: {
                ...chartOptions,
                scales: dualAxisScales(
                    'Número de Congestionamentos',
                    'Atraso Médio (min)'
                )
            }
        });
    }

    function initCityChart() {
        const ctx = document.getElementById('cityChart');
        if (!ctx) return;

        const data = dashboardData.city_analysis
            .sort((a, b) => b.jam_count - a.jam_count)
            .slice(0, 10);
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(i => i.city),
                datasets: [{
                    label: 'Congestionamentos',
                    data: data.map(i => i.jam_count),
                    backgroundColor: 'rgba(25, 135, 84, 0.7)',
                    borderColor: 'rgba(25, 135, 84, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                ...chartOptions,
                indexAxis: 'y',
                plugins: { legend: { display: false } }
            }
        });
    }

    function initRoadTypeChart() {
        const ctx = document.getElementById('roadTypeChart');
        if (!ctx) return;

        const typeMap = {
            1: 'Rua', 2: 'Avenida', 3: 'Rodovia',
            4: 'Rua Principal', 5: 'Freeway',
            6: 'Via Expressa', 7: 'Estrada de Terra', 8: 'Outro'
        };
        
        const data = dashboardData.roadtype_analysis
            .map(item => ({
                ...item,
                typeName: typeMap[item.roadType] || `Tipo ${item.roadType}`
            }));
        
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.map(i => i.typeName),
                datasets: [{
                    data: data.map(i => i.jam_count),
                    backgroundColor: colorPalette,
                    borderColor: colorPaletteBorders,
                    borderWidth: 1
                }]
            },
            options: chartOptions
        });
    }

    function initLengthVsDelayChart() {
        const ctx = document.getElementById('lengthVsDelayChart');
        if (!ctx) return;

        const data = dashboardData.length_vs_delay;
        
        new Chart(ctx, createDualAxisChartConfig(
            data.map(i => i.length_range),
            [data.map(i => i.jam_count), 'Congestionamentos', 'bar', 'rgba(13, 202, 240, 0.7)'],
            [data.map(i => i.avg_delay/60), 'Atraso Médio (min)', 'line', 'rgba(220, 53, 69, 0.7)'],
            'Número de Congestionamentos',
            'Atraso Médio (min)'
        ));
    }
});