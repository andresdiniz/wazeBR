<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/functions/scripts.php'; // Suporte
require_once __DIR__ . '/config/configbd.php';   // Inclui a classe Database

use Dotenv\Dotenv;

// Carregar .env
$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    die("Arquivo .env não encontrado em: $envPath");
}

try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    error_log("Erro ao carregar .env: " . $e->getMessage());
    logEmail("error", "Erro ao carregar .env: " . $e->getMessage());
    die("Erro ao carregar .env.");
}

// Ativar debug log se necessário
if ($_ENV['DEBUG'] === 'true') {
    ini_set('log_errors', 1);
    if (!is_dir(__DIR__ . '/../logs')) {
        mkdir(__DIR__ . '/../logs', 0777, true);
    }
    ini_set('error_log', __DIR__ . '/../logs/debug.log');
}

header('Content-Type: application/json');

// Verificação de token
define('API_SECRET_TOKEN', $_ENV['API_SECRET_TOKEN']);
$token = $_GET['token'] ?? $_POST['token'] ?? '';
if ($token !== API_SECRET_TOKEN) {
    http_response_code(403);
    echo json_encode(['erro' => 'Token inválido']);
    exit;
}

// Obter parâmetros
$id_parceiro = intval($_GET['id_parceiro'] ?? $_POST['id_parceiro'] ?? 0);
$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';

if (!$id_parceiro || !$acao) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetros obrigatórios ausentes']);
    exit;
}

// Instancia PDO com sua classe Database
try {
    $pdo = Database::getConnection();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro na conexão PDO', 'detalhes' => $e->getMessage()]);
    exit;
}

// Ações disponíveis
switch ($acao) {
    case 'listar_congestionamento':
        $stmt = $pdo->prepare("SELECT local, kms, tempo_minutos FROM congestionamento WHERE parceiro_id = ?");
        $stmt->execute([$id_parceiro]);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['dados' => $dados]);
        break;

    case 'calcular_congestionamento':
        $resultado = getTrafficData($pdo, $id_parceiro);
        echo json_encode(['dados' => $resultado]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['erro' => 'Ação inválida']);
        break;
}

////////////////////////////////////////////////////////////////////////////////

// Função que calcula lentidão e atraso (mantida do seu código original)
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
    $jamsStmt->execute();
    $jamsData = $jamsStmt->fetch(PDO::FETCH_ASSOC);

    // Soma total
    $totalKmsLento = ($irregularities['lento'] ?? 0) + ($subroutes['lento'] ?? 0) + ($jamsData['lento'] ?? 0);
    $totalAtraso = ($irregularities['atraso'] ?? 0) + ($subroutes['atraso'] ?? 0) + ($routes['atraso'] ?? 0) + ($jamsData['atraso'] ?? 0);

    $result = [
        'total_kms_lento' => number_format($totalKmsLento / 1000, 2),
        'total_atraso_minutos' => number_format($totalAtraso / 60, 2),
        'total_atraso_horas' => number_format($totalAtraso / 3600, 2)
    ];

    file_put_contents($cacheFile, json_encode($result));

    return $result;
}
