<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once './config/configbd.php';
require_once './vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Configurar o Twig
$loader = new FilesystemLoader(__DIR__ . '/../frontend'); // Caminho para a pasta frontend
$twig = new Environment($loader);

// Conexão com o banco de dados
$pdo = Database::getConnection();

// Buscar todas as rotas disponíveis
$sqlRoutes = "SELECT id, name FROM routes ORDER BY name";
$stmtRoutes = $pdo->prepare($sqlRoutes);
$stmtRoutes->execute();
$routes = $stmtRoutes->fetchAll(PDO::FETCH_ASSOC);

// Inicializar variáveis para busca
$routeId = $_GET['route_id'] ?? null;
$startDate = $_GET['start_date'] ?? date('d/m/Y', strtotime('-7 days')); // Padrão: últimos 7 dias
$endDate = $_GET['end_date'] ?? date('d/m/Y'); // Padrão: hoje

$data = [];

if ($routeId) {
    // Converter datas para o formato do banco (YYYY-MM-DD)
    $startDateFormatted = DateTime::createFromFormat('d/m/Y', $startDate)->format('Y-m-d');
    $endDateFormatted = DateTime::createFromFormat('d/m/Y', $endDate)->format('Y-m-d');

    // Buscar dados históricos filtrando por route_id e data
    $sqlHistoric = "SELECT data, velocidade, tempo 
                    FROM historic_routes 
                    WHERE route_id = :route_id
                    AND data BETWEEN :start_date AND :end_date
                    ORDER BY data";

    $stmtHistoric = $pdo->prepare($sqlHistoric);
    $stmtHistoric->execute([
        ':route_id' => $routeId,
        ':start_date' => $startDateFormatted,
        ':end_date' => $endDateFormatted
    ]);

    $data = $stmtHistoric->fetchAll(PDO::FETCH_ASSOC);

    // Formatar os dados corretamente
    foreach ($data as &$item) {
        $item['velocidade'] = (float)$item['velocidade'];
        $item['tempo'] = (float)$item['tempo'];
        $item['data'] = date('Y-m-d H:i:s', strtotime($item['data']));
    }
}

$data = [
    'routes' => $routes,
    'dados' => ['historic_routes' => $data],
    'selected_route' => $routeId,
    'start_date' => $startDate,
    'end_date' => $endDate
];
/*
// Passa os dados para o Twig
echo $twig->render('historic_routes.twig', [
    'routes' => $routes,
    'dados' => ['historic_routes' => $data],
    'selected_route' => $routeId,
    'start_date' => $startDate,
    'end_date' => $endDate
]);*/

