<?php
// Define um identificador único para a requisição
$request_id = uniqid('REQ_');
$start = microtime(true);

// 1. Headers de resposta e inclusões
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300'); // Cache por 5 minutos

require_once './config/configbd.php';
require_once './vendor/autoload.php'; 
// Assumindo que seu Logger.php é carregado pelo autoload. Se não, inclua-o.
// require_once 'Logger.php'; 

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use PDOException;
// Não use 'use Logger;' aqui, pois ele será instanciado

// 2. Inicialização do Logger (OBTENDO A INSTÂNCIA DO SINGLETON)
// Ajuste o caminho do logDir conforme a sua estrutura de arquivos
$logDir = __DIR__ . '/../logs'; 
$logger = Logger::getInstance($logDir, true); // 'true' para debug, ajuste conforme seu ambiente

// 3. Tratamento de Erro na Conexão com o Banco de Dados e Inicialização
try {
    // Configura o carregador do Twig
    $loader = new FilesystemLoader(__DIR__ . '/../frontend');
    $twig = new Environment($loader);

    // Conexão com o banco de dados
    $pdo = Database::getConnection();
    $logger->info('Database connection established.', ['request_id' => $request_id]);

} catch (PDOException $e) {
    // Agora usando a instância $logger
    $logger->error('Failed to connect to database.', ['error' => $e->getMessage(), 'request_id' => $request_id]);
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit;
} catch (Exception $e) {
    // Outros erros durante a inicialização
    $logger->error('Initialization error.', ['error' => $e->getMessage(), 'request_id' => $request_id]);
    http_response_code(500);
    echo json_encode(['error' => 'Application initialization failed.']);
    exit;
}

// 4. Lógica de Sessão e Segurança
if (!isset($_SESSION['usuario_id_parceiro'])) {
    $logger->warning('Session variable usuario_id_parceiro not set.', ['request_id' => $request_id]);
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized or session expired.']);
    exit;
}

$id_parceiro = $_SESSION['usuario_id_parceiro'];
session_write_close(); // Libera o lock da sessão

// --- Funções de Busca (Atualizadas para receber o $logger) ---

/**
 * Função para buscar alertas de acidentes (ordenados pelos mais recentes)
 */
function getAccidentAlerts(PDO $pdo, Logger $logger, $id_parceiro = null): array {
    $query = "SELECT uuid, country, city, reportRating, confidence, type, street, location_x, location_y, pubMillis 
              FROM alerts 
              WHERE type = 'ACCIDENT' AND status = 1";
    
    if ($id_parceiro !== null && $id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro";
    }

    $query .= " ORDER BY pubMillis DESC";

    $stmt = $pdo->prepare($query);

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    try {
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $logger->error('DB Error in getAccidentAlerts', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Função para buscar alertas de buracos
 */
function getHazardAlerts(PDO $pdo, Logger $logger, $id_parceiro = null): array {
    $query = "SELECT uuid, country, city, reportRating, confidence, type, subtype, street, location_x, location_y, pubMillis 
              FROM alerts 
              WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE' AND status = 1";

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro";
    }

    $query .= " ORDER BY confidence DESC";

    $stmt = $pdo->prepare($query);

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    try {
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $logger->error('DB Error in getHazardAlerts', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Função para buscar alertas de congestionamento
 */
function getJamAlerts(PDO $pdo, Logger $logger, $id_parceiro = null): array {
    $query = "SELECT uuid, country, city, reportRating, confidence, type, street, location_x, location_y, pubMillis 
              FROM alerts 
              WHERE type = 'JAM' AND status = 1";

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro";
    }

    $query .= " ORDER BY confidence DESC, pubMillis DESC";

    $stmt = $pdo->prepare($query);

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    try {
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $logger->error('DB Error in getJamAlerts', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Função para buscar outros alertas
 */
function getOtherAlerts(PDO $pdo, Logger $logger, $id_parceiro = null): array {
    $query = "SELECT uuid, country, city, reportRating, confidence, type, subtype, street, location_x, location_y, pubMillis
              FROM alerts
              WHERE status = 1 
              AND NOT (type = 'ACCIDENT' 
                       OR type = 'JAM' 
                       OR (type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'))";

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro";
    }

    $stmt = $pdo->prepare($query);

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    try {
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $logger->error('DB Error in getOtherAlerts', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Função para alertas ativos em qualquer período
 */
function getActiveAlertsAnyPeriod(PDO $pdo, Logger $logger, $id_parceiro = null): int {
    $query = "SELECT COUNT(*) AS activeTotal
              FROM alerts
              WHERE status = 1";

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro";
    }

    $stmt = $pdo->prepare($query);

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    try {
        $stmt->execute();
        return (int)($stmt->fetch(PDO::FETCH_ASSOC)['activeTotal'] ?? 0);
    } catch (PDOException $e) {
        $logger->error('DB Error in getActiveAlertsAnyPeriod', ['error' => $e->getMessage()]);
        return 0;
    }
}

/**
 * Função para total de alertas no mês
 */
function getTotalAlertsThisMonth(PDO $pdo, Logger $logger, $id_parceiro = null): int {
    $query = "SELECT COUNT(*) AS totalMonth 
              FROM alerts 
              WHERE MONTH(FROM_UNIXTIME(pubMillis / 1000)) = MONTH(CURDATE()) 
              AND YEAR(FROM_UNIXTIME(pubMillis / 1000)) = YEAR(CURDATE())";

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro";
    }

    $stmt = $pdo->prepare($query);

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    try {
        $stmt->execute();
        return (int)($stmt->fetch(PDO::FETCH_ASSOC)['totalMonth'] ?? 0);
    } catch (PDOException $e) {
        $logger->error('DB Error in getTotalAlertsThisMonth', ['error' => $e->getMessage()]);
        return 0;
    }
}

/**
 * Função para buscar dados de tráfego com cache e tratamento de erro de arquivo.
 */
function getTrafficData(PDO $pdo, Logger $logger, $id_parceiro = null): array {
    $cacheDir = __DIR__ . '/cache';
    $cacheKey = 'trafficdata_' . ($id_parceiro ?? 'all') . '.json';
    $cacheFile = $cacheDir . '/' . $cacheKey;

    if (!file_exists($cacheDir)) {
        if (!mkdir($cacheDir, 0777, true)) {
            $logger->warning('Failed to create cache directory.', ['path' => $cacheDir]);
        }
    }

    // Leitura do Cache
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
        $content = @file_get_contents($cacheFile);
        if ($content !== false) {
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $logger->info('Traffic data served from cache.', ['key' => $cacheKey]);
                return $data;
            } else {
                $logger->warning('Failed to decode cached JSON.', ['key' => $cacheKey, 'json_error' => json_last_error_msg()]);
            }
        }
    }

    $params = [];
    $filter = '';
    if (!is_null($id_parceiro) && $id_parceiro != 99) {
        $filter = ' AND id_parceiro = :id_parceiro';
        $params[':id_parceiro'] = $id_parceiro;
    }

    $getData = function($table) use ($pdo, $logger, $filter, $params) {
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
        try {
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $logger->error("DB Error getting data from table $table", ['error' => $e->getMessage()]);
            return ['lento' => 0, 'atraso' => 0];
        }
    };

    // Dados das tabelas
    $irregularities = $getData('irregularities');
    $subroutes = $getData('subroutes');
    $routes = $getData('routes');

    // Dados da tabela jams
    $jamsSql = "
        SELECT 
            SUM(length) AS lento,
            SUM(delay) AS atraso
        FROM jams
        WHERE status = 1 $filter
    ";
    
    $jamsStmt = $pdo->prepare($jamsSql);
    foreach ($params as $key => $val) {
        $jamsStmt->bindValue($key, $val, PDO::PARAM_INT);
    }
    try {
        $jamsStmt->execute();
        $jamsData = $jamsStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $logger->error('DB Error getting data from jams table', ['error' => $e->getMessage()]);
        $jamsData = ['lento' => 0, 'atraso' => 0];
    }

    // Cálculo total
    $totalKmsLento = ($irregularities['lento'] ?? 0) + ($subroutes['lento'] ?? 0) + ($jamsData['lento'] ?? 0);
    $totalAtraso = ($irregularities['atraso'] ?? 0) + ($subroutes['atraso'] ?? 0) + ($routes['atraso'] ?? 0) + ($jamsData['atraso'] ?? 0);

    $result = [
        'total_kms_lento' => number_format($totalKmsLento / 1000, 2, '.', ''),
        'total_atraso_minutos' => number_format($totalAtraso / 60, 2, '.', ''),
        'total_atraso_horas' => number_format($totalAtraso / 3600, 2, '.', '')
    ];
    
    // Salvar no cache com verificação de sucesso
    if (file_put_contents($cacheFile, json_encode($result)) === false) {
        $logger->warning('Failed to write traffic data to cache file.', ['path' => $cacheFile]);
    } else {
        $logger->info('Traffic data successfully calculated and cached.', ['key' => $cacheKey]);
    }
    
    return $result;
}

// --- Coleta de Métricas com a função measurePerformance existente em scripts.php ---
$metrics = []; 

// O parâmetro $logger foi adicionado às chamadas de função
$data = [
    'accidentAlerts' => measurePerformance(
        function() use ($pdo, $logger, $id_parceiro) {
            return $id_parceiro == 99 
                ? getAccidentAlerts($pdo, $logger) 
                : getAccidentAlerts($pdo, $logger, $id_parceiro);
        }, 
        $metrics['accidentAlerts']
    ),
    
    'hazardAlerts' => measurePerformance(
        function() use ($pdo, $logger, $id_parceiro) {
            return $id_parceiro == 99 
                ? getHazardAlerts($pdo, $logger) 
                : getHazardAlerts($pdo, $logger, $id_parceiro);
        }, 
        $metrics['hazardAlerts']
    ),
    
    'jamAlerts' => measurePerformance(
        function() use ($pdo, $logger, $id_parceiro) {
            return $id_parceiro == 99 
                ? getJamAlerts($pdo, $logger) 
                : getJamAlerts($pdo, $logger, $id_parceiro);
        }, 
        $metrics['jamAlerts']
    ),
    
    'otherAlerts' => measurePerformance(
        function() use ($pdo, $logger, $id_parceiro) {
            return $id_parceiro == 99 
                ? getOtherAlerts($pdo, $logger) 
                : getOtherAlerts($pdo, $logger, $id_parceiro);
        }, 
        $metrics['otherAlerts']
    ),
    
    'activeAlertsToday' => measurePerformance(
        function() use ($pdo, $logger, $id_parceiro) {
            return $id_parceiro == 99 
                ? getActiveAlertsAnyPeriod($pdo, $logger) 
                : getActiveAlertsAnyPeriod($pdo, $logger, $id_parceiro);
        }, 
        $metrics['activeAlertsToday']
    ),
    
    'totalAlertsThisMonth' => measurePerformance(
        function() use ($pdo, $logger, $id_parceiro) {
            return $id_parceiro == 99 
                ? getTotalAlertsThisMonth($pdo, $logger) 
                : getTotalAlertsThisMonth($pdo, $logger, $id_parceiro);
        }, 
        $metrics['totalAlertsThisMonth']
    ),
    
    'traficdata' => measurePerformance(
        function() use ($pdo, $logger, $id_parceiro) {
            return $id_parceiro == 99 
                ? getTrafficData($pdo, $logger) 
                : getTrafficData($pdo, $logger, $id_parceiro);
        }, 
        $metrics['traficdata']
    ),
];

$end = microtime(true);
$logger->info('Dashboard request completed.', [
    'time_elapsed_ms' => round(($end - $start) * 1000, 2),
    'request_id' => $request_id
]);


// --- Finalização ---
ob_end_clean();
// Retorna JSON para o frontend
//echo json_encode($data);
?>