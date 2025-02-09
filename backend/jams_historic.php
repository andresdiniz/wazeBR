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

// Função para buscar alertas de congestionamento (Jam) no banco de dados
function getFilteredJamAlerts(PDO $pdo, $startDate, $endDate) {
    $stmt = $pdo->prepare("SELECT * FROM alerts WHERE type = 'Jam' AND date_received BETWEEN :start_date AND :end_date ORDER BY pubMillis DESC");
    $stmt->bindValue(':start_date', $startDate);
    $stmt->bindValue(':end_date', $endDate);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtém os alertas de congestionamento
$jams = getFilteredJamAlerts($pdo, $startDate, $endDate);

if (!$jams) {
    echo json_encode(['error' => 'Nenhum congestionamento encontrado para as datas selecionadas.']);
    exit;
}

$labels = [];
$data_counts = [];
foreach ($jams as $jam) {
    $date = substr($jam['date_received'], 0, 10);
    if (!in_array($date, $labels)) {
        $labels[] = $date;
        $data_counts[] = 1;
    } else {
        $index = array_search($date, $labels);
        $data_counts[$index]++;
    }
}

echo json_encode([
    'jams' => $jams,
    'labels' => $labels,
    'data_counts' => $data_counts,
]);
