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

// Iniciar sessão
session_start();

$id_parceiro = $_SESSION['usuario_id_parceiro']; // Pega o valor do id_parceiro do usuario

// Definir SQL para pegar as rotas, dependendo do id_parceiro
$sqlRoutes = ($id_parceiro == 99)
    ? "SELECT id, name FROM routes ORDER BY name"
    : "SELECT id, name FROM routes WHERE id_parceiro = :id_parceiro ORDER BY name";

$stmtRoutes = $pdo->prepare($sqlRoutes);

// Executar a consulta de rotas
$stmtRoutes->execute($id_parceiro != 99 ? [':id_parceiro' => $id_parceiro] : []);
$routes = $stmtRoutes->fetchAll(PDO::FETCH_ASSOC);

// Inicializar variáveis para busca
$routeId = $_GET['route_id'] ?? null;
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days')); // Padrão: últimos 7 dias (formato Y-m-d)
$endDate = $_GET['end_date'] ?? date('Y-m-d', strtotime('+1 day')); // Padrão: amanhã (formato Y-m-d)

$data = [];
$insights = '';
$expectedSpeed = null;

if ($routeId) {
    // Garantir que as datas estão no formato correto (Y-m-d)
    $startDateFormatted = $startDate; // Já está no formato correto
    $endDateFormatted = $endDate; // Já está no formato correto

    // Consulta histórica com as datas no formato correto
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

    // Armazenar os resultados da consulta
    $data = $stmtHistoric->fetchAll(PDO::FETCH_ASSOC);

    // Formatar dados corretamente
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
