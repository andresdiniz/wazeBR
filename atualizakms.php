<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/debug.log');

$startTimeTotal = microtime(true);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';
require_once __DIR__ . '/config/configs.php';

use Dotenv\Dotenv;

// Carrega .env
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

    // Seleciona todos os alerts sem km do parceiro 2
    $stmt = $pdo->prepare("
        SELECT uuid, location_x, location_y 
        FROM alerts 
        WHERE id_parceiro = 2 AND km IS NULL 
        ORDER BY uuid ASC
    ");
    $stmt->execute();

    $totalAtualizados = 0;

    // Prepara o update uma vez
    $updateStmt = $pdo->prepare("UPDATE alerts SET km = :km WHERE uuid = :uuid");

    // Processamento linha a linha
    while ($alert = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $startTimeAlerta = microtime(true);

        $limiteKm = 2;
        $km = encontrarKmPorCoordenadasEPR($alert['location_x'], $alert['location_y'], $limiteKm);

        // Debug
        echo "UUID: {$alert['uuid']} | KM calculado: " . ($km ?? 'NULL') . "\n";

        if ($km !== null) {
            try {
                $updateStmt->bindValue(':km', (float)$km, PDO::PARAM_STR);
                $updateStmt->bindValue(':uuid', $alert['uuid'], PDO::PARAM_STR);
                $updateStmt->execute();

                if ($updateStmt->rowCount() > 0) {
                    $totalAtualizados++;
                    echo "Atualizado UUID: {$alert['uuid']} com KM: $km\n";
                } else {
                    echo "Nenhuma linha afetada para UUID: {$alert['uuid']}\n";
                }
            } catch (Exception $e) {
                error_log("Erro ao atualizar uuid {$alert['uuid']}: " . $e->getMessage());
                echo "Erro ao atualizar uuid {$alert['uuid']}: " . $e->getMessage() . "\n";
            }
        }

        $tempoAlerta = microtime(true) - $startTimeAlerta;
        echo "Tempo do alerta: " . round($tempoAlerta, 4) . " segundos\n";
    }

    $pdo->commit();

    $tempoTotal = microtime(true) - $startTimeTotal;
    echo "\nProcesso finalizado com sucesso.\n";
    echo "Total de alertas atualizados: $totalAtualizados\n";
    echo "Tempo total de execução: " . round($tempoTotal, 2) . " segundos\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erro no processamento: " . $e->getMessage());
    die("Erro: " . $e->getMessage());
}
