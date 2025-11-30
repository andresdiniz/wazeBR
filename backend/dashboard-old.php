<?php
// Define um identificador único para a requisição
$request_id = uniqid('REQ_');
$start = microtime(true);

// 1. Otimização: Remoção de headers não essenciais no início,
// apenas o Content-Type e Cache-Control (aumentado para 5 minutos)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300'); // Cache por 5 minutos

// 2. Logs e Configuração
// Assume-se que 'configbd.php' configura a conexão e que 'vendor/autoload.php'
// inclui o Monolog ou outro sistema de logging PSR-3.
require_once './config/configbd.php';
require_once './vendor/autoload.php';

// Mock da classe Logger para demonstração (Deve ser substituído pela implementação real do Monolog)
class Logger {
    public static function info($message, $context = []) { error_log("INFO: " . $message . " " . json_encode($context)); }
    public static function warning($message, $context = []) { error_log("WARNING: " . $message . " " . json_encode($context)); }
    public static function error($message, $context = []) { error_log("ERROR: " . $message . " " . json_encode($context)); }
}

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use PDOException; // Importa a classe de exceção do PDO

// 3. Tratamento de Erro na Conexão com o Banco de Dados
try {
    // Configura o carregador do Twig
    $loader = new FilesystemLoader(__DIR__ . '/../frontend');
    $twig = new Environment($loader);

    // Conexão com o banco de dados - Assume-se que getConnection() lança PDOException
    $pdo = Database::getConnection();
    Logger::info('Database connection established.', ['request_id' => $request_id]);

} catch (PDOException $e) {
    // Falha crítica: não é possível carregar o dashboard sem DB
    Logger::error('Failed to connect to database.', ['error' => $e->getMessage(), 'request_id' => $request_id]);
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit;
} catch (Exception $e) {
    // Outros erros durante a inicialização (ex: Twig)
    Logger::error('Initialization error.', ['error' => $e->getMessage(), 'request_id' => $request_id]);
    http_response_code(500);
    echo json_encode(['error' => 'Application initialization failed.']);
    exit;
}

// 4. Lógica de Sessão e Segurança
if (!isset($_SESSION['usuario_id_parceiro'])) {
    // Tratamento de segurança: Redirecionar ou retornar erro se a sessão não estiver pronta
    Logger::warning('Session variable usuario_id_parceiro not set.', ['request_id' => $request_id]);
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized or session expired.']);
    exit;
}

$id_parceiro = $_SESSION['usuario_id_parceiro'];
session_write_close(); // Libera o lock da sessão o mais cedo possível

// 5. Otimização: Mock da função measurePerformance para simular sua remoção
// Em produção, esta função e as chamadas a ela seriam removidas para evitar overhead.
function measurePerformance($callback, &$metric = null) {
    // Remove o overhead da medição detalhada em ambiente de produção
    return $callback();
}

// --- Funções de Busca (Otimizadas com Tipagem e uso consistente de Binding) ---

/**
 * Função para buscar alertas de acidentes (ordenados pelos mais recentes)
 * @param PDO $pdo
 * @param int|null $id_parceiro
 * @return array
 */
function getAccidentAlerts(PDO $pdo, $id_parceiro = null): array {
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
        Logger::error('DB Error in getAccidentAlerts', ['error' => $e->getMessage()]);
        return []; // Retorna array vazio em caso de falha
    }
}

/**
 * Função para buscar alertas de buracos
 * @param PDO $pdo
 * @param int|null $id_parceiro
 * @return array
 */
function getHazardAlerts(PDO $pdo, $id_parceiro = null): array {
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
        Logger::error('DB Error in getHazardAlerts', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Função para buscar alertas de congestionamento
 * @param PDO $pdo
 * @param int|null $id_parceiro
 * @return array
 */
function getJamAlerts(PDO $pdo, $id_parceiro = null): array {
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
        Logger::error('DB Error in getJamAlerts', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Função para buscar outros alertas
 * @param PDO $pdo
 * @param int|null $id_parceiro
 * @return array
 */
function getOtherAlerts(PDO $pdo, $id_parceiro = null): array {
    // Consulta reescrita para melhor clareza na lógica:
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
        Logger::error('DB Error in getOtherAlerts', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Função para alertas ativos em qualquer período
 * @param PDO $pdo
 * @param int|null $id_parceiro
 * @return int
 */
function getActiveAlertsAnyPeriod(PDO $pdo, $id_parceiro = null): int {
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
        Logger::error('DB Error in getActiveAlertsAnyPeriod', ['error' => $e->getMessage()]);
        return 0;
    }
}

/**
 * Função para total de alertas no mês
 * @param PDO $pdo
 * @param int|null $id_parceiro
 * @return int
 */
function getTotalAlertsThisMonth(PDO $pdo, $id_parceiro = null): int {
    // 6. Otimização SQL: Usando funções de data do MySQL para filtrar pelo mês/ano
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
        Logger::error('DB Error in getTotalAlertsThisMonth', ['error' => $e->getMessage()]);
        return 0;
    }
}

// 7. Melhoria no Sistema de Cache e Tratamento de Erro de Arquivo
function getTrafficData(PDO $pdo, $id_parceiro = null): array {
    $cacheDir = __DIR__ . '/cache';
    $cacheKey = 'trafficdata_' . ($id_parceiro ?? 'all') . '.json';
    $cacheFile = $cacheDir . '/' . $cacheKey;

    // Garante que o diretório de cache existe
    if (!file_exists($cacheDir)) {
        if (!mkdir($cacheDir, 0777, true)) {
            Logger::warning('Failed to create cache directory.', ['path' => $cacheDir]);
            // Continua sem cache
        }
    }

    // Leitura do Cache
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
        $content = @file_get_contents($cacheFile);
        if ($content !== false) {
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                Logger::info('Traffic data served from cache.', ['key' => $cacheKey]);
                return $data;
            } else {
                Logger::warning('Failed to decode cached JSON.', ['key' => $cacheKey, 'json_error' => json_last_error_msg()]);
                // Força o recálculo se o cache estiver corrompido
            }
        }
    }

    $params = [];
    $filter = '';
    if (!is_null($id_parceiro) && $id_parceiro != 99) {
        // Se a coluna id_parceiro for adicionada a irregularities, subroutes e jams, o filtro funciona.
        $filter = ' AND id_parceiro = :id_parceiro';
        $params[':id_parceiro'] = $id_parceiro;
    }

    // Função interna para obter dados, com tratamento de exceção
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
        try {
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            Logger::error("DB Error getting data from table $table", ['error' => $e->getMessage()]);
            return ['lento' => 0, 'atraso' => 0];
        }
    };

    // Dados das tabelas originais
    $irregularities = $getData('irregularities');
    $subroutes = $getData('subroutes');
    $routes = $getData('routes');

    // Obter dados da tabela jams
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
        Logger::error('DB Error getting data from jams table', ['error' => $e->getMessage()]);
        $jamsData = ['lento' => 0, 'atraso' => 0];
    }

    // Cálculo total
    $totalKmsLento = ($irregularities['lento'] ?? 0) + ($subroutes['lento'] ?? 0) + ($jamsData['lento'] ?? 0);
    $totalAtraso = ($irregularities['atraso'] ?? 0) + ($subroutes['atraso'] ?? 0) + ($routes['atraso'] ?? 0) + ($jamsData['atraso'] ?? 0);

    $result = [
        'total_kms_lento' => number_format($totalKmsLento / 1000, 2, '.', ''), // Usa ponto para JSON
        'total_atraso_minutos' => number_format($totalAtraso / 60, 2, '.', ''),
        'total_atraso_horas' => number_format($totalAtraso / 3600, 2, '.', '')
    ];
    
    // Salvar no cache com verificação de sucesso
    if (file_put_contents($cacheFile, json_encode($result)) === false) {
        Logger::warning('Failed to write traffic data to cache file.', ['path' => $cacheFile]);
    } else {
        Logger::info('Traffic data successfully calculated and cached.', ['key' => $cacheKey]);
    }
    
    return $result;
}

// --- Coleta de Métricas ---
$metrics = []; // Mantido para simular a chamada original

// Coleta de dados com medição de performance (agora simulada, sem overhead)
$data = [
    'accidentAlerts' => measurePerformance(
        function() use ($pdo, $id_parceiro) {
            return $id_parceiro == 99 
                ? getAccidentAlerts($pdo) 
                : getAccidentAlerts($pdo, $id_parceiro);
        }, 
        $metrics['accidentAlerts']
    ),
    
    'hazardAlerts' => measurePerformance(
        function() use ($pdo, $id_parceiro) {
            return $id_parceiro == 99 
                ? getHazardAlerts($pdo) 
                : getHazardAlerts($pdo, $id_parceiro);
        }, 
        $metrics['hazardAlerts']
    ),
    
    'jamAlerts' => measurePerformance(
        function() use ($pdo, $id_parceiro) {
            return $id_parceiro == 99 
                ? getJamAlerts($pdo) 
                : getJamAlerts($pdo, $id_parceiro);
        }, 
        $metrics['jamAlerts']
    ),
    
    'otherAlerts' => measurePerformance(
        function() use ($pdo, $id_parceiro) {
            return $id_parceiro == 99 
                ? getOtherAlerts($pdo) 
                : getOtherAlerts($pdo, $id_parceiro);
        }, 
        $metrics['otherAlerts']
    ),
    
    'activeAlertsToday' => measurePerformance(
        function() use ($pdo, $id_parceiro) {
            return $id_parceiro == 99 
                ? getActiveAlertsAnyPeriod($pdo) 
                : getActiveAlertsAnyPeriod($pdo, $id_parceiro);
        }, 
        $metrics['activeAlertsToday']
    ),
    
    'totalAlertsThisMonth' => measurePerformance(
        function() use ($pdo, $id_parceiro) {
            return $id_parceiro == 99 
                ? getTotalAlertsThisMonth($pdo) 
                : getTotalAlertsThisMonth($pdo, $id_parceiro);
        }, 
        $metrics['totalAlertsThisMonth']
    ),
    
    'traficdata' => measurePerformance(
        function() use ($pdo, $id_parceiro) {
            return $id_parceiro == 99 
                ? getTrafficData($pdo) 
                : getTrafficData($pdo, $id_parceiro);
        }, 
        $metrics['traficdata']
    ),
];

// Otimização: A função savePerformanceMetrics foi removida/mockada, pois adiciona overhead
// Salvando métricas de tempo de execução (apenas o tempo total)
$end = microtime(true);
Logger::info('Dashboard request completed.', [
    'time_elapsed_ms' => round(($end - $start) * 1000, 2),
    'request_id' => $request_id
]);


// 8. Renderização do Template e Finalização
ob_end_clean(); // Limpa qualquer buffer de saída anterior
echo $twig->render('dashboard.html.twig', $data); // Assumindo que o Twig renderiza o HTML final
// Se o output for apenas JSON (como o header sugere, o echo acima deve ser substituído)
// echo json_encode($data); 
// Mantive o $twig->render() por causa da importação do Twig no código original.
// Se a intenção é JSON puro (como sugere o header), use:
// echo json_encode($data);
?>