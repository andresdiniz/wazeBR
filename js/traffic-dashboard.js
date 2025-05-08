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
    
    // Cores para os gráficos
    const colors = {
        primary: 'rgba(13, 110, 253, 0.7)',
        primaryBorder: 'rgba(13, 110, 253, 1)',
        secondary: 'rgba(108, 117, 125, 0.7)',
        secondaryBorder: 'rgba(108, 117, 125, 1)',
        success: 'rgba(25, 135, 84, 0.7)',
        successBorder: 'rgba(25, 135, 84, 1)',
        danger: 'rgba(220, 53, 69, 0.7)',
        dangerBorder: 'rgba(220, 53, 69, 1)',
        warning: 'rgba(255, 193, 7, 0.7)',
        warningBorder: 'rgba(255, 193, 7, 1)',
        info: 'rgba(13, 202, 240, 0.7)',
        infoBorder: 'rgba(13, 202, 240, 1)'
    };
    
    // Paleta de cores para múltiplas séries
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
    
    const colorPaletteBorders = colorPalette.map(color => color.replace('0.7', '1'));
    
    // Inicializar gráficos
    
    // 1. Gráfico de distribuição por hora
    if (document.getElementById('hourlyChart')) {
        const hourlyData = dashboardData.hourly_distribution;
        const hours = hourlyData.map(item => `${item.hour_of_day}:00`);
        const jamCounts = hourlyData.map(item => item.jam_count);
        const avgDelays = hourlyData.map(item => item.avg_delay / 60); // Convertendo para minutos
        
        new Chart(document.getElementById('hourlyChart'), {
            type: 'bar',
            data: {
                labels: hours,
                datasets: [
                    {
                        label: 'Congestionamentos',
                        data: jamCounts,
                        backgroundColor: colors.primary,
                        borderColor: colors.primaryBorder,
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Atraso Médio (min)',
                        data: avgDelays,
                        type: 'line',
                        borderColor: colors.danger,
                        backgroundColor: colors.danger,
                        borderWidth: 2,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                ...chartOptions,
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Número de Congestionamentos'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Atraso Médio (min)'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
    }
    
    // 2. Gráfico de distribuição por dia da semana
    if (document.getElementById('weekdayChart')) {
        // Ordene os dias da semana corretamente
        const weekdayData = [...dashboardData.weekly_pattern]; 
        
        // Mapear nomes em português se necessário
        const dayNames = {
            'Sunday': 'Domingo',
            'Monday': 'Segunda',
            'Tuesday': 'Terça',
            'Wednesday': 'Quarta',
            'Thursday': 'Quinta',
            'Friday': 'Sexta',
            'Saturday': 'Sábado'
        };
        
        // Ordenar por day_num para garantir a ordem correta
        weekdayData.sort((a, b) => a.day_num - b.day_num);
        
        const days = weekdayData.map(item => dayNames[item.day_of_week] || item.day_of_week);
        const dayJamCounts = weekdayData.map(item => item.jam_count);
        const dayAvgDelays = weekdayData.map(item => item.avg_delay / 60); // Convertendo para minutos
        
        new Chart(document.getElementById('weekdayChart'), {
            type: 'bar',
            data: {
                labels: days,
                datasets: [
                    {
                        label: 'Congestionamentos',
                        data: dayJamCounts,
                        backgroundColor: colors.success,
                        borderColor: colors.successBorder,
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Atraso Médio (min)',
                        data: dayAvgDelays,
                        type: 'line',
                        borderColor: colors.warning,
                        backgroundColor: colors.warning,
                        borderWidth: 2,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                ...chartOptions,
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Número de Congestionamentos'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Atraso Médio (min)'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
    }
    
    // 3. Gráfico de nível de congestionamento
    if (document.getElementById('levelChart')) {
        const levelData = dashboardData.congestion_by_level;
        const levels = levelData.map(item => `Nível ${item.level}`);
        const levelCounts = levelData.map(item => item.jam_count);
        const levelAvgDelays = levelData.map(item => item.avg_delay / 60); // Convertendo para minutos
        
        new Chart(document.getElementById('levelChart'), {
            type: 'bar',
            data: {
                labels: levels,
                datasets: [
                    {
                        label: 'Congestionamentos',
                        data: levelCounts,
                        backgroundColor: colors.info,
                        borderColor: colors.infoBorder,
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Atraso Médio (min)',
                        data: levelAvgDelays,
                        type: 'line',
                        borderColor: colors.danger,
                        backgroundColor: colors.danger,
                        borderWidth: 2,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                ...chartOptions,
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Número de Congestionamentos'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Atraso Médio (min)'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
    }
    
    // 4. Gráfico de distribuição de atrasos
    if (document.getElementById('delayDistChart')) {
        const delayDistData = dashboardData.delay_distribution;
        const delayRanges = delayDistData.map(item => item.delay_range);
        const delayCounts = delayDistData.map(item => item.jam_count);
        
        new Chart(document.getElementById('delayDistChart'), {
            type: 'pie',
            data: {
                labels: delayRanges,
                datasets: [{
                    data: delayCounts,
                    backgroundColor: colorPalette,
                    borderColor: colorPaletteBorders,
                    borderWidth: 1
                }]
            },
            options: {
                ...chartOptions,
                plugins: {
                    legend: {
                        position: 'right',
                    }
                }
            }
        });
    }
    
    // 5. Gráfico mensal
    if (document.getElementById('monthlyChart')) {
        const monthlyData = dashboardData.monthly_trend;
        const months = monthlyData.map(item => item.month);
        const monthJamCounts = monthlyData.map(item => item.jam_count);
        const monthAvgDelays = monthlyData.map(item => item.avg_delay / 60); // Convertendo para minutos
        
        new Chart(document.getElementById('monthlyChart'), {
            type: 'line',
            data: {
                labels: months,
                datasets: [
                    {
                        label: 'Congestionamentos',
                        data: monthJamCounts,
                        backgroundColor: colors.primary,
                        borderColor: colors.primaryBorder,
                        borderWidth: 2,
                        yAxisID: 'y',
                        tension: 0.1
                    },
                    {
                        label: 'Atraso Médio (min)',
                        data: monthAvgDelays,
                        backgroundColor: colors.danger,
                        borderColor: colors.dangerBorder,
                        borderWidth: 2,
                        yAxisID: 'y1',
                        tension: 0.1
                    }
                ]
            },
            options: {
                ...chartOptions,
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Número de Congestionamentos'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Atraso Médio (min)'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
    }
    
    // 6. Gráfico de cidades
    if (document.getElementById('cityChart')) {
        const cityData = dashboardData.city_analysis.slice(0, 10); // Top 10 cidades
        const cities = cityData.map(item => item.city);
        const cityCounts = cityData.map(item => item.jam_count);
        
        new Chart(document.getElementById('cityChart'), {
            type: 'bar',
            data: {
                labels: cities,
                datasets: [{
                    label: 'Congestionamentos',
                    data: cityCounts,
                    backgroundColor: colors.success,
                    borderColor: colors.successBorder,
                    borderWidth: 1
                }]
            },
            options: {
                ...chartOptions,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    }
    
    // 7. Gráfico de tipo de via
    if (document.getElementById('roadTypeChart')) {
        const roadTypeData = dashboardData.roadtype_analysis;
        
        // Mapeamento de roadType para descrições mais amigáveis
        const roadTypeNames = {
            1: 'Rua',
            2: 'Avenida',
            3: 'Rodovia',
            4: 'Rua Principal',
            5: 'Freeway',
            6: 'Via Expressa',
            7: 'Estrada de Terra',
            8: 'Outro'
        };
        
        const roadTypes = roadTypeData.map(item => roadTypeNames[item.roadType] || `Tipo ${item.roadType}`);
        const roadTypeCounts = roadTypeData.map(item => item.jam_count);
        
        new Chart(document.getElementById('roadTypeChart'), {
            type: 'doughnut',
            data: {
                labels: roadTypes,
                datasets: [{
                    data: roadTypeCounts,
                    backgroundColor: colorPalette,
                    borderColor: colorPaletteBorders,
                    borderWidth: 1
                }]
            },
            options: chartOptions
        });
    }
    
    // 8. Gráfico de relação entre comprimento e atraso
    if (document.getElementById('lengthVsDelayChart')) {
        const lengthData = dashboardData.length_vs_delay;
        const lengthRanges = lengthData.map(item => item.length_range);
        const lengthJamCounts = lengthData.map(item => item.jam_count);
        const lengthAvgDelays = lengthData.map(item => item.avg_delay / 60); // Convertendo para minutos
        
        new Chart(document.getElementById('lengthVsDelayChart'), {
            type: 'bar',
            data: {
                labels: lengthRanges,
                datasets: [
                    {
                        label: 'Congestionamentos',
                        data: lengthJamCounts,
                        backgroundColor: colors.info,
                        borderColor: colors.infoBorder,
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Atraso Médio (min)',
                        data: lengthAvgDelays,
                        type: 'line',
                        borderColor: colors.danger,
                        backgroundColor: colors.danger,
                        borderWidth: 2,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                ...chartOptions,
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Número de Congestionamentos'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Atraso Médio (min)'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
    }