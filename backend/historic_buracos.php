<?php
require_once './config/configbd.php';
require_once './vendor/autoload.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id_parceiro']) || !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

$id_parceiro = $_SESSION['usuario_id_parceiro'];

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$loader = new FilesystemLoader(__DIR__ . '/../frontend');
$twig = new Environment($loader);

try {
    $pdo = Database::getConnection();

    // Consulta principal para buracos
    $baseSql = "SELECT * FROM alerts WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'";
    $baseParams = [];

    if ($id_parceiro != 99) {
        $baseSql .= " AND id_parceiro = :id_parceiro";
        $baseParams['id_parceiro'] = $id_parceiro;
    }

    // Aplicar filtros
    $filters = $_GET;
    if (!empty($filters['date'])) {
        $baseSql .= " AND DATE(FROM_UNIXTIME(pubMillis / 1000)) = :date";
        $baseParams['date'] = $filters['date'];
    }

    if (!empty($filters['period'])) {
        $baseSql .= " AND pubMillis >= :periodStart";
        $daysAgo = (int) $filters['period'];
        $baseParams['periodStart'] = (time() - ($daysAgo * 86400)) * 1000;
    }

    // Consulta para gráfico temporal
    $sqlTemporal = "SELECT 
                    DATE(FROM_UNIXTIME(pubMillis/1000)) as data, 
                    COUNT(*) as total 
                FROM alerts 
                WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'
                GROUP BY data 
                ORDER BY data DESC 
                LIMIT 30";

    // Consulta para rua mais afetada
    $sqlRuas = "SELECT 
                city, 
                street,
                COUNT(*) as total,
                CONCAT(city, ' - ', street) as local
            FROM alerts 
            WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'
            GROUP BY city, street
            ORDER BY total DESC 
            LIMIT 10";

    // Executar consultas
    $stmtTemporal = $pdo->prepare($sqlTemporal);
    $stmtTemporal->execute();
    $temporalData = $stmtTemporal->fetchAll(PDO::FETCH_ASSOC);

    $stmtRuas = $pdo->prepare($sqlRuas);
    $stmtRuas->execute();
    $ruasData = $stmtRuas->fetchAll(PDO::FETCH_ASSOC);

    // Calcular rua mais afetada
    $ruaMaisAfetada = [];
    if (!empty($ruasData)) {
        $ruaMaisAfetada = [
            'cidade' => $ruasData[0]['city'],
            'rua' => $ruasData[0]['street'],
            'total' => $ruasData[0]['total'],
            'local' => $ruasData[0]['local']
        ];
    }

    // Consulta principal
    $stmtBuracos = $pdo->prepare($baseSql);
    foreach ($baseParams as $key => $value) {
        $stmtBuracos->bindValue(":$key", $value);
    }
    $stmtBuracos->execute();
    $buracos = $stmtBuracos->fetchAll(PDO::FETCH_ASSOC);

    // Contagem total
    $countTotal = $pdo->query("SELECT COUNT(*) FROM alerts WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'")->fetchColumn();

    // Preparar dados para o template
    $data = [
        'buracos' => $buracos,
        'filters' => $filters,
        'counts' => [
            'total' => $countTotal,
            'filtered' => count($buracos),
            'confirmed' => 0,
            'not_resolved' => 0,
            'hidden' => 0
        ],
        'temporal' => $temporalData,
        'ruas' => $ruasData,
        'ruaMaisAfetada' => $ruaMaisAfetada
    ];

} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}

?>