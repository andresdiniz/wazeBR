<?php
require_once './config/configbd.php';
require_once './vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$loader = new FilesystemLoader(__DIR__ . '/../frontend');
$twig = new Environment($loader);

// Conexão com o banco de dados
$pdo = Database::getConnection();

// Define valores padrão de datas
$startDate = date('Y-m-01'); // Primeiro dia do mês atual
$endDate = date('Y-m-d'); // Data de hoje

session_start();

$id_parceiro = $_SESSION['usuario_id_parceiro'] ?? 99; // Pega o valor ou usa um padrão (99)

// Verifica se os filtros foram enviados
if (!empty($_GET['start_date'])) {
    $startDate = $_GET['start_date'];
}
if (!empty($_GET['end_date'])) {
    $endDate = $_GET['end_date'];
}

// Busca os alertas com base no filtro de datas
function getFilteredAccidentAlerts(PDO $pdo, $startDate, $endDate) {
    $stmt = $pdo->prepare("
        SELECT * 
        FROM alerts 
        WHERE type = 'ACCIDENT' 
          AND date_received BETWEEN :start_date AND :end_date
        ORDER BY pubMillis DESC
    ");
    $stmt->bindValue(':start_date', $startDate);
    $stmt->bindValue(':end_date', $endDate);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$data = [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'accidentAlerts' => getFilteredAccidentAlerts($pdo, $startDate, $endDate),
];
