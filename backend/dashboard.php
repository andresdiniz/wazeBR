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

$id_parceiro = $_SESSION['usuario_id_parceiro'];

// Função para buscar alertas de acidentes (ordenados pelos mais recentes)
function getAccidentAlerts(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT * 
        FROM alerts 
        WHERE type = 'ACCIDENT' AND status = 1 
    ";
    
    // Se não for o parceiro administrador (99), adiciona o filtro de parceiro
    if ($id_parceiro != 99) {
        $query .= "AND id_parceiro = :id_parceiro ";
    }

    $query .= "ORDER BY pubMillis DESC"; // Ordenação dos alertas

    $stmt = $pdo->prepare($query);

    // Se necessário, vincula o parâmetro do parceiro
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// Função para buscar alertas de buracos (ordenados por confidence do maior para o menor)
function getHazardAlerts(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT uuid, country, city, reportRating, confidence, type, subtype, street, location_x, location_y, pubMillis 
        FROM alerts 
        WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE' AND status = 1
    ";

    // Se não for o parceiro administrador (99), adiciona o filtro de parceiro
    if ($id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro ";
    }

    $query .= " ORDER BY confidence DESC"; // Ordenação correta

    $stmt = $pdo->prepare($query);

    // Se necessário, vincula o parâmetro do parceiro
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// Função para buscar alertas de congestionamento (ordenados pela confiabilidade do maior para o menor)
function getJamAlerts(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT uuid, country, city, reportRating, confidence, type, street, location_x, location_y, pubMillis 
        FROM alerts 
        WHERE type = 'JAM' AND status = 1
    ";

    // Se não for o parceiro administrador (99), adiciona o filtro de parceiro
    if ($id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro ";
    }

    $query .= " ORDER BY confidence DESC, pubMillis DESC"; // Ordenação correta

    $stmt = $pdo->prepare($query);

    // Se necessário, vincula o parâmetro do parceiro
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// Função para buscar os alertas "outros" (não classificados como ACCIDENT ou HAZARD com o subtipo HAZARD_ON_ROAD_POT_HOLE)
function getOtherAlerts(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT uuid, country, city, reportRating, confidence, type, subtype, street, location_x, location_y, pubMillis
        FROM alerts
        WHERE (type != 'ACCIDENT' AND (type != 'HAZARD' OR subtype != 'HAZARD_ON_ROAD_POT_HOLE') AND type != 'JAM')
        AND status = 1
    ";

    // Se não for o parceiro administrador (99), adiciona o filtro de parceiro
    if ($id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro ";
    }

    $stmt = $pdo->prepare($query);

    // Se não for o parceiro administrador, vincula o id_parceiro
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// Função para obter a quantidade de alertas ativos no dia de hoje
function getActiveAlertsToday(PDO $pdo, $id_parceiro) {
    // Inicia a query base
    $query = "
        SELECT COUNT(*) AS activeToday 
        FROM alerts 
        WHERE status = 1 AND DATE(FROM_UNIXTIME(pubMillis / 1000)) = CURDATE()
    ";

    // Se não for o parceiro administrador (99), adiciona o filtro de parceiro
    if ($id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro ";
    }

    // Prepara a consulta
    $stmt = $pdo->prepare($query);

    // Se não for o parceiro administrador, vincula o id_parceiro
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    // Executa a consulta
    $stmt->execute();

    // Retorna a quantidade de alertas ativos
    return $stmt->fetch(PDO::FETCH_ASSOC)['activeToday'];
}


// Função para obter a quantidade total de alertas no mês (independente do status)
function getTotalAlertsThisMonth(PDO $pdo, $id_parceiro) {
    // Inicia a query base
    $query = "
        SELECT COUNT(*) AS totalMonth 
        FROM alerts 
        WHERE MONTH(FROM_UNIXTIME(pubMillis / 1000)) = MONTH(CURDATE()) 
        AND YEAR(FROM_UNIXTIME(pubMillis / 1000)) = YEAR(CURDATE())
    ";

    // Se não for o parceiro administrador (99), adiciona o filtro de parceiro
    if ($id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro ";
    }

    // Prepara a consulta
    $stmt = $pdo->prepare($query);

    // Se não for o parceiro administrador, vincula o id_parceiro
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    // Executa a consulta
    $stmt->execute();

    // Retorna o total de alertas no mês
    return $stmt->fetch(PDO::FETCH_ASSOC)['totalMonth'];
}

//Nao fazer nessa ainda pois as irregularidades ainda nao tem a coluna id_parceiro
function getTrafficData(PDO $pdo, $id_parceiro = null) {
    // Condicional para filtrar pelo parceiro, caso necessário
    $idParceiroCondition = "";
    if (!is_null($id_parceiro)) {
        $idParceiroCondition = " AND id_parceiro = :id_parceiro";
    }

    // Trânsito lento em irregularities
    $stmt1 = $pdo->prepare("
        SELECT SUM(length) AS total_kms_lento
        FROM irregularities
        WHERE jam_level > 1 AND is_active = 1" . $idParceiroCondition
    );
    if (!is_null($id_parceiro)) {
        $stmt1->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }
    $stmt1->execute();
    $kmsLentoIrregularities = $stmt1->fetch(PDO::FETCH_ASSOC)['total_kms_lento'];

    // Trânsito lento em subroutes
    $stmt2 = $pdo->prepare("
        SELECT SUM(length) AS total_kms_lento_subroutes
        FROM subroutes
        WHERE jam_level > 1 AND is_active = 1" . $idParceiroCondition
    );
    if (!is_null($id_parceiro)) {
        $stmt2->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }
    $stmt2->execute();
    $kmsLentoSubroutes = $stmt2->fetch(PDO::FETCH_ASSOC)['total_kms_lento_subroutes'];

    // Atraso em irregularities
    $stmt3 = $pdo->prepare("
        SELECT SUM(time - historic_time) AS total_atraso_irregularities
        FROM irregularities
        WHERE time > historic_time AND is_active = 1" . $idParceiroCondition
    );
    if (!is_null($id_parceiro)) {
        $stmt3->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }
    $stmt3->execute();
    $atrasoIrregularities = $stmt3->fetch(PDO::FETCH_ASSOC)['total_atraso_irregularities'];

    // Atraso em routes
    $stmt4 = $pdo->prepare("
        SELECT SUM(time - historic_time) AS total_atraso_routes
        FROM routes
        WHERE time > historic_time AND is_active = 1" . $idParceiroCondition
    );
    if (!is_null($id_parceiro)) {
        $stmt4->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }
    $stmt4->execute();
    $atrasoRoutes = $stmt4->fetch(PDO::FETCH_ASSOC)['total_atraso_routes'];

    // Atraso em subroutes
    $stmt5 = $pdo->prepare("
        SELECT SUM(time - historic_time) AS total_atraso_subroutes
        FROM subroutes
        WHERE time > historic_time AND is_active = 1" . $idParceiroCondition
    );
    if (!is_null($id_parceiro)) {
        $stmt5->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }
    $stmt5->execute();
    $atrasoSubroutes = $stmt5->fetch(PDO::FETCH_ASSOC)['total_atraso_subroutes'];

    // Soma total de atrasos (em segundos)
    $totalAtrasoSegundos = $atrasoIrregularities + $atrasoRoutes + $atrasoSubroutes;

    // Soma total de quilômetros lentos
    $totalKmsLento = ($kmsLentoIrregularities ?? 0) + ($kmsLentoSubroutes ?? 0);

    // Retorno dos dados calculados
    return [
        'total_kms_lento' => number_format($totalKmsLento / 1000, 2), // Converte para quilômetros
        'total_atraso_minutos' => number_format($totalAtrasoSegundos / 60, 2), // Converte para minutos
        'total_atraso_horas' => number_format($totalAtrasoSegundos / 3600, 2) // Converte para horas
    ];
}

$traficdata = getTrafficData($pdo, $id_parceiro); // Pode adicionar lógica condicional aqui, se necessário

$data = [
    'accidentAlerts' => getAccidentAlerts($pdo, $id_parceiro),
    'hazardAlerts' => getHazardAlerts($pdo, $id_parceiro),
    'jamAlerts' => getJamAlerts($pdo, $id_parceiro),
    'otherAlerts' => getOtherAlerts($pdo, $id_parceiro),
    'activeAlertsToday' => getActiveAlertsToday($pdo, $id_parceiro),
    'totalAlertsThisMonth' => getTotalAlertsThisMonth($pdo, $id_parceiro),
    'traficdata' => $traficdata,
];

