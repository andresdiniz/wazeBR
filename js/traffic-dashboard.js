/**
 * Traffic Jam Dashboard
 * Script para visualização dos dados de congestionamento
 */

document.addEventListener('DOMContentLoaded', function () {
    // --- Configurações Globais ---
    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
            }
        }
    };

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

    const colorPaletteBorders = (colorsArray) =>
        colorsArray.map(c => c.replace(/[\d.]+\)$/g, '1)'));

    const borderColors = colorPaletteBorders(colorPalette);

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

    function isWeekday(dateString) {
        const date = new Date(dateString);
        const dayOfWeek = date.getDay(); // 0 para Domingo, 1 para Segunda, ..., 6 para Sábado
        return dayOfWeek >= 1 && dayOfWeek <= 5; // Segunda a Sexta são dias úteis
    }

    // --- Funções Utilitárias para Criação de Gráficos ---
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
                ...chartOptions,
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
                        borderColor: colorPaletteBorders([pColor])[0],
                        borderWidth: 1,
                        yAxisID: 'y',
                        type: pType
                    },
                    {
                        label: sLabel,
                        data: sData,
                        borderColor: colorPaletteBorders([sColor])[0],
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

    // --- Inicialização dos Gráficos ---

    // 1. Gráfico de distribuição por hora
    let hourlyChartInstance = null;
    function initHourlyChart() {
        const ctx = document.getElementById('hourlyChart');
        if (!ctx) return;

        const labels = data.map(item => `${item.hora}:00`);
        const congestionamentos = data.map(item => item.total);

        if (hourlyChartInstance) {
            hourlyChartInstance.destroy();
        }

        hourlyChartInstance = new Chart(ctx, createSingleAxisChartConfig(
            labels,
            [congestionamentos, 'Congestionamentos', 'bar', colorPalette[0]],
            [[], 'Atraso Médio (min)', 'line', colorPalette[1]] // Linha vazia, pois não há avg_delay
        ));
    }
    initHourlyChart();

    // 2. Gráfico de distribuição por dia da semana
    let weekdayChartInstance = null;
    function initWeekdayChart() {
        const ctx = document.getElementById('weekdayChart');
        if (!ctx) return;

        if (weekdayChartInstance) {
            weekdayChartInstance.destroy();
        }

        const rawData = datasemana;
        const dayOrder = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const dayLabelsPT = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        const processedData = dayOrder.map(dayName => rawData.find(d => d.dia === dayName) || { dia: dayName, total: 0 });

        const labels = processedData.map(d => {
            const idx = dayOrder.indexOf(d.dia);
            return dayLabelsPT[idx] || d.dia;
        });

        const congestionamentos = processedData.map(d => d.total);

        weekdayChartInstance = new Chart(ctx, createSingleAxisChartConfig(
            labels,
            [congestionamentos, 'Congestionamentos', 'bar', colorPalette[2]]
        ));
    }
    initWeekdayChart();

    // 3. Gráfico de nível de congestionamento
    function initLevelChart() {
        const ctx = document.getElementById('levelChart');
        if (!ctx) return;

        const chartData = dadosnivel;
        const labels = chartData.map(item => `Nível ${item.nivel}`);
        const congestionamentos = chartData.map(item => item.total);

        new Chart(ctx, createSingleAxisChartConfig(
            labels,
            [congestionamentos, 'Congestionamentos', 'bar', colorPalette[4]]
        ));
    }
    initLevelChart();

    // 4. Gráfico de distribuição de atrasos
    function initDelayDistributionChart() {
        const ctx = document.getElementById('delayDistChart');
        if (!ctx) return;

        const chartData = dadosatraso;
        const labels = chartData.map(i => i.rua);
        const values = chartData.map(i => i.total);

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data: values,
                    backgroundColor: colorPalette,
                    borderColor: borderColors,
                    borderWidth: 1,
                    hoverOffset: 10
                }]
            },
            options: {
                ...chartOptions,
                cutout: '50%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 15,
                            font: { size: 12 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const label = context.label || '';
                                const value = context.raw;
                                const dataset = context.chart.data.datasets[context.datasetIndex];
                                const total = dataset.data.reduce((sum, val) => sum + val, 0);
                                const percent = ((value / total) * 100).toFixed(1);
                                return `${label}: ${value} (${percent}%)`;
                            }
                        }
                    }

                }
            }
        });
    }
    initDelayDistributionChart();

    // 5. Gráfico de comprimento vs atraso
    function timexlenght() {
        const chartData = atrasocomprimento.map(item => ({
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
                    data: chartData,
                    backgroundColor: colorPalette[7],
                    borderColor: colorPaletteBorders([colorPalette[7]])[0],
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
                        title: { display: true, text: 'Comprimento (metros)' }
                    },
                    y: {
                        title: { display: true, text: 'Atraso (segundos)' }
                    }
                }
            }
        });
    }
    timexlenght();

    // 6. Gráfico mensal
    function initMonthlyChart() {
        const ctx = document.getElementById('monthlyChart');
        const chartData = mensal;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.map(d => d.mes),
                datasets: [{
                    label: 'Total de Ocorrências',
                    data: chartData.map(d => d.total),
                    borderColor: colorPalette[0],
                    backgroundColor: colorPalette[0].replace(/, 0\.7\)$/, ', 0.1)'),
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                ...chartOptions,
                plugins: {
                    tooltip: { mode: 'index', intersect: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Ocorrências' }
                    }
                }
            }
        });
    }
    initMonthlyChart();

    // 7. Gráfico de cidades (usando Plotly)
    function weeklyHourlyHeatmapPlotly() {
        const container = document.getElementById('heatmapChart');
        const chartData = diaxsemana;
        console.log("Dados do heatmap:", chartData);

        if (!container || !chartData || chartData.length === 0) {
            console.error("Container do gráfico (.heat) ou dados não encontrados para o heatmap.");
            return;
        }

        const dias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        const z = Array(7).fill(null).map(() => Array(24).fill(0)); // Agora preenchido com quantidade
        const textMatrix = Array(7).fill(null).map(() => Array(24).fill(''));
        const quantities = [];

        chartData.forEach(d => {
            if (d.dia >= 0 && d.dia < 7 && d.hora >= 0 && d.hora < 24) {
                const quantidade = d.quantidade;
                z[d.dia][d.hora] = quantidade;
                quantities.push(quantidade);
                textMatrix[d.dia][d.hora] = `Quantidade: ${quantidade}<br>Nível Médio: ${parseFloat(d.media_nivel).toFixed(2)}<br>Velocidade Média: ${parseFloat(d.media_velocidade).toFixed(2)} km/h<br>Atraso Médio: ${parseFloat(d.media_atraso).toFixed(2)}`;
            }
        });

        const minQ = quantities.length > 0 ? Math.min(...quantities) : 0;
        const maxQ = quantities.length > 0 ? Math.max(...quantities) : 100;

        const xLabels = Array.from({ length: 24 }, (_, i) => i);
        const yLabels = dias;

        const data = [{
            z: z,
            x: xLabels,
            y: yLabels,
            type: 'heatmap',
            colorscale: [
                [0, 'green'],
                [0.5, 'yellow'],
                [1, 'red']
            ],
            showscale: true,
            colorbar: { title: { text: 'Quantidade (KMs)', side: 'right' } },
            text: textMatrix,
            hoverinfo: 'text',
            zmin: minQ,
            zmax: maxQ
        }];

        const layout = {
            title: 'Quantidade por Dia da Semana e Hora',
            xaxis: { title: 'Hora do Dia', tickvals: xLabels, ticktext: xLabels.map(h => `${h}`), side: 'bottom', type: 'category', tickmode: 'array', showgrid: false },
            yaxis: { title: 'Dia da Semana', tickvals: Array.from({ length: 7 }, (_, i) => i), ticktext: yLabels, autorange: 'reversed', type: 'category', tickmode: 'array', showgrid: false },
            margin: { l: 70, r: 20, b: 60, t: 60 },
            hovermode: 'closest',
            width: container.offsetWidth,
            height: container.offsetWidth / 2,
        };

        const config = { responsive: true };

        container.innerHTML = '';
        Plotly.newPlot(container, data, layout, config);
    }
    weeklyHourlyHeatmapPlotly();

    // 8. Gráfico de extensão horária
    function initTotalKmHourlyChart() {
        const ctx = document.getElementById('totalKmHourlyChart');
        const chartData = km_por_hora;

        if (!ctx || !chartData?.length) {
            console.error("Dados ou canvas não encontrados.");
            return;
        }

        const colorPalette = ['#FF6384', '#36A2EB', '#4BC0C0']; // [destaque, padrão, linha]
        const chartOptions = {}; // coloque suas opções aqui se tiver

        const horas = chartData.map(d => d.hora);
        const totaisPorHora = chartData.map(d => d.total_km);

        // Identificar pico
        const maxValor = Math.max(...totaisPorHora);
        const maxIndex = totaisPorHora.indexOf(maxValor);

        // Cores das barras: destaque apenas o pico
        const barColors = totaisPorHora.map((_, i) =>
            i === maxIndex ? colorPalette[0] : colorPalette[1]
        );

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: horas.map(h => `${String(h).padStart(2, '0')}h`),
                datasets: [
                    {
                        label: 'Total por Hora (km)',
                        data: totaisPorHora,
                        backgroundColor: barColors,
                        yAxisID: 'y-km'
                    },
                    {
                        type: 'line',
                        label: `Pico: ${maxValor.toFixed(2)} km às ${String(horas[maxIndex]).padStart(2, '0')}h`,
                        data: totaisPorHora.map(() => maxValor),
                        borderColor: colorPalette[2],
                        borderWidth: 2,
                        borderDash: [6, 6],
                        fill: false,
                        pointRadius: 0,
                        yAxisID: 'y-km'
                    }
                ]
            },
            options: {
                ...chartOptions,
                plugins: {
                    legend: { display: true },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return `${context.dataset.label}: ${context.raw.toFixed(2)} km`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        id: 'y-km',
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Kilômetros'
                        }
                    }
                }
            }
        });
    }
    initTotalKmHourlyChart();

    // 9. Comparativo diário
    function initWeekdayComparisonChart() {
        const ctx = document.getElementById('weekdayComparisonChart');
        const totalData = km_por_dia_semana;
        const avgData = media_km_por_dia_semana;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: totalData.map(d => d.dia_semana),
                datasets: [{
                    label: 'Total KM',
                    data: totalData.map(d => d.total_km),
                    backgroundColor: colorPalette[3]
                }, {
                    label: 'Média por Ocorrência',
                    data: avgData.map(d => d.media_km),
                    backgroundColor: colorPalette[4],
                    type: 'line',
                    borderWidth: 3,
                    tension: 0.3
                }]
            },
            options: {
                ...chartOptions,
                plugins: { tooltip: { mode: 'index', intersect: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Kilômetros' }
                    }
                }
            }
        });
    }
    initWeekdayComparisonChart();

    // --- Gráficos Adicionais (parecem não estar sendo chamados no fluxo principal) ---
    function initDurationTimeScatter() {
        const ctx = document.getElementById('durationTimeChart');
        const hourlyDurations = {};

        jams.forEach(j => {
            const receivedTime = new Date(j.date_received);
            const hour = receivedTime.getHours();
            const updatedTime = new Date(j.date_updated);
            const durationMinutes = (updatedTime.getTime() - receivedTime.getTime()) / (1000 * 60);
            if (!hourlyDurations[hour]) {
                hourlyDurations[hour] = { sum: 0, count: 0 };
            }
            hourlyDurations[hour].sum += durationMinutes;
            hourlyDurations[hour].count++;
        });

        const rawData = Object.keys(hourlyDurations)
            .sort((a, b) => parseInt(a) - parseInt(b)) // Ordena por hora
            .map(hour => ({
                x: parseInt(hour),
                y: hourlyDurations[hour].sum / hourlyDurations[hour].count
            }));

        console.log("Dados de média de duração por hora:", rawData);

        new Chart(ctx, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Média da Duração dos Congestionamentos por Hora',
                    data: rawData,
                    backgroundColor: 'rgba(255, 99, 132, 0.5)'
                }]
            },
            options: {
                scales: {
                    x: { title: { display: true, text: 'Hora do Recebimento' }, ticks: { stepSize: 1 } },
                    y: { title: { display: true, text: 'Média da Duração (minutos)' } }
                },
                plugins: {
                    // @ts-ignore
                    trendline: {
                        lineStyle: 'dashed',
                        width: 2,
                        color: '#ff6384'
                    }
                }
            }
        });
    }
    initDurationTimeScatter()

    function initWeekendComparison() {
        const ctx = document.getElementById('weekendComparisonChart');

        const weekdayDelays = jams.filter(j => isWeekday(j.date_received)).map(j => j.delay / 60);
        const weekendDelays = jams.filter(j => !isWeekday(j.date_received)).map(j => j.delay / 60);

        const avgWeekdayDelay = weekdayDelays.length > 0 ? weekdayDelays.reduce((a, b) => a + b, 0) / weekdayDelays.length : 0;
        const avgWeekendDelay = weekendDelays.length > 0 ? weekendDelays.reduce((a, b) => a + b, 0) / weekendDelays.length : 0;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Dias Úteis', 'Fim de Semana'],
                datasets: [{
                    label: 'Média de Atraso (minutos)',
                    data: [avgWeekdayDelay, avgWeekendDelay],
                    backgroundColor: ['rgba(54, 162, 235, 0.7)', 'rgba(255, 99, 132, 0.7)']
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Média de Atraso (minutos)'
                        }
                    }
                }
            }
        });
    }
    initWeekendComparison();

    function initRoadTypeRadar() {
        const ctx = document.getElementById('roadTypeRadarChart');
        const metrics = ['speedKMH', 'delay'];
        const roadTypes = [...new Set(jams.map(jam => jam.roadType).filter(Boolean))].sort(); // Ordena os tipos de via

        if (!roadTypes || roadTypes.length === 0) {
            console.warn("Nenhum tipo de via encontrado nos dados para o gráfico de barras.");
            return;
        }

        const datasets = metrics.map(metric => ({
            label: metric,
            data: roadTypes.map(rt => avgByRoadType(rt, metric, jams)),
            backgroundColor: colorPalette[metrics.indexOf(metric) % colorPalette.length]
        }));

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: roadTypes,
                datasets: datasets
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Valor' // Podemos personalizar isso por métrica se necessário
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Tipo de Via'
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Comparação por Tipo de Via'
                    }
                },
                animation: {
                    onComplete: function () {
                        const ctx = this.chart.ctx;
                        ctx.font = "bold 10px Arial";
                        ctx.fillStyle = "#333";
                        ctx.textAlign = "center";

                        this.data.datasets.forEach((dataset, i) => {
                            const meta = this.chart.getDatasetMeta(i);
                            meta.data.forEach((bar, index) => {
                                const data = dataset.data[index];
                                const value = Math.round(data * 100) / 100;
                                ctx.fillText(value, bar.x, bar.y - 5);
                            });
                        });
                    }
                }
            }
        });
    }

    // Função placeholder para calcular a média por tipo de via e métrica
    function avgByRoadType(roadType, metric, data) {
        const filtered = data.filter(item => item.roadType === roadType && item[metric] !== undefined);
        if (filtered.length > 0) {
            return filtered.reduce((sum, item) => sum + item[metric], 0) / filtered.length;
        }
        return 0;
    }
    initRoadTypeRadar();

    function initDurationHistogram() {
        const ctx = document.getElementById('durationHistogram');
        const colorPalette = ['#4CAF50', '#FFC107', '#FF9800', '#F44336'];

        const durations = jams.map(j => {
            const receivedTime = new Date(j.date_received);
            const updatedTime = new Date(j.date_updated);
            console.log(`Recebido: ${receivedTime}, Atualizado: ${updatedTime}`);
            return (updatedTime.getTime() - receivedTime.getTime()) / (1000 * 60); // duração em minutos
        });

        console.log('Durações de todos os congestionamentos:', durations);

        // Inicializa os contadores para os intervalos corretos
        const counts = [0, 0, 0, 0]; // [0–15, 15–30, 30–45, >45]

        durations.forEach(duration => {
            if (duration <= 15) {
                counts[0]++;
            } else if (duration > 15 && duration <= 30) {
                counts[1]++;
            } else if (duration > 30 && duration <= 45) {
                counts[2]++;
            } else {
                counts[3]++;
            }
        });

        console.log('Contagem de durações por intervalo:', counts);

        const histogramData = {
            labels: ['0–15min', '15–30min', '30–45min', '45+min'],
            datasets: [{
                label: 'Quantidade de Congestionamentos',
                data: counts,
                backgroundColor: colorPalette
            }]
        };

        console.log('Dados para o histograma (frequência por duração):', histogramData);

        new Chart(ctx, {
            type: 'bar',
            data: histogramData,
            options: {
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Frequência'
                        }
                    }
                },
                plugins: {
                    legend: { display: true }
                }
            }
        });
    }
    initDurationHistogram();

    function averageDelayBoxPlot() {
        const container = document.getElementById('delayBoxPlotChart'); // Novo ID para o container do gráfico
        const chartData = diaxsemana; // Reutilizamos os mesmos dados


        if (!container || !chartData || chartData.length === 0) {
            console.error("Container do gráfico (delayBoxPlotChart) ou dados não encontrados para o Box Plot.");
            return;
        }

        // Preparar os dados para o Box Plot
        // Vamos agrupar as médias de atraso por hora
        const delaysByHour = {};
        for (let i = 0; i < 24; i++) {
            delaysByHour[i] = []; // Inicializa um array vazio para cada hora
        }

        chartData.forEach(d => {
            if (d.hora >= 0 && d.hora < 24) {
                // Garante que o valor é um número
                const mediaAtraso = parseFloat(d.media_atraso);
                if (!isNaN(mediaAtraso)) {
                    delaysByHour[d.hora].push(mediaAtraso);
                }
            }
        });

        // Criar as traces do Plotly para cada hora
        const data = [];
        for (let i = 0; i < 24; i++) {
            if (delaysByHour[i].length > 0) {
                data.push({
                    y: delaysByHour[i],
                    name: `${i}h`, // Nome da caixa (ex: "8h")
                    type: 'box',
                    boxpoints: 'Outliers', // Mostra apenas os outliers
                    marker: {
                        color: 'rgba(50,171,96,0.7)', // Cor das caixas
                    },
                    line: {
                        color: 'rgba(50,171,96,1.0)', // Cor das linhas dos bigodes
                    },
                    hoverinfo: 'y', // Mostra apenas os valores do eixo Y no hover
                    hovertemplate: `Hora: ${i}<br>Média Atraso: %{y}<extra></extra>` // Personaliza o tooltip
                });
            }
        }

        const layout = {
            title: 'Distribuição da Média de Atraso por Hora do Dia',
            yaxis: {
                title: 'Média de Atraso (minutos)',
                zeroline: false
            },
            xaxis: {
                title: 'Hora do Dia',
                tickvals: Array.from({ length: 24 }, (_, i) => i), // Mostra todos os ticks de hora
                ticktext: Array.from({ length: 24 }, (_, i) => `${i}h`) // Label para cada tick
            },
            margin: { t: 50, b: 50, l: 60, r: 20 },
            showlegend: false, // Não precisamos de legenda para cada hora individual
            width: container.offsetWidth,
            height: container.offsetWidth / 2, // Mantém a proporção
        };

        const config = { responsive: true };

        // Limpa o container antes de plotar
        container.innerHTML = '';
        Plotly.newPlot(container, data, layout, config);
    }
    averageDelayBoxPlot();
});

// Funções auxiliares (presumivelmente definidas em outro lugar no seu código)
// isWeekday(date_string)
// randColor()
// avgByRoadType(roadType, metric)