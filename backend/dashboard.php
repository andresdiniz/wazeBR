<?php
$start = microtime(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=240'); // Cache por 4 minutos
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
session_write_close(); // Libera o lock da sessão

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
    $cacheDir = __DIR__ . '/cache';
    if (!file_exists($cacheDir)) {
        mkdir($cacheDir, 0777, true);
    }

    $cacheKey = 'trafficdata_' . ($id_parceiro ?? 'all') . '.json';
    $cacheFile = $cacheDir . '/' . $cacheKey;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 240) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $params = [];
    $filter = '';
    if (!is_null($id_parceiro) && $id_parceiro != 99) {
        $filter = ' AND id_parceiro = :id_parceiro';
        $params[':id_parceiro'] = $id_parceiro;
    }

    $getData = function($table) use ($pdo, $filter, $params) {
        $sql = "
            SELECT 
                SUM(CASE WHEN jam_level > 1 THEN length ELSE 0 END) AS lento,
                SUM(CASE WHEN time > historic_time THEN time - historic_time ELSE 0 END) AS atraso
            FROM $table
            WHERE is_active = 1 $filter
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    };

    $irregularities = $getData('irregularities');
    $subroutes = $getData('subroutes');
    $routes = $getData('routes');

    $totalKmsLento = ($irregularities['lento'] ?? 0) + ($subroutes['lento'] ?? 0);
    $totalAtraso = ($irregularities['atraso'] ?? 0) + ($subroutes['atraso'] ?? 0) + ($routes['atraso'] ?? 0);

    $result = [
        'total_kms_lento' => number_format($totalKmsLento / 1000, 2),
        'total_atraso_minutos' => number_format($totalAtraso / 60, 2),
        'total_atraso_horas' => number_format($totalAtraso / 3600, 2)
    ];

    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_UNESCAPED_SLASHES);
    file_put_contents($cacheFile, $json);

    return $json;
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


error_log("Tempo de carregamento: " . (microtime(true) - $start));
