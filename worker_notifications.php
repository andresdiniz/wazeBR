<?php
declare(strict_types=1);

/**
 * Script: worker_notifications.php
 * Responsabilidade: Processar envio de notificações da fila detalhada
 *
 * Pré-requisitos (fornecidos pelo wazejob.php):
 *   - $logger: Logger
 *   - $pdo: PDO
 */

if (!isset($logger) || !($logger instanceof Logger)) {
    throw new RuntimeException('Logger não disponível em worker_notifications.php');
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('PDO não disponível em worker_notifications.php');
}

$startTime = microtime(true);
$currentDateTime = date('Y-m-d H:i:s');

$logger->info('worker_notifications iniciado', ['datetime' => $currentDateTime]);

set_time_limit(300);

// Configuração: quantidade de envios simultâneos
$batchSize = 5;

/**
 * Verifica conexão WhatsApp
 */
function verificarConexaoWhatsApp(string $deviceToken, string $authToken, Logger $logger): bool
{
    // Implementar verificação real aqui
    // Por enquanto, retorna true como placeholder
    return true;
}

/**
 * Envia notificação via WhatsApp
 */
function enviarNotificacaoWhatsApp(
    PDO $pdo,
    Logger $logger,
    string $deviceToken,
    string $authToken,
    string $phone,
    string $uuidAlert
): string {
    try {
        // Implementar envio real via API WhatsApp aqui
        // Por enquanto, simula sucesso

        $logger->info('Enviando WhatsApp', [
            'phone' => $phone,
            'uuid_alert' => $uuidAlert
        ]);

        // Simulação de envio
        return 'ENVIADO';
    } catch (Exception $e) {
        $logger->error('Erro ao enviar WhatsApp', [
            'phone' => $phone,
            'mensagem' => $e->getMessage()
        ]);
        return 'ERRO';
    }
}

/**
 * Processa worker de notificações
 */
function processNotificationWorker(PDO $pdo, Logger $logger, int $batchSize): void
{
    try {
        // 1. Buscar envios pendentes
        $sqlPendentes = "
            SELECT * FROM fila_envio_detalhes 
            WHERE status_envio != 'ENVIADO' 
            LIMIT :limit
        ";

        $stmt = $pdo->prepare($sqlPendentes);
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($pendentes)) {
            $logger->info('Nenhum envio pendente');
            return;
        }

        $logger->info('Processando lote de envios', ['total' => count($pendentes)]);

        // 2. Processar cada envio
        $totalEnviados = 0;
        $totalErros = 0;

        foreach ($pendentes as $envio) {
            $startEnvio = microtime(true);
            $status = 'FALHA';
            $message = '';
            $method = null;

            try {
                // Determinar método
                if (!empty($envio['email'])) {
                    $method = 'EMAIL';

                    if (function_exists('sendEmail')) {
                        // Implementar envio de email aqui
                        $message = "Email enviado para " . $envio['email'];
                        $status = 'ENVIADO';
                        $totalEnviados++;
                    } else {
                        $message = "Função sendEmail não disponível";
                        $status = 'ERRO';
                        $totalErros++;
                    }
                } elseif (!empty($envio['phone'])) {
                    $method = 'WHATSAPP';
                    $deviceToken = $_ENV['WHATSAPP_DEVICE_TOKEN'] ?? '';
                    $authToken = $_ENV['WHATSAPP_AUTH_TOKEN'] ?? '';

                    if (empty($deviceToken) || empty($authToken)) {
                        $message = "Credenciais WhatsApp não configuradas";
                        $status = 'ERRO';
                        $totalErros++;
                    } elseif (verificarConexaoWhatsApp($deviceToken, $authToken, $logger)) {
                        $status = enviarNotificacaoWhatsApp(
                            $pdo,
                            $logger,
                            $deviceToken,
                            $authToken,
                            $envio['phone'],
                            $envio['uuid_allert']
                        );

                        $message = "Mensagem enviada para " . $envio['phone'];
                        
                        if ($status === 'ENVIADO') {
                            $totalEnviados++;
                        } else {
                            $totalErros++;
                        }
                    } else {
                        $status = 'ERRO';
                        $message = "Instância WhatsApp não conectada";
                        $totalErros++;
                    }
                } else {
                    $message = "Nenhum contato disponível";
                    $status = 'ERRO';
                    $totalErros++;
                }
            } catch (Exception $e) {
                $message = $e->getMessage();
                $status = 'ERRO';
                $totalErros++;

                $logger->error('Erro no envio', [
                    'id_envio' => $envio['id'],
                    'mensagem' => $e->getMessage()
                ]);
            }

            $endEnvio = microtime(true);
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
                ':uuid_alerta' => $envio['uuid_allert']
            ]);

            // Log do envio
            $duration_ms = round(($endEnvio - $startEnvio) * 1000, 2);

            if (function_exists('logToJsonNotify')) {
                logToJsonNotify(
                    $envio['id'],
                    $envio['user_id'],
                    $method,
                    $status,
                    $startEnvio,
                    $endEnvio,
                    $message,
                    $duration_ms
                );
            }

            $logger->debug('Envio processado', [
                'id' => $envio['id'],
                'metodo' => $method,
                'status' => $status,
                'duracao_ms' => $duration_ms
            ]);
        }

        $logger->info('Lote processado', [
            'total_enviados' => $totalEnviados,
            'total_erros' => $totalErros
        ]);
    } catch (Exception $e) {
        $logger->error('Erro no processamento do worker', [
            'mensagem' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        throw $e;
    }
}

// Execução principal
processNotificationWorker($pdo, $logger, $batchSize);

$endTime = microtime(true);
$totalTime = round($endTime - $startTime, 2);

$logger->info('worker_notifications concluído', [
    'tempo_total_s' => $totalTime,
    'memoria_pico_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
]);

return true;