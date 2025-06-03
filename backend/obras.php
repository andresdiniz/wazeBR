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

    // Função para adicionar filtro de parceiro às queries
    $addPartnerFilter = function ($sql) use ($id_parceiro) {
        if ($id_parceiro != 99) {
            $sql .= " AND id_parceiro = :id_parceiro";
        }
        return $sql;
    };

    // Consulta principal para buracos
    $baseSql = "SELECT * FROM alerts WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_CONSTRUCTION'";
    $baseSql = $addPartnerFilter($baseSql);
    $baseParams = [];

    // Aplicar filtros adicionais
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

    // Query para dados temporais
    $sqlTemporal = "SELECT 
                    DATE(FROM_UNIXTIME(pubMillis/1000)) as data, 
                    COUNT(*) as total 
                FROM alerts 
                WHERE type = 'HAZARD' 
                AND subtype = 'HAZARD_ON_ROAD_CONSTRUCTION'";
    $sqlTemporal = $addPartnerFilter($sqlTemporal);
    $sqlTemporal .= " GROUP BY data ORDER BY data DESC LIMIT 30";

    // Query para cidades
    $sqlCidades = "SELECT 
                    city, 
                    COUNT(*) as total 
                FROM alerts 
                WHERE type = 'HAZARD' 
                AND subtype = 'HAZARD_ON_ROAD_CONSTRUCTION'";
    $sqlCidades = $addPartnerFilter($sqlCidades);
    $sqlCidades .= " GROUP BY city ORDER BY total DESC LIMIT 10";

    // Query para ruas
    $sqlRuas = "SELECT 
                CONCAT(city, ' - ', street) as local,
                COUNT(*) as total 
            FROM alerts 
            WHERE type = 'HAZARD' 
            AND subtype = 'HAZARD_ON_ROAD_CONSTRUCTION'";
    $sqlRuas = $addPartnerFilter($sqlRuas);
    $sqlRuas .= " GROUP BY local ORDER BY total DESC LIMIT 10";

    // Query para contagem total
    $sqlCountTotal = "SELECT COUNT(*) FROM alerts 
                     WHERE type = 'HAZARD' 
                     AND subtype = 'HAZARD_ON_ROAD_CONSTRUCTION'";
    $sqlCountTotal = $addPartnerFilter("SELECT COUNT(*) FROM alerts WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_CONSTRUCTION'");
    $sqlCountResolved = "SELECT COUNT(*) FROM alerts WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_CONSTRUCTION' AND confirmado = 'RESOLVED'";
    $sqlCountResolved = $addPartnerFilter($sqlCountResolved);

    // Preparar e executar todas as queries
    $executeQuery = function ($sql, $params = []) use ($pdo, $id_parceiro) {
        $stmt = $pdo->prepare($sql);
        if ($id_parceiro != 99) {
            $stmt->bindValue(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        }
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->execute();
        return $stmt;
    };

    // Executar queries
    $temporalData = $executeQuery($sqlTemporal)->fetchAll(PDO::FETCH_ASSOC);
    $cidadesData = $executeQuery($sqlCidades)->fetchAll(PDO::FETCH_ASSOC);
    $ruasData = $executeQuery($sqlRuas)->fetchAll(PDO::FETCH_ASSOC);
    $countTotal = $executeQuery($sqlCountTotal)->fetchColumn();
    $countResolved = $executeQuery($sqlCountResolved)->fetchColumn();

    // Query principal com filtros
    $stmtBuracos = $executeQuery($baseSql, $baseParams);
    $buracos = $stmtBuracos->fetchAll(PDO::FETCH_ASSOC);

    // Preparar dados para o template
    $data = [
        'obras' => $buracos,
        'filters' => $filters,
        'counts' => [
            'total' => $countTotal,
            'filtered' => count($buracos),
            'confirmed' => 0,
            'not_resolved' => 0,
            'hidden' => 0,
            'resolved' => $countResolved
        ],
        'temporal' => $temporalData,
        'cidades' => $cidadesData,
        'ruas' => $ruasData
    ];

} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}

?>