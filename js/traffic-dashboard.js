/**
 * Traffic Jam Dashboard
 * Script para visualização dos dados de congestionamento
 */

document.addEventListener('DOMContentLoaded', function () {
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
    weeklyHourlyHeatmapPlotly();
    // 7. Gráfico de tipo de via
    // 10. Novos gráficos adicionados
    initTotalKmHourlyChart();      // Gráfico de extensão horária
    initWeekdayComparisonChart(); // Comparativo diário

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
            // Configuração responsiva dos gráficos
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
        const ctx = document.getElementById('monthlyChart');
        const rawData = mensal; // Mantém a variável original

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: rawData.map(d => d.mes),
                datasets: [{
                    label: 'Total de Ocorrências',
                    data: rawData.map(d => d.total),
                    borderColor: 'rgba(13, 110, 253, 0.9)',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Ocorrências'
                        }
                    }
                }
            }
        });
    }



    // Assume you have Plotly.js included in your HTML file.
    // You can include it via CDN like this:
    // <script src="https://cdn.plot.ly/plotly-2.30.0.min.js"></script>
    // Or by downloading the library and hosting it locally.

    function weeklyHourlyHeatmapPlotly() {
        const container = document.getElementById('heatmapChart');
        const rawData = diaxsemana; // Mantém a variável original
        var semanaldata = diaxsemana; // Dados de entrada, deve ser um array de objetos com {dia, hora, media_velocidade, quantidade, media_nivel, media_atraso}
        // Seleciona o contêiner onde o gráfico Plotly será renderizado.
        // Plotly geralmente renderiza em uma div, não diretamente em um canvas existente.
        // Certifique-se de que o contêiner exista no DOM antes de tentar renderizar o gráfico. 
        // Se você estiver usando Twig, certifique-se de que a variável semanaldata esteja disponível no contexto.
        // semanaldata deve ser um array de objetos com as propriedades: dia, hora, media_velocidade, quantidade, media_nivel, media_atraso 
        // Verifica se o contêiner e os dados existem
        if (!container || !semanaldata || semanaldata.length === 0) {
            console.error("Container do gráfico (.heat) ou dados não encontrados.");
            return;
        }

        // Dias da semana (na ordem para o eixo Y: Domingo no topo)
        const dias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

        // Inicializa arrays 2D para os valores do heatmap (z) e texto do tooltip (text_matrix).
        // A dimensão será 7 dias (linhas) x 24 horas (colunas).
        const z = Array(7).fill(null).map(() => Array(24).fill(null));
        const text_matrix = Array(7).fill(null).map(() => Array(24).fill(''));

        // Popula os arrays z e text_matrix com os dados de entrada.
        const speeds = [];
        semanaldata.forEach(d => {
            // Verifica se os valores de dia e hora são válidos para os índices do array
            if (d.dia >= 0 && d.dia < 7 && d.hora >= 0 && d.hora < 24) {
                const speed = d.media_velocidade;
                z[d.dia][d.hora] = speed;
                speeds.push(speed); // Coleta as velocidades para encontrar min/max

                // Formata o texto completo para o tooltip
                text_matrix[d.dia][d.hora] = `Quantidade: ${d.quantidade}<br>Nível Médio: ${parseFloat(d.media_nivel).toFixed(2)}<br>Velocidade Média: ${speed.toFixed(2)} km/h<br>Atraso Médio: ${parseFloat(d.media_atraso).toFixed(2)}`;
            }
        });

        // Calcula a velocidade mínima e máxima para mapeamento de cores.
        // Define valores padrão caso não haja dados para evitar erros.
        const minSpeed = speeds.length > 0 ? Math.min(...speeds) : 0;
        const maxSpeed = speeds.length > 0 ? Math.max(...speeds) : 100; // Valor máximo padrão se nenhum dado for encontrado

        // Define os rótulos dos eixos X (horas) e Y (dias).
        const x_labels = Array.from({ length: 24 }, (_, i) => i); // [0, 1, ..., 23]
        const y_labels = dias; // ['Dom', 'Seg', ..., 'Sáb']

        // Define os dados para o gráfico heatmap
        const data = [{
            z: z, // Matriz 2D com os valores a serem mapeados pela cor
            x: x_labels, // Rótulos do eixo X
            y: y_labels, // Rótulos do eixo Y
            type: 'heatmap', // Tipo do gráfico
            colorscale: 'Viridis', // Escala de cores (Viridis é semelhante à imagem)
            showscale: true, // Mostra a barra de escala de cor
            colorbar: {
                title: {
                    text: 'Velocidade Média (km/h)', // Título da barra de cor
                    side: 'right'
                }
            },
            text: text_matrix, // Matriz 2D com o texto personalizado para cada célula
            hoverinfo: 'text', // Mostra apenas o texto personalizado no tooltip
            zmin: minSpeed, // Valor mínimo para a escala de cor
            zmax: maxSpeed  // Valor máximo para a escala de cor
        }];

        // Define o layout do gráfico
        const layout = {
            title: 'Velocidade Média por Dia da Semana e Hora', // Título principal do gráfico
            xaxis: {
                title: 'Hora do Dia', // Título do eixo X
                tickvals: x_labels, // Define onde os ticks do eixo X aparecem
                ticktext: x_labels.map(hour => `${hour}`), // Define o texto dos ticks do eixo X
                side: 'bottom', // Posição do eixo X
                type: 'category', // Trata os ticks como categorias para espaçamento uniforme
                tickmode: 'array',
                showgrid: false // Oculta as linhas de grade do eixo X
            },
            yaxis: {
                title: 'Dia da Semana', // Título do eixo Y
                tickvals: Array.from({ length: 7 }, (_, i) => i), // Define onde os ticks do eixo Y aparecem
                ticktext: y_labels, // Define o texto dos ticks do eixo Y
                autorange: 'reversed', // Inverte a ordem do eixo Y para ter Domingo no topo
                type: 'category', // Trata os ticks como categorias para espaçamento uniforme
                tickmode: 'array',
                showgrid: false // Oculta as linhas de grade do eixo Y
            },
            // Ajusta as margens para melhor visualização dos rótulos e títulos
            margin: {
                l: 70, // margem esquerda
                r: 20, // margem direita
                b: 60, // margem inferior
                t: 60, // margem superior
            },
            hovermode: 'closest', // Modo do tooltip
            // Define as dimensões iniciais do gráfico com base no contêiner
            width: container.offsetWidth,
            height: container.offsetWidth / 2, // Ajuste a proporção conforme necessário
        };

        // Configurações adicionais (opcional)
        const config = {
            responsive: true // Torna o gráfico responsivo
            // displayModeBar: false // Oculta a barra de ferramentas do Plotly
        };

        // Limpa o conteúdo anterior da div contêiner (se houver)
        container.innerHTML = '';

        // Renderiza o gráfico na div contêiner especificada
        Plotly.newPlot(container, data, layout, config);

        // Nota: Exibir os valores numéricos diretamente dentro de cada célula
        // (como na imagem que você mostrou) não é uma funcionalidade padrão e simples
        // do tipo heatmap no Plotly. Geralmente, isso é feito usando anotações,
        // o que pode adicionar complexidade considerável ao código, especialmente
        // com muitos pontos de dados ou dados faltantes. O tooltip já mostra os detalhes.
    }

    // Para usar esta função, chame-a passando seus dados (diaxsemana)
    // e o seletor do elemento HTML onde o gráfico deve ser renderizado:
    // weeklyHourlyHeatmapPlotly(diaxsemana, '.grafico-calor');

    function initTotalKmHourlyChart() {
        const ctx = document.getElementById('totalKmHourlyChart');
        const rawData = km_por_hora; // Usa a variável existente

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: rawData.map(d => `${d.hora}h`),
                datasets: [{
                    label: 'Extensão Total (km)',
                    data: rawData.map(d => d.total_km),
                    backgroundColor: 'rgba(220, 53, 69, 0.7)'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Kilômetros' }
                    }
                }
            }
        });
    }

    function initWeekdayComparisonChart() {
        const ctx = document.getElementById('weekdayComparisonChart');
        const totalData = km_por_dia_semana; // Variável existente
        const avgData = media_km_por_dia_semana; // Variável existente

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: totalData.map(d => d.dia_semana),
                datasets: [{
                    label: 'Total KM',
                    data: totalData.map(d => d.total_km),
                    backgroundColor: 'rgba(255, 159, 64, 0.7)'
                }, {
                    label: 'Média por Ocorrência',
                    data: avgData.map(d => d.media_km),
                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                    type: 'line',
                    borderWidth: 3,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Kilômetros' }
                    }
                },
                plugins: {
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                }
            }
        });
    }
});