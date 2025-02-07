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

$id_parceiro = $_SESSION['usuario_id_parceiro']; // Obtém o ID do parceiro da sessão

// Função genérica para buscar alertas com filtro de parceiro
function getAlertsByType(PDO $pdo, $id_parceiro, $type, $subtypeCondition = "") {
    $query = "SELECT * FROM alerts WHERE type = :type AND status = 1 ";

    if (!empty($subtypeCondition)) {
        $query .= "AND $subtypeCondition ";
    }

    if ($id_parceiro != 99) {
        $query .= "AND id_parceiro = :id_parceiro ";
    }

    $query .= "ORDER BY pubMillis DESC";

    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':type', $type, PDO::PARAM_STR);

    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar alertas "outros"
function getOtherAlerts(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT * FROM alerts
        WHERE type NOT IN ('ACCIDENT', 'JAM', 'HAZARD') 
        OR (type = 'HAZARD' AND subtype != 'HAZARD_ON_ROAD_POT_HOLE') 
        AND status = 1 ";

    if ($id_parceiro != 99) {
        $query .= "AND id_parceiro = :id_parceiro ";
    }

    $stmt = $pdo->prepare($query);

    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para obter a quantidade de alertas ativos no dia de hoje
function getActiveAlertsToday(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT COUNT(*) AS activeToday 
        FROM alerts 
        WHERE status = 1 
        AND DATE(FROM_UNIXTIME(pubMillis / 1000)) = CURDATE() ";

    if ($id_parceiro != 99) {
        $query .= "AND id_parceiro = :id_parceiro ";
    }

    $stmt = $pdo->prepare($query);

    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC)['activeToday'];
}

// Função para obter a quantidade total de alertas no mês
function getTotalAlertsThisMonth(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT COUNT(*) AS totalMonth 
        FROM alerts 
        WHERE MONTH(FROM_UNIXTIME(pubMillis / 1000)) = MONTH(CURDATE()) 
        AND YEAR(FROM_UNIXTIME(pubMillis / 1000)) = YEAR(CURDATE()) ";

    if ($id_parceiro != 99) {
        $query .= "AND id_parceiro = :id_parceiro ";
    }

    $stmt = $pdo->prepare($query);

    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC)['totalMonth'];
}

// Função para buscar dados de trânsito
function getTrafficData(PDO $pdo, $id_parceiro) {
    $queryParceiro = $id_parceiro != 99 ? "AND id_parceiro = :id_parceiro" : "";

    $stmt1 = $pdo->prepare("
        SELECT SUM(length) AS total_kms_lento
        FROM irregularities
        WHERE jam_level > 1 AND is_active = 1 $queryParceiro
    ");
    
    if ($id_parceiro != 99) {
        $stmt1->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }
    
    $stmt1->execute();
    $kmsLentoIrregularities = $stmt1->fetch(PDO::FETCH_ASSOC)['total_kms_lento'];

    $stmt2 = $pdo->prepare("
        SELECT SUM(time - historic_time) AS total_atraso
        FROM routes
        WHERE time > historic_time AND is_active = 1 $queryParceiro
    ");

    if ($id_parceiro != 99) {
        $stmt2->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt2->execute();
    $totalAtrasoSegundos = $stmt2->fetch(PDO::FETCH_ASSOC)['total_atraso'];

    return [
        'total_kms_lento' => number_format(($kmsLentoIrregularities ?? 0) / 1000, 2),
        'total_atraso_minutos' => number_format(($totalAtrasoSegundos ?? 0) / 60, 2),
        'total_atraso_horas' => number_format(($totalAtrasoSegundos ?? 0) / 3600, 2)
    ];
}

// Buscar os alertas considerando o id_parceiro
$accidentAlerts = getAlertsByType($pdo, $id_parceiro, 'ACCIDENT');
$hazardAlerts = getAlertsByType($pdo, $id_parceiro, 'HAZARD', "subtype = 'HAZARD_ON_ROAD_POT_HOLE'");
$jamAlerts = getAlertsByType($pdo, $id_parceiro, 'JAM');
$otherAlerts = getOtherAlerts($pdo, $id_parceiro);

// Buscar métricas adicionais
$activeAlertsToday = getActiveAlertsToday($pdo, $id_parceiro);
$totalAlertsThisMonth = getTotalAlertsThisMonth($pdo, $id_parceiro);
$trafficData = getTrafficData($pdo, $id_parceiro);

// Exemplo em backend/dashboard.php
$data = [
    'accidentAlerts' => $accidentAlerts,
    'hazardAlerts' => $hazardAlerts,
    'jamAlerts' => $jamAlerts,
    'otherAlerts' => $otherAlerts,
    'activeAlertsToday' => $activeAlertsToday,
    'totalAlertsThisMonth' => $totalAlertsThisMonth,
    'trafficData' => $trafficData
];
