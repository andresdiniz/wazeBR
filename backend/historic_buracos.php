<?php
require_once './config/configbd.php';
require_once './vendor/autoload.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id_parceiro'])) {
    header('Location: login.php');
    exit;
}
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}
$id_parceiro = $_SESSION['usuario_id_parceiro'];

try {
    $pdo = Database::getConnection();

    // Consulta principal para buracos
    $baseSql = "SELECT * FROM alerts WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'";
    $baseParams = [];

    if ($id_parceiro != 99) {
        $baseSql .= " AND id_parceiro = :id_parceiro";
        $baseParams['id_parceiro'] = $id_parceiro;
    }

    // Filtros
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

    // Dados para gráficos
    $sqlTemporal = "SELECT 
                    DATE(FROM_UNIXTIME(pubMillis/1000)) as data, 
                    COUNT(*) as total 
                FROM alerts 
                WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'
                GROUP BY data 
                ORDER BY data DESC 
                LIMIT 30";

    $sqlCidades = "SELECT 
                    city, 
                    COUNT(*) as total 
                FROM alerts 
                WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'
                GROUP BY city 
                ORDER BY total DESC 
                LIMIT 10";

    $stmtTemporal = $pdo->prepare($sqlTemporal);
    $stmtTemporal->execute();
    $temporalData = $stmtTemporal->fetchAll(PDO::FETCH_ASSOC);

    $stmtCidades = $pdo->prepare($sqlCidades);
    $stmtCidades->execute();
    $cidadesData = $stmtCidades->fetchAll(PDO::FETCH_ASSOC);

    // No trecho onde faz as consultas SQL:
    $sqlTemporal = "SELECT 
                DATE(FROM_UNIXTIME(pubMillis/1000)) as data, 
                COUNT(*) as total 
            FROM alerts 
            WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'
            GROUP BY data 
            ORDER BY data DESC 
            LIMIT 30";

    $sqlRuas = "SELECT 
            CONCAT(city, ' - ', street) as local,
            COUNT(*) as total 
        FROM alerts 
        WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'
        GROUP BY local 
        ORDER BY total DESC 
        LIMIT 10";

    // Executar e fetch dos dados
    $stmtRuas = $pdo->prepare($sqlRuas);
    $stmtRuas->execute();
    $ruasData = $stmtRuas->fetchAll(PDO::FETCH_ASSOC);

    // Passar para o template
    $data['ruas'] = $ruasData;

    // Executar query principal
    $stmtBuracos = $pdo->prepare($baseSql);
    foreach ($baseParams as $key => $value) {
        $stmtBuracos->bindValue(":$key", $value);
    }
    $stmtBuracos->execute();
    $buracos = $stmtBuracos->fetchAll(PDO::FETCH_ASSOC);

    // Contagens
    $countTotal = $pdo->query("SELECT COUNT(*) FROM alerts WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'")->fetchColumn();

    $data = [
        'buracos' => $buracos,
        'filters' => $filters,
        'counts' => [
            'total' => $countTotal,
            'filtered' => count($buracos),
            'confirmed' => 0, // Implementar lógica real
            'not_resolved' => 0,
            'hidden' => 0
        ],
        'temporal' => $temporalData,
        'cidades' => $cidadesData
    ];

} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}


?>