document.addEventListener('DOMContentLoaded', () => {
    const mapModal = document.getElementById('mapModal'); // Referência ao modal
    let mapInstance = null; // Instância do mapa Leaflet
    let routeLayer = null; // Camada da rota no mapa
    let heatmapChartInstance = null; // Instância do chart Highcharts

    // Adiciona ouvintes de evento aos botões que abrem o modal da rota
    document.querySelectorAll('.view-route').forEach(button => {
        button.addEventListener('click', async () => {
            const routeId = button.dataset.routeId;
            const modalTitle = mapModal.querySelector('#modalRouteName');
            const loadingIndicator = mapModal.querySelector('#loadingIndicator');
            const mapContainer = mapModal.querySelector('#mapContainer');
            const heatmapChartContainer = mapModal.querySelector('#heatmapChart');
            const insightsContainer = mapModal.querySelector('#insightsContainer'); // Exemplo: um container para insights

            // Limpa o conteúdo anterior e mostra o indicador de carregamento
            modalTitle.textContent = 'Carregando...';
            if (mapInstance) { // Destroi a instância anterior do mapa
                mapInstance.remove();
                mapInstance = null;
            }
            if (heatmapChartInstance) { // Destroi a instância anterior do heatmap chart
                heatmapChartInstance.destroy();
                heatmapChartInstance = null;
            }
            mapContainer.innerHTML = ''; // Limpa o container do mapa (necessário para recriar)
            heatmapChartContainer.innerHTML = ''; // Limpa o container do heatmap
            if (insightsContainer) insightsContainer.innerHTML = ''; // Limpa o container de insights, se existir

            loadingIndicator.style.display = 'block';

            try {
                const response = await fetch(`/api.php?action=get_route_details&route_id=${routeId}`);
                // Verifica se a resposta da rede foi OK
                if (!response.ok) {
                    throw new Error(`Erro HTTP! status: ${response.status}`);
                }
                const result = await response.json();

                // Verifica erros retornados pela API (estrutura do payload)
                if (result.error) {
                    // Melhoria: Exibir em um elemento no modal em vez de alert
                    console.error('Erro retornado pela API:', result.error);
                    alert('Erro ao buscar detalhes: ' + result.error);
                    return;
                }

                const { route, geometry, historic, heatmap, subroutes } = result.data;

                // Atualiza o título do modal
                modalTitle.textContent = route.name;

                // Renderiza mapa, insights e heatmap
                renderMap(mapContainer.id, geometry); // Passa o ID do container
                renderHeatmap(heatmapChartContainer.id, heatmap, route); // Passa o ID do container
                renderInsights(insightsContainer, route, geometry, heatmap); // Passa o container de insights

            } catch (err) {
                console.error('Erro ao carregar rota:', err);
                // Melhoria: Exibir em um elemento no modal em vez de alert
                alert('Erro ao carregar rota. Veja o console para mais detalhes.');
            } finally {
                // Oculta o indicador de carregamento
                loadingIndicator.style.display = 'none';
            }
        });
    });

    // Função para renderizar o mapa Leaflet
    function renderMap(containerId, geometry) {
        if (!geometry || geometry.length === 0) {
            console.warn("Geometria vazia, mapa não será renderizado.");
            document.getElementById(containerId).innerHTML = '<p>Sem dados de geometria para exibir.</p>';
            return;
        }

        // A instância anterior já foi removida antes de chamar esta função
        mapInstance = L.map(containerId).setView([geometry[0].y, geometry[0].x], 14); // y=latitude, x=longitude

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(mapInstance);

        // Mapeia os pontos de geometria para o formato [latitude, longitude] que o Leaflet espera
        const latlngs = geometry.map(p => [p.y, p.x]);
        routeLayer = L.polyline(latlngs, { color: 'blue', weight: 5 }).addTo(mapInstance);

        // Ajusta o zoom para mostrar toda a rota
        if (latlngs.length > 1) { // Precisa de pelo menos 2 pontos para calcular bounds
             mapInstance.fitBounds(routeLayer.getBounds());
        } else if (latlngs.length === 1) { // Apenas 1 ponto, centraliza nele
             mapInstance.setView(latlngs[0], 14);
        }
    }

    // Função para renderizar os insights/análises da rota
    function renderInsights(containerElement, route, geometry, heatmapData) {
        // Se não houver um container para insights, não faz nada
        if (!containerElement) {
            console.warn("Container para insights não encontrado.");
            return;
        }

        // Limpa o container antes de adicionar o novo conteúdo
        containerElement.innerHTML = '';

        // Dados iniciais
        const daysOfWeek = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        const periodos = {
            'Madrugada (0-5h)': [0, 1, 2, 3, 4, 5],
            'Manhã (6-11h)': [6, 7, 8, 9, 10, 11],
            'Tarde (12-17h)': [12, 13, 14, 15, 16, 17],
            'Noite (18-23h)': [18, 19, 20, 21, 22, 23]
        };
        // Assumindo que jam_level 0-5 corresponde a este array
        const jamLevels = ['Fluido', 'Leve', 'Moderado', 'Intenso', 'Congestionado', 'Parado'];

        // Processamento dos dados de velocidade para percentis
        const speeds = heatmapData.map(item => parseFloat(item.avg_speed)).filter(s => !isNaN(s)).sort((a, b) => a - b);
        const percentis = {
            p25: speeds.length > 0 ? speeds[Math.floor(speeds.length * 0.25)] : 0,
            p50: speeds.length > 0 ? speeds[Math.floor(speeds.length * 0.5)] : 0,
            p75: speeds.length > 0 ? speeds[Math.floor(speeds.length * 0.75)] : 0
        };

        // Otimização do processamento para calcular médias por dia, hora e período
        let totals = { days: {}, hours: {}, periods: {} };
        let counts = { days: {}, hours: {}, periods: {} };
        let maxValues = { day: -Infinity, hour: -Infinity, period: -Infinity };
        let best = { day: 'N/A', hour: 'N/A', period: 'N/A' }; // Valores padrão caso não haja dados

        heatmapData.forEach(item => {
            const day = parseInt(item.day_of_week); // Assume 1-indexed: 1=Domingo ... 7=Sábado
            const hour = parseInt(item.hour);
            const speed = parseFloat(item.avg_speed);

            if (isNaN(day) || isNaN(hour) || isNaN(speed)) return; // Ignora dados inválidos

            // Mapeia dia 1-7 para índice 0-6 (Domingo=0)
            const dayIndex = day - 1;
            if (dayIndex < 0 || dayIndex > 6) return; // Ignora dias fora do intervalo

            // Atualizar totais por dia (usando índice 0-6)
            totals.days[dayIndex] = (totals.days[dayIndex] || 0) + speed;
            counts.days[dayIndex] = (counts.days[dayIndex] || 0) + 1;

            // Atualizar totais por hora
            totals.hours[hour] = (totals.hours[hour] || 0) + speed;
            counts.hours[hour] = (counts.hours[hour] || 0) + 1;

            // Atualizar períodos
            const periodEntry = Object.entries(periodos).find(([name, hours]) => hours.includes(hour));
            if (periodEntry) {
                const periodName = periodEntry[0];
                totals.periods[periodName] = (totals.periods[periodName] || 0) + speed;
                counts.periods[periodName] = (counts.periods[periodName] || 0) + 1;
            }
        });

        // Calcular melhores momentos com base nas médias
        Object.entries(totals.days).forEach(([dayIndexStr, total]) => {
             const dayIndex = parseInt(dayIndexStr); // dayIndexStr é a chave (string "0" a "6")
            if (counts.days[dayIndex] > 0) {
                const avg = total / counts.days[dayIndex];
                if (avg > maxValues.day) {
                    maxValues.day = avg;
                    best.day = daysOfWeek[dayIndex]; // Exibe apenas o nome do dia
                }
            }
        });

        Object.entries(totals.hours).forEach(([hourStr, total]) => {
             const hour = parseInt(hourStr); // hourStr é a chave (string "0" a "23")
            if (counts.hours[hour] > 0) {
                const avg = total / counts.hours[hour];
                if (avg > maxValues.hour) {
                    maxValues.hour = avg;
                    best.hour = `${hour.toString().padStart(2, '0')}h`;
                }
            }
        });

        Object.entries(totals.periods).forEach(([period, total]) => {
            if (counts.periods[period] > 0) {
                const avg = total / counts.periods[period];
                if (avg > maxValues.period) {
                    maxValues.period = avg;
                    best.period = period;
                }
            }
        });


        // Formatação dos dados
        const avgSpeed = parseFloat(route.avg_speed || 0).toFixed(1);
        const historicSpeed = parseFloat(route.historic_speed || 0).toFixed(1);
        const speedVariation = (historicSpeed !== '0.0' && historicSpeed !== '0') ? ((parseFloat(avgSpeed) - parseFloat(historicSpeed)) / parseFloat(historicSpeed) * 100) : 0;
        const speedVariationFormatted = speedVariation.toFixed(1);
        const speedVariationArrow = speedVariation >= 0 ? '↑' : '↓';
        const jamLevel = parseInt(route.jam_level || 0);
        const jamLevelDescription = jamLevels[jamLevel] || 'Desconhecido';
        const irregularities = geometry.filter(p => p.irregularity_id != null).length;


        // Monta o HTML dos insights
        // Este é um exemplo básico. Você provavelmente irá querer um template HTML dedicado.
        const insightsHTML = `
            <h4>Análises da Rota</h4>
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Velocidade Média Atual:</strong> ${avgSpeed} km/h</p>
                    <p><strong>Velocidade Média Histórica:</strong> ${historicSpeed} km/h</p>
                    <p>
                        <strong>Variação Histórica:</strong>
                        <span class="${speedVariation >= 0 ? 'text-success' : 'text-danger'}">
                            ${speedVariationFormatted}% ${speedVariationArrow}
                        </span>
                         ${/* Exemplo de barra de progresso - pode precisar de CSS */''}
                         <div class="progress" style="height: 5px; margin-top: 5px;">
                            <div class="progress-bar ${speedVariation >= 0 ? 'bg-success' : 'bg-danger'}"
                                role="progressbar" style="width: ${Math.min(Math.abs(speedVariation), 100)}%;"
                                aria-valuenow="${Math.min(Math.abs(speedVariation), 100)}" aria-valuemin="0" aria-valuemax="100"
                                data-bs-toggle="tooltip" title="Diferença: ${ (parseFloat(avgSpeed) - parseFloat(historicSpeed)).toFixed(1) } km/h">
                            </div>
                        </div>
                    </p>

                    <p><strong>Nível de Congestionamento:</strong> ${jamLevel} - ${jamLevelDescription}</p>
                    <p><strong>Irregularidades Mapeadas:</strong> ${irregularities}</p>
                </div>
                 <div class="col-md-6">
                    <h5>Melhores Momentos para Percorrer a Rota (Média Histórica)</h5>
                    <p><strong>Dia da Semana:</strong> ${best.day}</p>
                    <p><strong>Hora do Dia:</strong> ${best.hour}</p>
                    <p><strong>Período:</strong> ${best.period}</p>

                    <h5>Distribuição de Velocidade (Heatmap Data)</h5>
                    <p><strong>Percentil 25:</strong> ${percentis.p25.toFixed(1)} km/h</p>
                    <p><strong>Percentil 50 (Mediana):</strong> ${percentis.p50.toFixed(1)} km/h</p>
                    <p><strong>Percentil 75:</strong> ${percentis.p75.toFixed(1)} km/h</p>
                    ${/* Container para o sparkline */''}
                     <p><strong>Variação das Velocidades:</strong> <span class="speed-sparkline" data-values="${speeds.join(',')}"></span></p>

                </div>
            </div>
        `;

        // Adiciona o HTML dos insights ao container
        containerElement.innerHTML = insightsHTML;

        // Inicializa tooltips para o novo conteúdo
        containerElement.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
             // Verifica se já existe uma instância para evitar duplicidade (opcional, Bootstrap costuma lidar bem)
             if (bootstrap.Tooltip.getInstance(el)) {
                 bootstrap.Tooltip.getInstance(el).dispose(); // Descarta a instância antiga
             }
            new bootstrap.Tooltip(el, { trigger: 'hover' });
        });

        // Inicializa sparklines para o novo conteúdo
         containerElement.querySelectorAll('.sparkline').forEach(spark => {
             const values = spark.dataset.values.split(',').map(Number).filter(v => !isNaN(v));
             if (values.length > 1) { // Sparkline precisa de pelo menos 2 valores
                  new Sparkline(spark, {
                     width: 100,
                     height: 30,
                     lineColor: '#4e73df', // Cor da linha (ex: azul primário do tema SB Admin 2)
                     fillColor: '#d1d3e2', // Cor de preenchimento (ex: cinza claro do tema SB Admin 2)
                     spotColor: null, // Remove ponto no último valor padrão
                     minSpotColor: '#f6c23e', // Cor para o ponto mínimo (ex: amarelo)
                     maxSpotColor: '#1cc88a', // Cor para o ponto máximo (ex: verde)
                     highlightSpotColor: '#e74a3b', // Cor do ponto ao passar o mouse (ex: vermelho)
                     highlightLineColor: '#212529', // Cor da linha ao passar o mouse (ex: cinza escuro)
                     spotRadius: 2 // Raio dos pontos
                 });
             } else {
                 spark.innerText = values.length === 1 ? `(${values[0].toFixed(1)} km/h)` : '(Sem dados)';
             }
         });
    }

    // Função para renderizar o gráfico de heatmap com Highcharts
    function renderHeatmap(containerId, heatmapData, route) {
        const heatmapChartContainer = document.getElementById(containerId);
         if (!heatmapChartContainer) {
             console.error(`Container do heatmap não encontrado: #${containerId}`);
             return;
         }

        if (!heatmapData || heatmapData.length === 0) {
            heatmapChartContainer.innerHTML = '<p>Sem dados de heatmap para exibir.</p>';
            return;
        }

        // Calcula velocidades mínima e máxima nos dados do heatmap
        const speeds = heatmapData.map(item => parseFloat(item.avg_speed)).filter(s => !isNaN(s));
        if (speeds.length === 0) {
             heatmapChartContainer.innerHTML = '<p>Sem dados de velocidade válidos para o heatmap.</p>';
             return;
        }

        const minSpeed = Math.min(...speeds);
        const maxSpeed = Math.max(...speeds);

        // Ajusta o limite máximo da escala de cores se o range for muito pequeno para visualização
        const range = maxSpeed - minSpeed;
        const adjustedMax = range < 5 ? maxSpeed + 5 : maxSpeed; // Se a diferença for muito pequena, aumenta o máximo

        // Prepara os dados para o Highcharts: [hour, dayIndex, speed]
        // Assume item.day_of_week é 1-indexed (1=Domingo, ..., 7=Sábado)
        const categories = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        const data = heatmapData.map(item => {
            const dayIndex = parseInt(item.day_of_week) - 1; // Mapeia 1-7 para 0-6
            const hour = parseInt(item.hour);
            const speed = parseFloat(item.avg_speed);

             if (isNaN(dayIndex) || isNaN(hour) || isNaN(speed) || dayIndex < 0 || dayIndex > 6 || hour < 0 || hour > 23) {
                 return null; // Ignora pontos de dados inválidos
             }
            return [hour, dayIndex, speed];
        }).filter(item => item !== null); // Remove dados inválidos

        // Destroi a instância anterior do chart, se existir
        if (heatmapChartInstance) {
            heatmapChartInstance.destroy();
        }

        // Cria o gráfico de heatmap
        heatmapChartInstance = Highcharts.chart(containerId, {
            chart: {
                type: 'heatmap',
                plotBorderWidth: 1,
                // Altura responsiva ou fixa
                height: 250 // Ajuste conforme necessário
            },
            title: {
                text: 'Velocidade Média por Hora e Dia da Semana',
                align: 'left',
                style: {
                    fontSize: '14px'
                }
            },
            xAxis: {
                categories: Array.from({ length: 24 }, (_, i) => `${i}h`), // Rótulos 0h, 1h, ... 23h
                title: { text: 'Hora do Dia' },
                labels: {
                    step: 3 // Mostra a cada 3 horas para evitar sobreposição em telas pequenas
                }
            },
            yAxis: {
                categories: categories, // Rótulos Dom, Seg, ... Sáb
                title: { text: 'Dia da Semana' },
                reversed: true // Inverte para ter Domingo no topo
            },
            colorAxis: {
                min: minSpeed, // Velocidade mínima nos dados
                max: adjustedMax, // Velocidade máxima ajustada
                // Cores: Vermelho para velocidade baixa, Verde para velocidade alta
                stops: [
                    [0, '#FF0000'], // Vermelho (velocidade mais baixa)
                    [0.5, '#FFFF00'], // Amarelo (velocidade média)
                    [1, '#00FF00'] // Verde (velocidade mais alta)
                ],
                 labels: {
                     format: '{value:.1f} km/h'
                 }
            },
            legend: {
                 enabled: true, // Habilita a legenda para a escala de cores
                 align: 'right',
                 layout: 'vertical',
                 margin: 0,
                 verticalAlign: 'top',
                 y: 25,
                 symbolHeight: 280
            },
            tooltip: {
                formatter: function () {
                    // Formato do tooltip: "Dia da Semana, Hora: Velocidade km/h"
                    const day = categories[this.point.y];
                    const hour = this.point.x;
                    const speed = this.point.value.toFixed(1);
                    return `<strong>${day}, ${hour}h:</strong> ${speed} km/h`;
                }
            },
            series: [{
                name: 'Velocidade Média (km/h)',
                borderWidth: 1,
                data: data,
                dataLabels: {
                    enabled: true,
                    color: '#000', // Cor do texto
                    format: '{point.value:.0f}', // Exibe a velocidade média (sem casas decimais se preferir)
                    style: {
                        textOutline: 'none', // Remove contorno de texto padrão
                        fontSize: '10px'
                    }
                }
            }],
            credits: { enabled: false } // Remove os créditos do Highcharts
        });
    }
});

// Remover o segundo listener DOMContentLoaded pois o código foi movido
// document.addEventListener('DOMContentLoaded', () => { ... });