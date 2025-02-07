<?php
// Inclui o arquivo de configuração do banco de dados e autoload do Composer
require_once './config/configbd.php'; // Conexão ao banco de dados
require_once './vendor/autoload.php'; // Autoloader do Composer

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Configura o carregador do Twig para buscar templates na pasta "frontend"
$loader = new FilesystemLoader(__DIR__ . '/../frontend'); // Caminho para a pasta frontend
$twig = new Environment($loader);

// Conexão com o banco de dados
$pdo = Database::getConnection();

session_start();

echo "<pre>";
var_dump($_SESSION); // Mostra os detalhes de cada variável
echo "</pre>";

// Função para buscar alertas de acidentes (ordenados pelos mais recentes)
function getAccidentAlerts(PDO $pdo) {
    $stmt = $pdo->prepare("
        SELECT * 
        FROM alerts 
        WHERE type = 'ACCIDENT' AND status = 1
        ORDER BY pubMillis DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar alertas de buracos (ordenados por confidence do maior para o menor)
function getHazardAlerts(PDO $pdo) {
    $stmt = $pdo->prepare("
        SELECT uuid, country, city, reportRating, confidence, type, subtype, street, location_x, location_y, pubMillis 
        FROM alerts 
        WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE' AND status = 1
        ORDER BY pubMillis DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar alertas de congestionamento (ordenados pela confiabilidade do maior para o menor)
function getJamAlerts(PDO $pdo) {
    $stmt = $pdo->prepare("
        SELECT uuid, country, city, reportRating, subtype, confidence, type, street, location_x, location_y, pubMillis, status, date_received
        FROM alerts 
        WHERE type = 'JAM' AND status = 1
        ORDER BY pubMillis DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar os alertas "outros" (não classificados como ACCIDENT ou HAZARD com o subtipo HAZARD_ON_ROAD_POT_HOLE)
function getOtherAlerts(PDO $pdo) {
    $stmt = $pdo->prepare("
        SELECT uuid, country, city, reportRating, confidence, type, subtype, street, location_x, location_y, pubMillis
        FROM alerts
        WHERE (type != 'ACCIDENT' AND (type != 'HAZARD' OR subtype != 'HAZARD_ON_ROAD_POT_HOLE') AND type != 'JAM')
        AND status = 1
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para obter a quantidade de alertas ativos no dia de hoje
function getActiveAlertsToday(PDO $pdo) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS activeToday 
        FROM alerts 
        WHERE status = 1 AND DATE(FROM_UNIXTIME(pubMillis / 1000)) = CURDATE()
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC)['activeToday'];
}

// Função para obter a quantidade total de alertas no mês (independente do status)
function getTotalAlertsThisMonth(PDO $pdo) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS totalMonth 
        FROM alerts 
        WHERE MONTH(FROM_UNIXTIME(pubMillis / 1000)) = MONTH(CURDATE()) 
        AND YEAR(FROM_UNIXTIME(pubMillis / 1000)) = YEAR(CURDATE())
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC)['totalMonth'];
}

// Buscar os alertas de acidentes, buracos e congestionamentos
$accidentAlerts = getAccidentAlerts($pdo);
$hazardAlerts = getHazardAlerts($pdo);
$jamAlerts = getJamAlerts($pdo);
// Buscar os alertas "outros"
$otherAlerts = getOtherAlerts($pdo);

// Buscar métricas adicionais
$activeAlertsToday = getActiveAlertsToday($pdo);
$totalAlertsThisMonth = getTotalAlertsThisMonth($pdo);

// Exemplo em backend/dashboard.php
$data = [
    'accidentAlerts' => getAccidentAlerts($pdo),
    'hazardAlerts' => getHazardAlerts($pdo),
    'jamAlerts' => getJamAlerts($pdo),
    'otherAlerts' => getOtherAlerts($pdo),
    'activeAlertsToday' => getActiveAlertsToday($pdo),
    'totalAlertsThisMonth' => getTotalAlertsThisMonth($pdo)
];

