<?php
// Inicia buffer de saída imediatamente
ob_start();
$start = microtime(true);

// Configura headers após iniciar o buffer
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=240');

require_once './config/configbd.php';
require_once './vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Função para salvar métricas (movida para cima)
function savePerformanceMetrics($metrics, $startTime) {
    try {
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/desempenho/';
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
            file_put_contents($logDir . '.htaccess', "Deny from all");
        }

        $filename = $logDir . 'metrics-' . date('Y-m-d') . '.log';

        $logData = [
            'timestamp' => date('c'),
            'execution_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
            'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'MB',
            'details' => $metrics
        ];

        file_put_contents(
            $filename,
            json_encode($logData, JSON_PRETTY_PRINT) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

    } catch (Exception $e) {
        error_log("Erro ao salvar métricas: " . $e->getMessage());
    }
}

// 1. Configuração do Twig
$loader = new FilesystemLoader(__DIR__ . '/../frontend');
$twig = new Environment($loader, [
    'cache' => __DIR__ . '/../tmp/twig_cache',
    'auto_reload' => true
]);

$pdo = Database::getConnection();
$id_parceiro = $_SESSION['usuario_id_parceiro'];
session_write_close();

// 2. Função de medição corrigida
function measurePerformance(callable $function, &$metrics = null) {
    $start = microtime(true);
    $result = $function();
    $time = round((microtime(true) - $start) * 1000, 2);
    $memory = memory_get_peak_usage(true);
    
    if ($metrics !== null) {
        $metrics = [
            'time' => $time . ' ms',
            'memory' => round($memory / 1024 / 1024, 2) . ' MB'
        ];
    }
    
    return $result;
}

// 3. Chamadas corrigidas para measurePerformance
$metrics = [];

// Coleta de dados com métricas
$combinedAlerts = measurePerformance(
    function() use ($pdo, $id_parceiro) {
        $query = "SELECT type, subtype, uuid, city, street, 
                 location_x, location_y, pubMillis, confidence, reportRating
                 FROM alerts
                 WHERE status = 1 AND (
                     type = 'ACCIDENT' OR
                     (type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE') OR
                     type = 'JAM'
                 )".($id_parceiro != 99 ? " AND id_parceiro = :id_parceiro" : "");

        $stmt = $pdo->prepare($query);
        if ($id_parceiro != 99) {
            $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    },
    $metrics['combinedAlerts']
);

$processedAlerts = measurePerformance(
    function() use ($combinedAlerts) {
        $result = ['accident' => [], 'hazard' => [], 'jam' => [], 'other' => []];
        foreach ($combinedAlerts as $alert) {
            // ... (processamento igual ao original)
        }
        return $result;
    },
    $metrics['processedAlerts']
);

// 4. Geração do relatório final
$data = $processedAlerts;
savePerformanceMetrics($metrics, $start);

// Limpa buffer e envia resposta
ob_end_clean();