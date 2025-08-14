<?php

$startScriptTime = microtime(true);

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

date_default_timezone_set('America/Sao_Paulo');

$pdo = Database::getConnection();

// Configuração: quantidade de envios simultâneos
$batchSize = 5;

try {
    // 1. Buscar envios pendentes
    $sqlPendentes = "SELECT * FROM fila_envio_detalhes WHERE status_envio = 'PENDENTE' LIMIT :limit";
    $stmt = $pdo->prepare($sqlPendentes);
    $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $pendentes = $stmt->fetchAll();

    if (!$pendentes) {
        echo "Nenhum envio pendente.\n";
        exit;
    }

    // 2. Processar cada envio
    foreach ($pendentes as $envio) {
        $startTime = microtime(true);
        $status = 'FALHA';
        $message = '';
        $method = null;

        try {
            // Determinar qual método
            if (!empty($envio['email'])) {
                $method = 'EMAIL';
                // Aqui você chamaria a função real de envio de email
                $message = "Email enviado para " . $envio['email'];
            } elseif (!empty($envio['phone'])) {
                $method = 'WHATSAPP'; // ou SMS
                // Aqui você chamaria a API de WhatsApp/SMS
                $message = "Mensagem enviada para " . $envio['phone'];
            } else {
                $message = "Nenhum contato disponível";
            }

            // Simular envio (remover em produção)
            usleep(500000); // 0,5 segundos para simular delay

            $status = 'ENVIADO';
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $status = 'FALHA';
        }

        $endTime = microtime(true);

        // Atualizar status no banco
        $sqlUpdate = "UPDATE fila_envio_detalhes 
                      SET status_envio = :status, metodo = :metodo, data_atualizacao = NOW() 
                      WHERE id = :id";
        $stmtUpdate = $pdo->prepare($sqlUpdate);
        $stmtUpdate->execute([
            ':status' => $status,
            ':metodo' => $method,
            ':id' => $envio['id']
        ]);

        // Log do envio
        $duration_ms = is_numeric($endTime) && is_numeric($startTime)
            ? round(($endTime - $startTime) * 1000, 2)
            : 0;

        logToJsonNotify(
            $envio['id'],         // alertId
            $envio['user_id'],    // userId
            $method,              // method
            $status,              // status
            $startTime,           // startTime
            $endTime,             // endTime
            $message,             // message
            $duration_ms          // duration_ms opcional se quiser adicionar no log
        );
    }

    $totalScriptTime = round((microtime(true) - $startScriptTime) * 1000, 2);
    echo "Processamento do lote concluído em {$totalScriptTime} ms.\n";

} catch (\Exception $e) {
    error_log("Erro no processamento do worker: " . $e->getMessage());
    die("Erro no processamento do worker: " . $e->getMessage());
}
