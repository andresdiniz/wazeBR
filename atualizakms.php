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

<?php
// ... (código anterior mantido)

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();

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

    $pdo->commit(); // Confirma as alterações no banco

    $tempoTotal = microtime(true) - $startTimeTotal;
    echo "Processo finalizado com sucesso.\n";
    echo "Total de alertas atualizados: $totalAtualizados\n";
    echo "Tempo total de execução: " . round($tempoTotal, 2) . " segundos\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erro no processamento: " . $e->getMessage());
    die("Erro: " . $e->getMessage());
}

/**
 * Função para atualizar km no banco
 */
function atualizarKm(array $alerts, PDO $pdo) {
    $updateStmt = $pdo->prepare("UPDATE alerts SET km = :km WHERE uuid = :uuid");
    $atualizados = 0;

    foreach ($alerts as $alert) {
        $startTime = microtime(true);

        $limiteKm = 2;
        $km = encontrarKmPorCoordenadasEPR($alert['location_y'], $alert['location_x'], $limiteKm);

        // Debug: Verifique se o KM está sendo calculado
        echo "UUID: {$alert['uuid']} | KM: " . ($km ?? 'NULL') . "\n";

        if ($km !== null) {
            try {
                $updateStmt->bindValue(':km', $km, PDO::PARAM_STR);
                $updateStmt->bindValue(':uuid', $alert['uuid'], PDO::PARAM_STR);
                $updateStmt->execute();

                // Verifica se a atualização afetou alguma linha
                if ($updateStmt->rowCount() > 0) {
                    $atualizados++;
                } else {
                    echo "Nenhuma linha afetada para UUID: {$alert['uuid']}\n";
                }
            } catch (Exception $e) {
                error_log("Erro ao atualizar uuid {$alert['uuid']}: " . $e->getMessage());
                echo "Erro ao atualizar uuid {$alert['uuid']}: " . $e->getMessage() . "\n";
            }
        }

        $tempoAlerta = microtime(true) - $startTime;
        echo "Tempo do alerta: " . round($tempoAlerta, 4) . " segundos\n";
    }

    return $atualizados;
}

?>