<?php
ob_start();
$start = microtime(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=240');

require_once './config/configbd.php';
require_once './vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// 1. Otimização de Inicialização
$loader = new FilesystemLoader(__DIR__ . '/../frontend');
$twig = new Environment($loader, [
    'cache' => __DIR__ . '/../tmp/twig_cache', // Ativa cache de templates
    'auto_reload' => true
]);

$pdo = Database::getConnection();
$id_parceiro = $_SESSION['usuario_id_parceiro'];
session_write_close();

// Função genérica para medição de performance
function measurePerformance(callable $function, &$metrics = []) {
    $start = microtime(true);
    $result = $function();
    $time = round((microtime(true) - $start) * 1000, 2);
    $memory = memory_get_peak_usage(true);
    
    $metrics = [
        'time' => $time . ' ms',
        'memory' => round($memory / 1024 / 1024, 2) . ' MB'
    ];
    
    return $result;
}

// 2. Query Unificada para Alertas
function getCombinedAlerts(PDO $pdo, $id_parceiro) {
    $query = "SELECT 
        type, subtype, uuid, city, street, 
        location_x, location_y, pubMillis, confidence, reportRating
    FROM alerts
    WHERE status = 1 AND (
        type = 'ACCIDENT' OR
        (type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE') OR
        type = 'JAM'
    )";

    if ($id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro";
    }

    $stmt = $pdo->prepare($query);
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 3. Processamento de Dados Otimizado
function processAlerts($alerts) {
    $result = [
        'accident' => [],
        'hazard' => [],
        'jam' => [],
        'other' => []
    ];

    foreach ($alerts as $alert) {
        $entry = [
            'uuid' => $alert['uuid'],
            'city' => $alert['city'],
            'street' => $alert['street'],
            'location' => [
                'x' => $alert['location_x'],
                'y' => $alert['location_y']
            ],
            'pubMillis' => $alert['pubMillis'],
            'confidence' => $alert['confidence'] ?? null,
            'reportRating' => $alert['reportRating'] ?? null
        ];

        switch ($alert['type']) {
            case 'ACCIDENT':
                $result['accident'][] = $entry;
                break;
            case 'HAZARD':
                $result['hazard'][] = $entry;
                break;
            case 'JAM':
                $result['jam'][] = $entry;
                break;
            default:
                $result['other'][] = $entry;
        }
    }

    // Ordenações específicas
    usort($result['hazard'], fn($a, $b) => $b['confidence'] <=> $a['confidence']);
    usort($result['jam'], fn($a, $b) => $b['confidence'] <=> $a['confidence']);

    return $result;
}

// Coleta de métricas
$metrics = [];

// Execução otimizada
// Correção na chamada da função (linha 121)
$combinedAlerts = measurePerformance(
    function() use ($pdo, $id_parceiro) {
        return getCombinedAlerts($pdo, $id_parceiro);
    }
);
$metrics['combinedAlerts'] = $combinedAlerts['metrics'];

$processedAlerts = measurePerformance('processAlerts', $combinedAlerts['result']);
$metrics['processedAlerts'] = $processedAlerts['metrics'];

// Dados finais
$data = $processedAlerts['result'];
$data['traficdata'] = measurePerformance('getTrafficData', $pdo, $id_parceiro);
$data['activeAlertsToday'] = measurePerformance('getActiveAlertsToday', $pdo, $id_parceiro);
$data['totalAlertsThisMonth'] = measurePerformance('getTotalAlertsThisMonth', $pdo, $id_parceiro);

// Geração de relatório
error_log(json_encode([
    'total_execution_time' => round((microtime(true) - $start) * 1000, 2) . 'ms',
    'memory_peak' => round(memory_get_peak_usage() / 1024 / 1024, 2) . 'MB',
    'metrics' => $metrics
]));

ob_end_flush();