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
        $status = 'ERRO';
        $message = '';
        $method = null;

        try {
            // Determinar qual método
            if (!empty($envio['email'])) {
                $method = 'EMAIL';
                $message = "Email enviado para " . $envio['email'];
            } elseif (!empty($envio['phone'])) {
                $method = 'WHATSAPP';
                $deviceToken = 'fec20e76-c481-4316-966d-c09798ae0d95';
                        $authToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczovL3BsYXRhZm9ybWEuYXBpYnJhc2lsLmNvbS5ici9hdXRoL2NhbGxiYWNrIiwiaWF0IjoxNzUzMTczMzE4LCJleHAiOjE3ODQ3MDkzMTgsIm5iZiI6MTc1MzE3MzMxOCwianRpIjoia1pUMFBrWEJoRHA1Q0NPbSIsInN1YiI6Ijg1MiIsInBydiI6IjIzYmQ1Yzg5NDlmNjAwYWRiMzllNzAxYzQwMDg3MmRiN2E1OTc2ZjcifQ.opUGRf8f1unfjS_oJtChpoUv8Q0yYGNJChyQ8xoD5Bs';
                        // Lista de números para enviar
                enviarNotificacaoWhatsApp($pdo, $deviceToken, $authToken, [$envio['phone']], $envio['id']);
                $message = "Mensagem enviada para " . $envio['phone'];
            } else {
                $message = "Nenhum contato disponível";
            }

            // Simular envio
            usleep(500000);
            $status = 'ENVIADO';
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $status = 'ERRO';
        }

        $endTime = microtime(true);

        // Data/hora PHP
        $dataAtualizacao = date('Y-m-d H:i:s');

        // Atualizar fila_envio_detalhes
        $stmtUpdateDetalhes = $pdo->prepare("
        UPDATE fila_envio_detalhes 
        SET status_envio = :status, 
            metodo = :metodo, 
            data_atualizacao = :data_atualizacao 
        WHERE id = :id
    ");
        $stmtUpdateDetalhes->execute([
            ':status' => $status,
            ':metodo' => $method,
            ':data_atualizacao' => $dataAtualizacao,
            ':id' => $envio['id']
        ]);

        // Atualizar fila_envio principal
        $stmtUpdateFila = $pdo->prepare("
        UPDATE fila_envio
        SET status_envio = :status,
            data_envio = :data_envio,
            mensagem_erro = :mensagem_erro,
            enviado = :enviado
        WHERE uuid_alerta = :uuid_alerta
    ");

        $stmtUpdateFila->execute([
            ':status' => $status,
            ':data_envio' => $dataAtualizacao,
            ':mensagem_erro' => ($status === 'ERRO') ? $message : null,
            ':enviado' => ($status === 'ENVIADO') ? 1 : 0,
            ':uuid_alerta' => $envio['id']
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
            $duration_ms          // duration_ms
        );
    }

    $totalScriptTime = round((microtime(true) - $startScriptTime) * 1000, 2);
    echo "Processamento do lote concluído em {$totalScriptTime} ms.\n";

} catch (\Exception $e) {
    error_log("Erro no processamento do worker: " . $e->getMessage());
    die("Erro no processamento do worker: " . $e->getMessage());
}
