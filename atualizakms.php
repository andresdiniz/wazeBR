<?php
$startTime = microtime(true);
ini_set('display_errors', 1);
 ini_set('log_errors', 1);
  ini_set('error_log', __DIR__ . '/../logs/debug.log');
   require_once __DIR__ . '/vendor/autoload.php'; 
   require_once __DIR__ . '/config/configbd.php';
    require_once __DIR__ . '/functions/scripts.php'; 
    require_once __DIR__ . '/config/configs.php';
     use Dotenv\Dotenv; $envPath = __DIR__ . '/.env'; if (!file_exists($envPath)) { die("Arquivo .env não encontrado no caminho: $envPath"); } try { $dotenv = Dotenv::createImmutable(__DIR__); $dotenv->load(); } catch (Exception $e) { error_log("Erro ao carregar o .env: " . $e->getMessage()); logEmail("error", "Erro ao carregar o .env: " . $e->getMessage()); die("Erro ao carregar o .env: " . $e->getMessage()); } global $currentDateTime; $pdo->beginTransaction();
try {
    // Consulta inicial para pegar alerts com km null e id_parceiro = 2
    $stmt = $pdo->prepare("
        SELECT uuid, location_x, location_y 
        FROM alerts 
        WHERE id_parceiro = 2 AND km IS NULL 
        ORDER BY uuid ASC
    ");

    $stmt->execute();

    // Processar em lotes para não sobrecarregar memória
    $batchSize = 1000;
    $alerts = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $alerts[] = $row;

        if (count($alerts) >= $batchSize) {
            atualizarKm($alerts, $pdo);
            $alerts = []; // limpa o batch
        }
    }

    // Processar o restante
    if (!empty($alerts)) {
        atualizarKm($alerts, $pdo);
    }

    $pdo->commit();
    echo "Processo finalizado com sucesso.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Erro no processamento: " . $e->getMessage());
    die("Erro: " . $e->getMessage());
}

/**
 * Função para atualizar km no banco
 */
function atualizarKm(array $alerts, PDO $pdo) {
    $updateStmt = $pdo->prepare("UPDATE alerts SET km = :km WHERE uuid = :uuid");

    foreach ($alerts as $alert) {
        $limiteKm = 2; // limite em km
        $km = encontrarKmPorCoordenadasEPR($alert['location_y'], $alert['location_x'], $limiteKm);

        if ($km !== null) {
            $updateStmt->execute([
                ':km' => $km,
                ':uuid' => $alert['uuid']
            ]);
        }
    }
}
