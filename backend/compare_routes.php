<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once './config/configbd.php';
require_once './vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Configurar o Twig
$loader = new FilesystemLoader(__DIR__ . '/../frontend');
$twig = new Environment($loader);

// Conexão com o banco de dados
$pdo = Database::getConnection();

session_start();
$id_parceiro = $_SESSION['usuario_id_parceiro'];

// Buscar rotas disponíveis
$sqlRoutes = ($id_parceiro == 99)
    ? "SELECT id, name FROM routes ORDER BY name"
    : "SELECT id, name FROM routes WHERE id_parceiro = :id_parceiro ORDER BY name";

$stmtRoutes = $pdo->prepare($sqlRoutes);
$stmtRoutes->execute($id_parceiro != 99 ? [':id_parceiro' => $id_parceiro] : []);
$routes = $stmtRoutes->fetchAll(PDO::FETCH_ASSOC);

// Parâmetros do formulário
$route1 = $_GET['route1_id'] ?? null;
$route2 = $_GET['route2_id'] ?? null;
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$dados = [];
$route1_name = '';
$route2_name = '';

if ($route1 && $route2) {
    // Buscar nomes das rotas
    $stmt = $pdo->prepare("SELECT name FROM routes WHERE id = ?");
    $stmt->execute([$route1]);
    $route1_name = $stmt->fetchColumn();
    
    $stmt->execute([$route2]);
    $route2_name = $stmt->fetchColumn();

    // Função para buscar dados de uma rota
    $fetchRouteData = function($routeId) use ($pdo, $startDate, $endDate) {
        $stmt = $pdo->prepare("SELECT data, velocidade, tempo 
                             FROM historic_routes 
                             WHERE route_id = ? 
                             AND data BETWEEN ? AND ?
                             ORDER BY data");
        $stmt->execute([$routeId, $startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    // Dados das rotas
    $rota1 = $fetchRouteData($route1);
    $rota2 = $fetchRouteData($route2);

    // Processar dados para gráficos
    $labels = [];
    $mergedData = [];
    
    // Combinar datas únicas
    $allDates = array_unique(array_merge(
        array_column($rota1, 'data'),
        array_column($rota2, 'data')
    ));
    sort($allDates);

    // Estruturar dados combinados
    foreach ($allDates as $date) {
        $item1 = array_filter($rota1, fn($item) => $item['data'] === $date);
        $item2 = array_filter($rota2, fn($item) => $item['data'] === $date);
        
        $mergedData[] = [
            'data' => $date,
            'velocidade1' => $item1 ? (float)reset($item1)['velocidade'] : null,
            'tempo1' => $item1 ? (float)reset($item1)['tempo'] : null,
            'velocidade2' => $item2 ? (float)reset($item2)['velocidade'] : null,
            'tempo2' => $item2 ? (float)reset($item2)['tempo'] : null
        ];
        
        $labels[] = $date;
    }

    // Calcular médias
    $calculateAverages = function($data) {
        $velocidades = array_column($data, 'velocidade');
        $tempos = array_column($data, 'tempo');
        
        return [
            'velocidade' => count($velocidades) ? array_sum($velocidades) / count($velocidades) : 0,
            'tempo' => count($tempos) ? array_sum($tempos) / count($tempos) : 0
        ];
    };

    $dados = [
        'rota1' => $rota1,
        'rota2' => $rota2,
        'media1' => $calculateAverages($rota1),
        'media2' => $calculateAverages($rota2),
        'labels' => $labels,
        'merged_data' => $mergedData,
        'rota1_values' => [
            'velocidade' => array_column($mergedData, 'velocidade1'),
            'tempo' => array_column($mergedData, 'tempo1')
        ],
        'rota2_values' => [
            'velocidade' => array_column($mergedData, 'velocidade2'),
            'tempo' => array_column($mergedData, 'tempo2')
        ]
    ];
}

$data = [
    'routes' => $routes,
    'dados' => $dados,
    'route1' => $route1,
    'route2' => $route2,
    'route1_name' => $route1_name,
    'route2_name' => $route2_name,
    'start_date' => $startDate,
    'end_date' => $endDate

];
?>