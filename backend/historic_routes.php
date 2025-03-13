<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once './config/configbd.php';
require_once './vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Configurar o Twig
$loader = new FilesystemLoader(__DIR__ . '/../frontend'); // Caminho para a pasta frontend
$twig = new Environment($loader);

// Conexão com o banco de dados
$pdo = Database::getConnection();

session_start();

$id_parceiro = $_SESSION['usuario_id_parceiro']; // Pega o valor do id_parceiro do usuario

// Modificar a consulta dependendo do id_parceiro
if ($id_parceiro == 99) {
    // Se o id_parceiro for 99, retorna todas as rotas
    $sqlRoutes = "SELECT id, name FROM routes ORDER BY name";
} else {
    // Se o id_parceiro não for 99, retorna apenas as rotas do id_parceiro
    $sqlRoutes = "SELECT id, name FROM routes WHERE id_parceiro = :id_parceiro ORDER BY name";
}

$stmtRoutes = $pdo->prepare($sqlRoutes);

// Se não for o id_parceiro 99, precisamos passar o id_parceiro na execução
if ($id_parceiro != 99) {
    $stmtRoutes->execute([':id_parceiro' => $id_parceiro]);
} else {
    $stmtRoutes->execute();
}

$routes = $stmtRoutes->fetchAll(PDO::FETCH_ASSOC);

// Inicializar variáveis para busca
$routeId = $_GET['route_id'] ?? null;
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days')); // Padrão: últimos 7 dias (formato Y-m-d)
$endDate = $_GET['end_date'] ?? date('Y-m-d', strtotime('+1 day')); // Padrão: amanhã (formato Y-m-d)

$data = [];

// Verificar se a data está sendo fornecida
if ($routeId) {
    // Garantir que as datas passadas estão no formato correto (Y-m-d)
    $startDateFormatted = $startDate;  // Já está no formato correto
    $endDateFormatted = $endDate;      // Já está no formato correto

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

?>
