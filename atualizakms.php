<?php
$startTimeTotal = microtime(true);

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/debug.log');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';
require_once __DIR__ . '/config/configs.php';

use Dotenv\Dotenv;

$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    die("Arquivo .env não encontrado no caminho: $envPath");
}

try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    error_log("Erro ao carregar o .env: " . $e->getMessage());
    logEmail("error", "Erro ao carregar o .env: " . $e->getMessage());
    die("Erro ao carregar o .env: " . $e->getMessage());
}

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();

    // Consulta inicial para pegar alerts com km null e id_parceiro = 2
    $stmt = $pdo->prepare("
        SELECT uuid, location_x, location_y 
        FROM alerts 
        WHERE id_parceiro = 2 AND km IS NULL 
        ORDER BY uuid ASC
    ");
    $stmt->execute();

    $batchSize = 1000;
    $alerts = [];
    $totalAtualizados = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $alerts[] = $row;

        if (count($alerts) >= $batchSize) {
            $atualizados = atualizarKm($alerts, $pdo);
            $totalAtualizados += $atualizados;
            $alerts = [];
        }
    }

    if (!empty($alerts)) {
        $atualizados = atualizarKm($alerts, $pdo);
        $totalAtualizados += $atualizados;
    }

    $pdo->commit();

    $tempoTotal = microtime(true) - $startTimeTotal;
    echo "Processo finalizado com sucesso.\n";
    echo "Total de alertas atualizados: $totalAtualizados\n";
    echo "Tempo total de execução: " . round($tempoTotal, 2) . " segundos\n";

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Erro no processamento: " . $e->getMessage());
    die("Erro: " . $e->getMessage());
}

/**
 * Função para atualizar km no banco
 * Retorna quantidade de alertas atualizados
 */
function atualizarKm(array $alerts, PDO $pdo) {
    $updateStmt = $pdo->prepare("UPDATE alerts SET km = :km WHERE uuid = :uuid");
    $atualizados = 0;

    foreach ($alerts as $alert) {
        $startTime = microtime(true); // tempo individual do alerta

        $limiteKm = 2; // limite em km
        $km = encontrarKmPorCoordenadasEPR($alert['location_y'], $alert['location_x'], $limiteKm);
        echo $km;
        if ($km !== null) {
            try {
                $updateStmt->execute([
                    ':km' => $km,
                    ':uuid' => $alert['uuid']
                ]);
                $atualizados++;
            } catch (Exception $e) {
                error_log("Erro ao atualizar uuid {$alert['uuid']}: " . $e->getMessage());
                echo "Erro ao atualizar uuid {$alert['uuid']}: " . $e->getMessage() . "\n";
            }
        }

        $tempoAlerta = microtime(true) - $startTime;
        echo "UUID {$alert['uuid']} atualizado em " . round($tempoAlerta, 4) . " segundos\n";
    }

    return $atualizados;
}

