<?php
// Identificador único da requisição + tempo
$request_id = uniqid('REQ_');
$start = microtime(true);

// 1. Headers de resposta
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300'); // Cache HTTP por 5 minutos

// 2. Includes principais
require_once './config/configbd.php';
require_once './vendor/autoload.php';
// se Logger não for carregado via autoload, inclua aqui:
// require_once __DIR__ . '/classes/Logger.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use PDO;
use PDOException;

// Garante que a sessão exista antes de ler
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Inicialização do Logger
$logDir = __DIR__ . '/../logs';
$logger = Logger::getInstance($logDir, true); // true = modo debug (ajuste se quiser)

// 4. Inicialização de Twig e PDO
try {
    // Twig (mesmo que hoje você não use aqui, mantive para não perder recurso)
    $loader = new FilesystemLoader(__DIR__ . '/../frontend');
    $twig   = new Environment($loader);

    // Conexão com o banco
    $pdo = Database::getConnection();
    $logger->info('Database connection established.', ['request_id' => $request_id]);
} catch (PDOException $e) {
    $logger->error('Failed to connect to database.', [
        'error'      => $e->getMessage(),
        'request_id' => $request_id
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    $logger->error('Initialization error.', [
        'error'      => $e->getMessage(),
        'request_id' => $request_id
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Application initialization failed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5. Segurança / sessão
if (!isset($_SESSION['usuario_id_parceiro'])) {
    $logger->warning('Session variable usuario_id_parceiro not set.', ['request_id' => $request_id]);
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized or session expired.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id_parceiro = (int) $_SESSION['usuario_id_parceiro'];
// libera o lock da sessão para não travar outras requisições
session_write_close();

/* ===========================================================
 * FUNÇÕES AUXILIARES PARA ALERTS
 * Mantém a mesma assinatura e comportamento do seu código.
 * ===========================================================
 */

/**
 * Base genérica para queries de alerts com filtro opcional por parceiro.
 */
function baseAlertQuery(PDO $pdo, Logger $logger, string $baseSql, ?int $id_parceiro, string $orderBy = ''): array
{
    $sql = $baseSql;

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $sql .= ' AND id_parceiro = :id_parceiro';
    }

    if ($orderBy) {
        $sql .= ' ' . $orderBy;
    }

    $stmt = $pdo->prepare($sql);

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $stmt->bindValue(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    try {
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $logger->error('DB Error in baseAlertQuery', ['error' => $e->getMessage(), 'sql' => $sql]);
        return [];
    }
}

/**
 * Acidentes
 */
function getAccidentAlerts(PDO $pdo, Logger $logger, ?int $id_parceiro = null): array
{
    $sql = "
        SELECT uuid, country, city, reportRating, confidence, type, street,
               location_x, location_y, pubMillis
        FROM alerts
        WHERE type = 'ACCIDENT' AND status = 1
    ";

    return baseAlertQuery(
        $pdo,
        $logger,
        $sql,
        $id_parceiro,
        'ORDER BY pubMillis DESC'
    );
}

/**
 * Buracos (HAZARD_ON_ROAD_POT_HOLE)
 */
function getHazardAlerts(PDO $pdo, Logger $logger, ?int $id_parceiro = null): array
{
    $sql = "
        SELECT uuid, country, city, reportRating, confidence, type, subtype,
               street, location_x, location_y, pubMillis
        FROM alerts
        WHERE type = 'HAZARD'
          AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'
          AND status = 1
    ";

    return baseAlertQuery(
        $pdo,
        $logger,
        $sql,
        $id_parceiro,
        'ORDER BY confidence DESC'
    );
}

/**
 * Jams (congestionamentos)
 */
function getJamAlerts(PDO $pdo, Logger $logger, ?int $id_parceiro = null): array
{
    $sql = "
        SELECT uuid, country, city, reportRating, confidence, type, street,
               location_x, location_y, pubMillis
        FROM alerts
        WHERE type = 'JAM' AND status = 1
    ";

    return baseAlertQuery(
        $pdo,
        $logger,
        $sql,
        $id_parceiro,
        'ORDER BY confidence DESC, pubMillis DESC'
    );
}

/**
 * Outros alertas
 */
function getOtherAlerts(PDO $pdo, Logger $logger, ?int $id_parceiro = null): array
{
    $sql = "
        SELECT uuid, country, city, reportRating, confidence, type, subtype,
               street, location_x, location_y, pubMillis
        FROM alerts
        WHERE status = 1
          AND NOT (
                type = 'ACCIDENT'
            OR  type = 'JAM'
            OR (type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE')
          )
    ";

    return baseAlertQuery($pdo, $logger, $sql, $id_parceiro);
}

/**
 * Total de alertas ativos (qualquer período)
 */
function getActiveAlertsAnyPeriod(PDO $pdo, Logger $logger, ?int $id_parceiro = null): int
{
    $sql = "SELECT COUNT(*) AS activeTotal FROM alerts WHERE status = 1";

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $sql .= " AND id_parceiro = :id_parceiro";
    }

    $stmt = $pdo->prepare($sql);

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $stmt->bindValue(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    try {
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['activeTotal'] ?? 0);
    } catch (PDOException $e) {
        $logger->error('DB Error in getActiveAlertsAnyPeriod', ['error' => $e->getMessage()]);
        return 0;
    }
}

/**
 * Total de alertas no mês corrente (otimizado para usar índice)
 */
function getTotalAlertsThisMonth(PDO $pdo, Logger $logger, ?int $id_parceiro = null): int
{
    // pubMillis em milissegundos → usamos range em milissegundos
    $startMonth = strtotime(date('Y-m-01 00:00:00')) * 1000;
    $endMonth   = strtotime(date('Y-m-t 23:59:59')) * 1000;

    $sql = "
        SELECT COUNT(*) AS totalMonth
        FROM alerts
        WHERE pubMillis BETWEEN :start AND :end
    ";

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $sql .= " AND id_parceiro = :id_parceiro";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':start', $startMonth, PDO::PARAM_INT);
    $stmt->bindValue(':end',   $endMonth,   PDO::PARAM_INT);

    if ($id_parceiro !== null && $id_parceiro != 99) {
        $stmt->bindValue(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    try {
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['totalMonth'] ?? 0);
    } catch (PDOException $e) {
        $logger->error('DB Error in getTotalAlertsThisMonth', ['error' => $e->getMessage()]);
        return 0;
    }
}

/* ===========================================================
 * TRÁFEGO / CACHE
 * ===========================================================
 */

function getTrafficData(PDO $pdo, Logger $logger, ?int $id_parceiro = null): array
{
    $cacheDir  = __DIR__ . '/cache';
    $cacheKey  = 'trafficdata_' . ($id_parceiro ?? 'all') . '.json';
    $cacheFile = $cacheDir . '/' . $cacheKey;
    $cacheTtl  = 300; // 5 minutos

    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            $logger->warning('Failed to create cache directory.', ['path' => $cacheDir]);
        }
    }

    // 1. Tenta cache (leitura rápida)
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        $content = @file_get_contents($cacheFile);
        if ($content !== false) {
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Não loga cache hit toda hora para não poluir log
                return $data;
            } else {
                $logger->warning('Failed to decode cached JSON.', [
                    'key'        => $cacheKey,
                    'json_error' => json_last_error_msg()
                ]);
            }
        }
    }

    // 2. Se não tem cache válido, consulta o banco
    $params = [];
    $filter = '';
    if ($id_parceiro !== null && $id_parceiro != 99) {
        $filter = ' AND id_parceiro = :id_parceiro';
        $params[':id_parceiro'] = $id_parceiro;
    }

    $getData = function (string $table) use ($pdo, $logger, $filter, $params): array {
        $sql = "
            SELECT 
                SUM(CASE WHEN jam_level > 1 THEN length ELSE 0 END) AS lento,
                SUM(CASE WHEN time > historic_time THEN time - historic_time ELSE 0 END) AS atraso
            FROM {$table}
            WHERE is_active = 1 {$filter}
        ";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, PDO::PARAM_INT);
        }

        try {
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return [
                'lento'  => (float)($row['lento']  ?? 0),
                'atraso' => (float)($row['atraso'] ?? 0),
            ];
        } catch (PDOException $e) {
            $logger->error("DB Error getting data from table {$table}", ['error' => $e->getMessage()]);
            return ['lento' => 0, 'atraso' => 0];
        }
    };

    // Dados das 3 tabelas principais
    $irregularities = $getData('irregularities');
    $subroutes      = $getData('subroutes');
    $routes         = $getData('routes');

    // Dados da tabela jams
    $jamsSql = "
        SELECT 
            SUM(length) AS lento,
            SUM(delay)  AS atraso
        FROM jams
        WHERE status = 1 {$filter}
    ";

    $jamsStmt = $pdo->prepare($jamsSql);
    foreach ($params as $key => $val) {
        $jamsStmt->bindValue($key, $val, PDO::PARAM_INT);
    }

    try {
        $jamsStmt->execute();
        $jamsRow  = $jamsStmt->fetch(PDO::FETCH_ASSOC);
        $jamsData = [
            'lento'  => (float)($jamsRow['lento']  ?? 0),
            'atraso' => (float)($jamsRow['atraso'] ?? 0),
        ];
    } catch (PDOException $e) {
        $logger->error('DB Error getting data from jams table', ['error' => $e->getMessage()]);
        $jamsData = ['lento' => 0, 'atraso' => 0];
    }

    // Cálculo total
    $totalKmsLento = $irregularities['lento'] + $subroutes['lento'] + $jamsData['lento'];
    $totalAtraso   = $irregularities['atraso'] + $subroutes['atraso'] + $routes['atraso'] + $jamsData['atraso'];

    $result = [
        'total_kms_lento'       => number_format($totalKmsLento / 1000, 2, '.', ''),
        'total_atraso_minutos'  => number_format($totalAtraso / 60, 2, '.', ''),
        'total_atraso_horas'    => number_format($totalAtraso / 3600, 2, '.', ''),
    ];

    // 3. Grava cache com escrita atômica
    $tmpFile = $cacheFile . '.tmp';
    if (@file_put_contents($tmpFile, json_encode($result, JSON_UNESCAPED_UNICODE)) !== false) {
        @rename($tmpFile, $cacheFile);
        $logger->info('Traffic data calculated and cached.', ['key' => $cacheKey]);
    } else {
        $logger->warning('Failed to write traffic data to cache file.', ['path' => $cacheFile]);
        @unlink($tmpFile);
    }

    return $result;
}

/* ===========================================================
 * COLETA DE MÉTRICAS / RESPOSTA
 * measurePerformance deve existir em scripts.php (como você já tinha)
 * ===========================================================
 */

// Se measurePerformance estiver em outro arquivo, garanta o require antes deste script
// require_once __DIR__ . '/functions/scripts.php';

$metrics = [];

// Cada entrada mantém a mesma semântica que você já usava
$data = [
    'accidentAlerts' => measurePerformance(
        function () use ($pdo, $logger, $id_parceiro) {
            return ($id_parceiro == 99)
                ? getAccidentAlerts($pdo, $logger, null)
                : getAccidentAlerts($pdo, $logger, $id_parceiro);
        },
        $metrics['accidentAlerts']
    ),

    'hazardAlerts' => measurePerformance(
        function () use ($pdo, $logger, $id_parceiro) {
            return ($id_parceiro == 99)
                ? getHazardAlerts($pdo, $logger, null)
                : getHazardAlerts($pdo, $logger, $id_parceiro);
        },
        $metrics['hazardAlerts']
    ),

    'jamAlerts' => measurePerformance(
        function () use ($pdo, $logger, $id_parceiro) {
            return ($id_parceiro == 99)
                ? getJamAlerts($pdo, $logger, null)
                : getJamAlerts($pdo, $logger, $id_parceiro);
        },
        $metrics['jamAlerts']
    ),

    'otherAlerts' => measurePerformance(
        function () use ($pdo, $logger, $id_parceiro) {
            return ($id_parceiro == 99)
                ? getOtherAlerts($pdo, $logger, null)
                : getOtherAlerts($pdo, $logger, $id_parceiro);
        },
        $metrics['otherAlerts']
    ),

    'activeAlertsToday' => measurePerformance(
        function () use ($pdo, $logger, $id_parceiro) {
            return ($id_parceiro == 99)
                ? getActiveAlertsAnyPeriod($pdo, $logger, null)
                : getActiveAlertsAnyPeriod($pdo, $logger, $id_parceiro);
        },
        $metrics['activeAlertsToday']
    ),

    'totalAlertsThisMonth' => measurePerformance(
        function () use ($pdo, $logger, $id_parceiro) {
            return ($id_parceiro == 99)
                ? getTotalAlertsThisMonth($pdo, $logger, null)
                : getTotalAlertsThisMonth($pdo, $logger, $id_parceiro);
        },
        $metrics['totalAlertsThisMonth']
    ),

    'traficdata' => measurePerformance(
        function () use ($pdo, $logger, $id_parceiro) {
            return ($id_parceiro == 99)
                ? getTrafficData($pdo, $logger, null)
                : getTrafficData($pdo, $logger, $id_parceiro);
        },
        $metrics['traficdata']
    ),
];

// Tempo total da requisição
$end = microtime(true);
$timeElapsedMs = round(($end - $start) * 1000, 2);

$logger->info('Dashboard request completed.', [
    'time_elapsed_ms' => $timeElapsedMs,
    'request_id'      => $request_id
]);

// Garante que nenhum output buffer antigo vaze
if (ob_get_length()) {
    ob_end_clean();
}

/* Resposta final em JSON (mantendo todos os* recursos)
echo json_encode(
    [
        'request_id' =>/ $request_id,
        'time_ms'    => $timeElapsedMs,
        'metrics'    => $metrics,
        'data'       => $data
    ],
    JSON_UNESCAPED_UNICODE
);
*/
$response = json_decode($jsonFromApi, true); // se vier via API interna
$data = [
    // métricas rápidas
    'activeAlertsToday'     => $response['data']['activeAlertsToday'],
    'totalAlertsThisMonth'  => $response['data']['totalAlertsThisMonth'],
    'traficdata'            => $response['data']['traficdata'],

    // listas
    'accidentAlerts' => $response['data']['accidentAlerts'],
    'jamAlerts'      => $response['data']['jamAlerts'],
    'hazardAlerts'   => $response['data']['hazardAlerts'],
    'otherAlerts'    => $response['data']['otherAlerts']
];
exit;
