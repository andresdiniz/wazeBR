<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once './config/configbd.php';
require_once './vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Configurar o Twig
$loader = new FilesystemLoader(__DIR__ . '/../frontend');
$twig = new Environment($loader, [
    'cache' => __DIR__ . '/cache', // Ativa o cache do Twig para melhor desempenho
    'auto_reload' => true         // Recarrega templates alterados (desativar em produção)
]);

// Conexão com o banco de dados - Usar somente quando necessário
session_start();
$id_parceiro = $_SESSION['usuario_id_parceiro'] ?? 0;

// Parâmetros do formulário
$route1 = $_GET['route1_id'] ?? null;
$route2 = $_GET['route2_id'] ?? null;
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Inicializar dados
$dados = [];
$route1_name = '';
$route2_name = '';
$routes = [];

// Função para obter conexão PDO apenas quando necessário
function getPDO() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = Database::getConnection();
    }
    return $pdo;
}

// Buscar rotas disponíveis - Executada apenas uma vez
function getRoutes($id_parceiro) {
    $pdo = getPDO();
    
    // Usar índices na consulta SQL
    $sqlRoutes = ($id_parceiro == 99)
        ? "SELECT id, name FROM routes ORDER BY name"
        : "SELECT id, name FROM routes WHERE id_parceiro = :id_parceiro ORDER BY name";

    $stmtRoutes = $pdo->prepare($sqlRoutes);
    $stmtRoutes->execute($id_parceiro != 99 ? [':id_parceiro' => $id_parceiro] : []);
    return $stmtRoutes->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar rotas apenas se necessário (otimização)
$routes = getRoutes($id_parceiro);

if ($route1 && $route2) {
    $pdo = getPDO();
    
    // Buscar nomes das rotas (em uma única consulta para otimização)
    $stmt = $pdo->prepare("SELECT id, name FROM routes WHERE id IN (?, ?)");
    $stmt->execute([$route1, $route2]);
    $routeNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $route1_name = $routeNames[$route1] ?? '';
    $route2_name = $routeNames[$route2] ?? '';

    // Função para buscar dados de uma rota - Com otimização da consulta
    $fetchRouteData = function($routeId) use ($pdo, $startDate, $endDate) {
        // Adicionado LIMIT para evitar sobrecarga com muitos dados
        // Usar índices em route_id e data
        $stmt = $pdo->prepare("SELECT data, velocidade, tempo 
                             FROM historic_routes 
                             WHERE route_id = ? 
                             AND data BETWEEN ? AND ?
                             ORDER BY data 
                             LIMIT 5000"); // Limitar para evitar sobrecarga
        $stmt->execute([$routeId, $startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    // Dados das rotas - Executar consultas em paralelo se possível
    $rota1 = $fetchRouteData($route1);
    $rota2 = $fetchRouteData($route2);

    // Otimizar o processamento de dados para grandes conjuntos
    $mergedData = [];
    $rota1_map = [];
    $rota2_map = [];
    $allDates = [];
    
    // Criar mapas para acesso O(1) em vez de busca O(n)
    foreach ($rota1 as $item) {
        $rota1_map[$item['data']] = $item;
        $allDates[$item['data']] = true;
    }
    
    foreach ($rota2 as $item) {
        $rota2_map[$item['data']] = $item;
        $allDates[$item['data']] = true;
    }
    
    // Ordenar datas
    $labels = array_keys($allDates);
    sort($labels);
    
    // Agregar dados por dia para reduzir pontos nos gráficos (opcional)
    $aggregateByDay = false;
    
    if ($aggregateByDay && count($labels) > 100) {
        $dailyData = [];
        $dailyLabels = [];
        
        foreach ($labels as $datetime) {
            $day = substr($datetime, 0, 10);
            
            if (!isset($dailyData[$day])) {
                $dailyData[$day] = [
                    'count1' => 0, 'velocidade1_sum' => 0, 'tempo1_sum' => 0,
                    'count2' => 0, 'velocidade2_sum' => 0, 'tempo2_sum' => 0
                ];
                $dailyLabels[] = $day;
            }
            
            if (isset($rota1_map[$datetime])) {
                $dailyData[$day]['count1']++;
                $dailyData[$day]['velocidade1_sum'] += (float)$rota1_map[$datetime]['velocidade'];
                $dailyData[$day]['tempo1_sum'] += (float)$rota1_map[$datetime]['tempo'];
            }
            
            if (isset($rota2_map[$datetime])) {
                $dailyData[$day]['count2']++;
                $dailyData[$day]['velocidade2_sum'] += (float)$rota2_map[$datetime]['velocidade'];
                $dailyData[$day]['tempo2_sum'] += (float)$rota2_map[$datetime]['tempo']; 
            }
        }
        
        // Reconstruir dados agregados
        $mergedData = [];
        $processedLabels = [];
        
        foreach ($dailyLabels as $day) {
            $d = $dailyData[$day];
            $processedLabels[] = $day;
            
            $mergedData[] = [
                'data' => $day,
                'velocidade1' => $d['count1'] ? $d['velocidade1_sum'] / $d['count1'] : null,
                'tempo1' => $d['count1'] ? $d['tempo1_sum'] / $d['count1'] : null,
                'velocidade2' => $d['count2'] ? $d['velocidade2_sum'] / $d['count2'] : null,
                'tempo2' => $d['count2'] ? $d['tempo2_sum'] / $d['count2'] : null
            ];
        }
        
        $labels = $processedLabels;
    } else {
        // Processar dados normalmente
        foreach ($labels as $date) {
            $mergedData[] = [
                'data' => $date,
                'velocidade1' => isset($rota1_map[$date]) ? (float)$rota1_map[$date]['velocidade'] : null,
                'tempo1' => isset($rota1_map[$date]) ? (float)$rota1_map[$date]['tempo'] : null,
                'velocidade2' => isset($rota2_map[$date]) ? (float)$rota2_map[$date]['velocidade'] : null,
                'tempo2' => isset($rota2_map[$date]) ? (float)$rota2_map[$date]['tempo'] : null
            ];
        }
    }

    // Calcular médias de forma otimizada
    $calculateAverages = function($data) {
        $velocidades = array_filter(array_column($data, 'velocidade'));
        $tempos = array_filter(array_column($data, 'tempo'));
        
        return [
            'velocidade' => empty($velocidades) ? 0 : array_sum($velocidades) / count($velocidades),
            'tempo' => empty($tempos) ? 0 : array_sum($tempos) / count($tempos)
        ];
    };

    // Preencher valores para gráficos
    $rota1_velocidade = [];
    $rota1_tempo = [];
    $rota2_velocidade = [];
    $rota2_tempo = [];
    
    foreach ($mergedData as $item) {
        $rota1_velocidade[] = $item['velocidade1'];
        $rota1_tempo[] = $item['tempo1'];
        $rota2_velocidade[] = $item['velocidade2'];
        $rota2_tempo[] = $item['tempo2'];
    }

    $dados = [
        'rota1' => $rota1,
        'rota2' => $rota2,
        'media1' => $calculateAverages($rota1),
        'media2' => $calculateAverages($rota2),
        'labels' => $labels,
        'merged_data' => $mergedData,
        'rota1_values' => [
            'velocidade' => $rota1_velocidade,
            'tempo' => $rota1_tempo
        ],
        'rota2_values' => [
            'velocidade' => $rota2_velocidade,
            'tempo' => $rota2_tempo
        ]
    ];
    
    // Calcular estatísticas adicionais
    $dados['estatisticas'] = [
        'rota1' => [
            'total_registros' => count($rota1),
            'diferenca_percentual' => [
                'velocidade' => isset($dados['media2']['velocidade']) && $dados['media2']['velocidade'] > 0 
                    ? (($dados['media1']['velocidade'] / $dados['media2']['velocidade']) - 1) * 100 
                    : 0,
                'tempo' => isset($dados['media2']['tempo']) && $dados['media2']['tempo'] > 0 
                    ? (($dados['media1']['tempo'] / $dados['media2']['tempo']) - 1) * 100 
                    : 0
            ]
        ],
        'rota2' => [
            'total_registros' => count($rota2)
        ]
    ];
}

// Dados para template
$data = [
    'routes' => $routes,
    'dados' => !empty($dados) ? $dados : new stdClass(),
    'route1' => $route1,
    'route2' => $route2,
    'route1_name' => $route1_name,
    'route2_name' => $route2_name,
    'start_date' => $startDate,
    'end_date' => $endDate
];

// Renderizar template
echo $twig->render('comparacao_rotas.html.twig', $data);