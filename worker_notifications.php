<?php
// Conexão PDO (mesma configuração do script anterior)
$host = '127.0.0.1';
$db   = 'u335174317_wazeportal';
$user = 'SEU_USUARIO';
$pass = 'SUA_SENHA';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

function logToJsonNotify($filaDetalheId, $method, $status, $startTime, $endTime, $message = '') {
    $logEntry = [
        'fila_detalhe_id' => $filaDetalheId,
        'method'   => $method,
        'status'   => $status,
        'start_time' => $startTime,
        'end_time'   => $endTime,
        'duration_ms'=> round(($endTime - $startTime) * 1000, 2),
        'message'  => $message
    ];
    file_put_contents('worker_log.json', json_encode($logEntry, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n", FILE_APPEND);
}

// Configuração: quantidade de envios simultâneos
$batchSize = 5;

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

    try {
        // Determinar qual método
        if (!empty($envio['email'])) {
            $method = 'EMAIL';
            // Aqui você chamaria a função real de envio de email
            $message = "Email enviado para ".$envio['email'];
        } elseif (!empty($envio['phone'])) {
            $method = 'WHATSAPP'; // ou SMS
            // Aqui você chamaria a API de WhatsApp/SMS
            $message = "Mensagem enviada para ".$envio['phone'];
        } else {
            $method = null;
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
    $sqlUpdate = "UPDATE fila_envio_detalhes SET status_envio = :status, metodo = :metodo, data_atualizacao = NOW() WHERE id = :id";
    $stmtUpdate = $pdo->prepare($sqlUpdate);
    $stmtUpdate->execute([
        ':status' => $status,
        ':metodo' => $method,
        ':id'     => $envio['id']
    ]);

    // Log do envio
    logToJsonNotifyNotify($envio['id'], $method, $status, $startTime, $endTime, $message);
}

echo "Processamento do lote concluído.\n";
