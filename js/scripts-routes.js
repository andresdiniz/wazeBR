document.addEventListener('DOMContentLoaded', () => {
    const formatDate = (dateInput) => {
        const date = typeof dateInput === 'string' ? new Date(dateInput) : dateInput;
        if(isNaN(date)) return 'N/A';
        return date.toLocaleDateString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
    };


    const mapModal = document.getElementById('mapModal');
    let mapInstance = null;
    let routeLayer = null;
    let heatmapChartInstance = null;
    let lineChartInstance = null;

    document.querySelectorAll('.view-route').forEach(button => {
        button.addEventListener('click', async () => {
            const routeId = button.dataset.routeId;
            const modalTitle = mapModal.querySelector('#modalRouteName');
            const loadingIndicator = mapModal.querySelector('#loadingIndicator');
            const mapContainer = mapModal.querySelector('#mapContainer');
            const heatmapChartContainer = mapModal.querySelector('#heatmapChart');
            const insightsContainer = mapModal.querySelector('#insightsContainer');
            const lineChartContainer = mapModal.querySelector('#lineChartContainer');

            modalTitle.textContent = 'Carregando...';

            // Limpar instâncias anteriores
            if (mapInstance) mapInstance.remove();
            if (heatmapChartInstance) heatmapChartInstance.destroy();
            if (lineChartInstance) lineChartInstance.destroy();
            
            mapContainer.innerHTML = '';
            heatmapChartContainer.innerHTML = '';
            insightsContainer.innerHTML = '';
            lineChartContainer.innerHTML = '';

            loadingIndicator.style.display = 'block';

            try {
                const response = await fetch(`/api.php?action=get_route_details&route_id=${routeId}`);
                if (!response.ok) throw new Error(`Erro HTTP! status: ${response.status}`);
                
                const result = await response.json();
                if (result.error) throw new Error(result.error);

                const { route, geometry, historic, heatmap } = result.data;

                modalTitle.textContent = route.name || 'Detalhes da Rota';

                // Renderizar componentes
                renderMap(mapContainer.id, geometry);
                renderHeatmap(heatmapChartContainer.id, heatmap);
                renderInsights(insightsContainer, route, geometry, heatmap);
                
                // Renderizar gráfico de linha se houver dados históricos
                if (historic && historic.length > 0) {
                    renderLineChart(lineChartContainer.id, historic);
                }

            } catch (err) {
                console.error('Erro:', err);
                insightsContainer.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
            } finally {
                loadingIndicator.style.display = 'none';
            }
        });
    });

    function renderMap(containerId, geometry) {
        const mapContainer = document.getElementById(containerId);
        if (!mapContainer) {
            console.error(`Container do mapa não encontrado: #${containerId}`);
            return;
        }

       // Verifica se há dados de geometria válidos
       if (!geometry || geometry.length === 0) {
           console.warn("Geometria vazia ou inválida, mapa não será renderizado.");
            mapContainer.innerHTML = '<p>Sem dados de geometria para exibir no mapa.</p>'; // Exibe mensagem no container
           return;
       }

       // A instância anterior já foi removida no handler do click

       // Cria uma nova instância do mapa Leaflet no container especificado
       mapInstance = L.map(containerId);

       // Define a visualização inicial (pode ser ajustado depois com fitBounds)
       // Usa o primeiro ponto da geometria. Leaflet espera [latitude, longitude].
       if (geometry[0]) {
           mapInstance.setView([geometry[0].y, geometry[0].x], 14);
       } else {
            mapContainer.innerHTML = '<p>Dados de geometria inválidos.</p>';
            return;
       }


       // Adiciona a camada de tiles do OpenStreetMap
       L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
           attribution: '© OpenStreetMap contributors'
       }).addTo(mapInstance);

       // Mapeia os pontos de geometria para o formato [latitude, longitude] que o Leaflet espera para polylines
       const latlngs = geometry.map(p => [p.y, p.x]);

       // Cria a polyline da rota e a adiciona ao mapa
       routeLayer = L.polyline(latlngs, { color: 'blue', weight: 5 }).addTo(mapInstance);

       // Ajusta o zoom do mapa para mostrar toda a rota
       if (latlngs.length > 1) { // Precisa de pelo menos 2 pontos para calcular bounds úteis
            mapInstance.fitBounds(routeLayer.getBounds());
       } else if (latlngs.length === 1) { // Apenas 1 ponto, centraliza nele
            mapInstance.setView(latlngs[0], 14); // Mantém o zoom inicial ou ajusta se necessário
       }
   }


   function renderHeatmap(containerId, heatmapData, route) {
    const heatmapChartContainer = document.getElementById(containerId);
     if (!heatmapChartContainer) {
         console.error(`Container do heatmap não encontrado: #${containerId}`);
         return;
     }

    // Verifica se há dados para o heatmap
    if (!heatmapData || heatmapData.length === 0) {
        heatmapChartContainer.innerHTML = '<p>Sem dados de heatmap para exibir.</p>';
        return;
    }

    // Calcula velocidades mínima e máxima nos dados do heatmap para definir a escala de cores
    const speeds = heatmapData.map(item => parseFloat(item.avg_speed)).filter(s => !isNaN(s)); // Filtra NaN
    if (speeds.length === 0) {
         heatmapChartContainer.innerHTML = '<p>Sem dados de velocidade válidos para o heatmap.</p>';
         return;
    }

    const minSpeed = Math.min(...speeds);
    const maxSpeed = Math.max(...speeds);

    // Ajusta o limite máximo da escala de cores se o range for muito pequeno para visualização
    // Isso ajuda a criar uma variação de cor mais perceptível
    const range = maxSpeed - minSpeed;
    const adjustedMax = range < 5 ? maxSpeed + 5 : maxSpeed; // Se a diferença for muito pequena, aumenta o máximo artificialmente
    const adjustedMin = range < 5 ? minSpeed - Math.max(0, 5 - range) : minSpeed; // Ajusta min para manter o range ajustado, mas não vai abaixo de 0

    // Prepara os dados para o Highcharts: array de arrays [hour, dayIndex, speed]
    // Assume item.day_of_week é 1-indexed (1=Domingo, ..., 7=Sábado)
    const categories = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb']; // Rótulos para o eixo Y (dias da semana)
    const data = heatmapData.map(item => {
        const day = parseInt(item.day_of_week);
        const hour = parseInt(item.hour);
        const speed = parseFloat(item.avg_speed);

         // Valida os dados do item
         if (isNaN(day) || isNaN(hour) || isNaN(speed) || day < 1 || day > 7 || hour < 0 || hour > 23) {
             console.warn("Dado de heatmap inválido:", item);
             return null; // Retorna null para itens inválidos
         }
        // Mapeia o dia (1-7) para o índice do array categories (0-6)
        const dayIndex = day - 1;
        return [hour, dayIndex, speed];
    }).filter(item => item !== null); // Filtra os itens nulos (inválidos)

     // Se não houver dados válidos após filtrar, exibe uma mensagem
     if (data.length === 0) {
          heatmapChartContainer.innerHTML = '<p>Sem dados válidos para criar o heatmap.</p>';
          return;
     }


    // Destrói a instância anterior do chart, se existir, antes de criar a nova
    if (heatmapChartInstance) {
        heatmapChartInstance.destroy();
    }

    // Cria o gráfico de heatmap usando Highcharts
    heatmapChartInstance = Highcharts.chart(containerId, {
        chart: {
            type: 'heatmap',
            plotBorderWidth: 1, // Borda entre os "pixels" do heatmap
            height: 250, // Altura fixa ou ajustável via CSS/configuração
             // width: '100%' // Largura pode ser controlada via CSS ou responsividade do Highcharts
        },
        title: {
            text: 'Velocidade Média Histórica por Hora e Dia da Semana', // Título do gráfico
            align: 'left', // Alinhamento do título
            style: {
                fontSize: '14px' // Tamanho da fonte do título
            }
        },
        xAxis: {
            categories: Array.from({ length: 24 }, (_, i) => `${i}h`), // Rótulos para o eixo X (horas 0h-23h)
            title: { text: 'Hora do Dia' }, // Título do eixo X
            labels: {
                step: 3 // Mostra rótulos a cada 3 horas para evitar sobreposição em displays estreitos
            },
             gridLineWidth: 0 // Remove linhas de grid no eixo X
        },
        yAxis: {
            categories: categories, // Rótulos para o eixo Y (dias da semana)
            title: { text: 'Dia da Semana' }, // Título do eixo Y
            reversed: true, // Inverte o eixo Y para ter o primeiro dia (Domingo) no topo
             gridLineWidth: 0 // Remove linhas de grid no eixo Y
        },
        colorAxis: {
            min: adjustedMin, // Velocidade mínima (ajustada) -> Mapeada para a primeira cor
            max: adjustedMax, // Velocidade máxima (ajustada) -> Mapeada para a última cor
            // Define as cores da escala. Vermelho para velocidade baixa, Verde para velocidade alta.
            stops: [
                [0, '#FF0000'], // 0% da escala (velocidade mínima) -> Vermelho
                [0.5, '#FFFF00'], // 50% da escala (velocidade intermediária) -> Amarelo
                [1, '#00FF00'] // 100% da escala (velocidade máxima) -> Verde
            ],
             labels: {
                 format: '{value:.1f} km/h' // Formato dos rótulos na legenda da cor
             }
        },
        legend: {
             enabled: true, // Habilita a legenda da escala de cores
             align: 'right', // Alinha a legenda à direita
             layout: 'vertical', // Layout vertical
             margin: 0, // Margem
             verticalAlign: 'top', // Alinha verticalmente no topo
             y: 25, // Posição vertical a partir do topo
             symbolHeight: 280 // Altura do gradiente na legenda
        },
        tooltip: {
            // Formata o tooltip ao passar o mouse sobre um ponto do heatmap
            formatter: function () {
                const day = categories[this.point.y]; // Obtém o nome do dia a partir do índice Y
                const hour = this.point.x; // Obtém a hora a partir do índice X
                const speed = this.point.value.toFixed(1); // Obtém o valor (velocidade) formatado
                return `<strong>${day}, ${hour}h:</strong> ${speed} km/h`; // Ex: "Seg, 14h: 45.2 km/h"
            }
        },
        series: [{
            name: 'Velocidade Média (km/h)', // Nome da série (aparece no tooltip)
            borderWidth: 1, // Largura da borda de cada ponto
            data: data, // Os dados [hora, diaIndex, velocidade]
            dataLabels: {
                enabled: true, // Habilita os rótulos nos pontos
                color: '#000', // Cor do texto dos rótulos (preto)
                format: '{point.value:.0f}', // Formato do texto (velocidade sem casas decimais)
                style: {
                    textOutline: 'none', // Remove o contorno de texto padrão para melhor legibilidade
                    fontSize: '10px' // Tamanho da fonte dos rótulos
                }
            }
        }],
        credits: { enabled: false } // Remove o link "Highcharts.com"
    });
}


    function renderInsights(containerElement, route, geometry, heatmapData) {
        // Se não houver um container válido, não faz nada
        if (!containerElement) {
            console.error("Container para insights não fornecido ou inválido.");
            return;
        }

        // Limpa o container antes de adicionar o novo conteúdo HTML
        containerElement.innerHTML = '';

        // Dados de referência
        const daysOfWeek = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        const periodos = {
            'Madrugada (0-5h)': [0, 1, 2, 3, 4, 5],
            'Manhã (6-11h)': [6, 7, 8, 9, 10, 11],
            'Tarde (12-17h)': [12, 13, 14, 15, 16, 17],
            'Noite (18-23h)': [18, 19, 20, 21, 22, 23]
        };
        // Array para mapear jam_level numérico para descrição
        const jamLevels = ['Fluido', 'Leve', 'Moderado', 'Intenso', 'Congestionado', 'Parado']; // Assumindo que jam_level 0-5 corresponde a esta ordem

        // --- Processamento e Cálculo de Insights ---

        // Calcula percentis de velocidade a partir dos dados do heatmap
        const speeds = heatmapData.map(item => parseFloat(item.avg_speed)).filter(s => !isNaN(s)).sort((a, b) => a - b); // Filtra NaN antes de ordenar
        const percentis = {
            p25: speeds.length > 0 ? speeds[Math.floor(speeds.length * 0.25)] : 0,
            p50: speeds.length > 0 ? speeds[Math.floor(speeds.length * 0.5)] : 0, // Mediana
            p75: speeds.length > 0 ? speeds[Math.floor(speeds.length * 0.75)] : 0
        };

        // Calcula médias e identifica melhores momentos (dia, hora, período)
        let totals = { days: {}, hours: {}, periods: {} };
        let counts = { days: {}, hours: {}, periods: {} };
        let maxValues = { day: -Infinity, hour: -Infinity, period: -Infinity };
        // Valores padrão caso não haja dados suficientes para calcular o "melhor momento"
        let best = { day: 'N/A', hour: 'N/A', period: 'N/A' };

        heatmapData.forEach(item => {
            // Assume item.day_of_week é 1-indexed (1=Domingo, ..., 7=Sábado) conforme padrão comum em DBs
            const day = parseInt(item.day_of_week);
            const hour = parseInt(item.hour);
            const speed = parseFloat(item.avg_speed);

            // Ignora pontos de dados onde dia, hora ou velocidade são inválidos
            if (isNaN(day) || isNaN(hour) || isNaN(speed) || day < 1 || day > 7 || hour < 0 || hour > 23) {
                return;
            }

            // Mapeia dia 1-7 para índice 0-6 (Domingo=0, ..., Sábado=6) para usar com o array daysOfWeek
            const dayIndex = day - 1;

            // Atualizar totais e contagens por dia (usando índice 0-6)
            totals.days[dayIndex] = (totals.days[dayIndex] || 0) + speed;
            counts.days[dayIndex] = (counts.days[dayIndex] || 0) + 1;

            // Atualizar totais e contagens por hora (usando hora 0-23)
            totals.hours[hour] = (totals.hours[hour] || 0) + speed;
            counts.hours[hour] = (counts.hours[hour] || 0) + 1;

            // Atualizar totais e contagens por período
            const periodEntry = Object.entries(periodos).find(([name, hours]) => hours.includes(hour));
            if (periodEntry) {
                const periodName = periodEntry[0]; // Ex: 'Manhã (6-11h)'
                totals.periods[periodName] = (totals.periods[periodName] || 0) + speed;
                counts.periods[periodName] = (counts.periods[periodName] || 0) + 1;
            }
        });

        // Calcular melhores momentos com base nas médias de velocidade
        // Melhor Dia da Semana:
        Object.entries(totals.days).forEach(([dayIndexStr, total]) => {
             const dayIndex = parseInt(dayIndexStr); // A chave é a string do índice ("0" a "6")
            if (counts.days[dayIndex] > 0) { // Certifica-se de que há dados para este dia
                const avg = total / counts.days[dayIndex];
                if (avg > maxValues.day) {
                    maxValues.day = avg;
                    // --- MODIFICAÇÃO: Inclui o nome do dia e a data atual formatada ---
                    const now = new Date();
                    const currentDay = now.getDate().toString().padStart(2, '0');
                    const currentMonth = (now.getMonth() + 1).toString().padStart(2, '0'); // getMonth() retorna 0-11
                    const currentYear = now.getFullYear();
                    const currentDateFormatted = `${currentDay}/${currentMonth}/${currentYear}`;

                    // Atribui o nome do dia da semana + data atual formatada
                    best.day = currentDateFormatted;
                    best.weekday = daysOfWeek[dayIndex]; // Ex: ""
                    // --- FIM MODIFICAÇÃO ---
                }
            }
        });

        // Melhor Hora do Dia:
        Object.entries(totals.hours).forEach(([hourStr, total]) => {
             const hour = parseInt(hourStr); // A chave é a string da hora ("0" a "23")
             if (counts.hours[hour] > 0) { // Certifica-se de que há dados para esta hora
                const avg = total / counts.hours[hour];
                if (avg > maxValues.hour) {
                    maxValues.hour = avg;
                    best.hour = `${hour.toString().padStart(2, '0')}h`; // Formata hora com zero à esquerda
                }
             }
        });

        // Melhor Período do Dia:
        Object.entries(totals.periods).forEach(([period, total]) => {
             if (counts.periods[period] > 0) { // Certifica-se de que há dados para este período
                const avg = total / counts.periods[period];
                if (avg > maxValues.period) {
                    maxValues.period = avg;
                    best.period = period; // O nome do período já inclui o range de horas (ex: 'Manhã (6-11h)')
                }
             }
        });

        // --- Formatação e Exibição dos Dados na UI ---

        // Converte velocidades para float e formata com 1 casa decimal
        const avgSpeed = parseFloat(route.avg_speed || 0).toFixed(1);
        const historicSpeed = parseFloat(route.historic_speed || 0).toFixed(1);

        // Calcula a variação percentual da velocidade
        const speedVariation = (parseFloat(historicSpeed) !== 0)
            ? ((parseFloat(avgSpeed) - parseFloat(historicSpeed)) / parseFloat(historicSpeed) * 100)
            : 0; // Evita divisão por zero
        const speedVariationFormatted = speedVariation.toFixed(1);
        const speedVariationArrow = speedVariation >= 0 ? '↑' : '↓'; // Seta para cima se maior ou igual, para baixo se menor

        // Mapeia o nível de congestionamento numérico para a descrição
        const jamLevel = parseInt(route.jam_level || 0);
        const jamLevelDescription = jamLevels[jamLevel] || 'Desconhecido'; // Usa descrição ou 'Desconhecido' se o nível for inválido

        // Conta o número de pontos na geometria que têm irregularidades mapeadas
        const irregularities = geometry.filter(p => p.irregularity_id != null).length;
        
        // Novo template HTML com organização em 2 colunas
        const insightsHTML = `
        <div class="col-md-6"> <!-- Coluna esquerda -->
            <div class="insight-item mb-3">
                <small class="text-muted d-block">Velocidade Atual</small>
                <h4 class="text-primary">${avgSpeed} km/h</h4>
            </div>
            
            <div class="insight-item mb-3">
                <small class="text-muted d-block">Velocidade Histórica</small>
                <h4 class="text-secondary">${historicSpeed} km/h</h4>
            </div>
            
            <div class="insight-item mb-3">
                <small class="text-muted d-block">Variação</small>
                <h4 class="${speedVariation >= 0 ? 'text-success' : 'text-danger'}">
                    ${speedVariationFormatted}% ${speedVariationArrow}
                </h4>
            </div>
        </div>
        
        <div class="col-md-6"> <!-- Coluna direita -->
            <div class="insight-item mb-3">
                <small class="text-muted d-block">Congestionamento</small>
                <div class="d-flex align-items-center">
                    <div class="progress flex-grow-1" style="height: 8px;">
                        <div class="progress-bar bg-${jamLevel > 3 ? 'danger' : 'warning'}" 
                            style="width: ${(jamLevel / 5) * 100}%">
                        </div>
                    </div>
                    <small class="badge badge-${jamLevel > 3 ? 'danger' : 'warning'} ml-2">
                        Nível ${jamLevel}
                    </small>
                </div>
            </div>
            
            <div class="insight-item mb-3">
                <small class="text-muted d-block">Melhor Horário</small>
                <h5 class="mb-0">${best.period}</h5>
            </div>

            <div class="insight-item mb-3">
                <small class="text-muted d-block">Melhor Dia da Semana</small>
                <small class="text-muted">${best.weekday}</small>
            </div>
            
            <div class="insight-item">
                <small class="text-muted d-block">Irregularidades</small>
                <h4 class="text-danger">${irregularities}</h4>
            </div>
        </div>
    `;

    containerElement.innerHTML = insightsHTML;
    }

    function renderLineChart(containerId, historicData) {
        const container = document.getElementById(containerId);
        if (!container || !historicData.length) return;

        // Processar dados
        const processedData = historicData
            .map(item => ({
                date: new Date(item.date),
                speed: parseFloat(item.avg_speed)
            }))
            .sort((a, b) => a.date - b.date);

        // Configurar eixos
        const categories = processedData.map(item => formatDate(item.date));
        const data = processedData.map(item => item.speed);

        // Criar gráfico
        lineChartInstance = Highcharts.chart(containerId, {
            chart: {
                type: 'line',
                height: 250
            },
            title: { text: 'Desempenho Histórico - Últimos 7 Dias' },
            xAxis: {
                categories,
                title: { text: 'Data' },
                crosshair: true
            },
            yAxis: {
                title: { text: 'Velocidade (km/h)' },
                min: Math.min(...data) - 5
            },
            series: [{
                name: 'Velocidade Média',
                data,
                color: '#4e73df',
                marker: { radius: 4 },
                tooltip: {
                    valueDecimals: 1,
                    valueSuffix: ' km/h'
                }
            }],
            plotOptions: {
                line: {
                    dataLabels: {
                        enabled: true,
                        format: '{y:.1f} km/h'
                    }
                }
            },
            credits: { enabled: false }
        });
    }
});