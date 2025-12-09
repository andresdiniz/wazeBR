<?php
declare(strict_types=1);

/**
 * Script: notifications.php
 * Responsabilidade: Preparar fila de notificações detalhada
 *
 * Pré-requisitos (fornecidos pelo wazejob.php):
 *   - $logger: Logger
 *   - $pdo: PDO
 */

if (!isset($logger) || !($logger instanceof Logger)) {
    throw new RuntimeException('Logger não disponível em notifications.php');
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('PDO não disponível em notifications.php');
}

$startTime = microtime(true);
$currentDateTime = date('Y-m-d H:i:s');

$logger->info('notifications iniciado', ['datetime' => $currentDateTime]);

set_time_limit(300);

/**
 * Processa fila de notificações
 */
function processNotificationQueue(PDO $pdo, Logger $logger, string $currentDateTime): void
{
    try {
        $pdo->beginTransaction();

        // 1. Buscar alertas pendentes
        $sqlFila = "
            SELECT f.id AS fila_id, f.uuid_alerta, f.id_parceiro, 
                   a.type AS alert_type, a.subtype AS alert_subtype,
                   a.street, a.city, a.country
            FROM fila_envio f
            JOIN alerts a ON f.uuid_alerta = a.uuid
            WHERE f.enviado = 0 AND a.status = 1
            FOR UPDATE
        ";

        $stmtFila = $pdo->query($sqlFila);
        $filaPendentes = $stmtFila->fetchAll(PDO::FETCH_ASSOC);

        if (empty($filaPendentes)) {
            $logger->info('Não há alertas pendentes');
            $pdo->commit();
            return;
        }

        $logger->info('Alertas pendentes encontrados', ['total' => count($filaPendentes)]);

        // 2. Buscar usuários relevantes
        $sqlUsuarios = "
            SELECT u.id AS user_id, u.email, u.phone_number,
                   p.id_parceiro, p.type, p.subtype,
                   p.receive_email, p.receive_sms, p.receive_whatsapp,
                   p.email AS pref_email, p.phone_number AS pref_phone
            FROM user_notification_preferences p
            JOIN users u ON p.id_user = u.id
        ";

        $stmtUsuarios = $pdo->query($sqlUsuarios);
        $usuariosTodos = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);

        // Agrupar usuários por chave
        $usuariosPorChave = [];
        foreach ($usuariosTodos as $u) {
            $key = $u['id_parceiro'] . '|' . $u['type'] . '|' . ($u['subtype'] ?? '');
            $usuariosPorChave[$key][] = $u;
        }

        // 2.1 Buscar pares já existentes
        $stmtExistentes = $pdo->query("SELECT uuid_allert, user_id, metodo FROM fila_envio_detalhes");
        $paresExistentes = [];

        foreach ($stmtExistentes->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $paresExistentes[$row['uuid_allert']][$row['user_id'] . '|' . $row['metodo']] = true;
        }

        $pdo->commit();

        // 3. Preparar inserções
        $insertsFilaEnvio = [];

        foreach ($filaPendentes as $alerta) {
            $keyExato = $alerta['id_parceiro'] . '|' . $alerta['alert_type'] . '|' . ($alerta['alert_subtype'] ?? '');
            $keyGenerico = $alerta['id_parceiro'] . '|' . $alerta['alert_type'] . '|';

            $usuariosAlvo = array_merge(
                $usuariosPorChave[$keyExato] ?? [],
                $usuariosPorChave[$keyGenerico] ?? []
            );

            foreach ($usuariosAlvo as $usuario) {
                if ($usuario['user_id'] === null) continue;

                $email = $usuario['pref_email'] ?: $usuario['email'];
                $phone = $usuario['pref_phone'] ?: $usuario['phone_number'];

                // EMAIL
                if ($usuario['receive_email'] && !empty($email)) {
                    $chave = $usuario['user_id'] . '|EMAIL';
                    if (!isset($paresExistentes[$alerta['uuid_alerta']][$chave])) {
                        $insertsFilaEnvio[] = [
                            'fila_id' => $alerta['fila_id'],
                            'uuid_allert' => $alerta['uuid_alerta'],
                            'user_id' => $usuario['user_id'],
                            'email' => $email,
                            'phone' => null,
                            'metodo' => 'EMAIL',
                            'data_criacao' => $currentDateTime
                        ];
                        $paresExistentes[$alerta['uuid_alerta']][$chave] = true;
                    }
                }

                // SMS
                if ($usuario['receive_sms'] && !empty($phone)) {
                    $chave = $usuario['user_id'] . '|SMS';
                    if (!isset($paresExistentes[$alerta['uuid_alerta']][$chave])) {
                        $insertsFilaEnvio[] = [
                            'fila_id' => $alerta['fila_id'],
                            'uuid_allert' => $alerta['uuid_alerta'],
                            'user_id' => $usuario['user_id'],
                            'email' => null,
                            'phone' => $phone,
                            'metodo' => 'SMS',
                            'data_criacao' => $currentDateTime
                        ];
                        $paresExistentes[$alerta['uuid_alerta']][$chave] = true;
                    }
                }

                // WHATSAPP
                if ($usuario['receive_whatsapp'] && !empty($phone)) {
                    $chave = $usuario['user_id'] . '|WHATSAPP';
                    if (!isset($paresExistentes[$alerta['uuid_alerta']][$chave])) {
                        $insertsFilaEnvio[] = [
                            'fila_id' => $alerta['fila_id'],
                            'uuid_allert' => $alerta['uuid_alerta'],
                            'user_id' => $usuario['user_id'],
                            'email' => null,
                            'phone' => $phone,
                            'metodo' => 'WHATSAPP',
                            'data_criacao' => $currentDateTime
                        ];
                        $paresExistentes[$alerta['uuid_alerta']][$chave] = true;
                    }
                }
            }
        }

        // 4. Inserção em bulk
        if (!empty($insertsFilaEnvio)) {
            $pdo->beginTransaction();

            try {
                $values = [];
                $placeholders = [];

                foreach ($insertsFilaEnvio as $insert) {
                    $placeholders[] = "(?, ?, ?, ?, ?, ?, 'PENDENTE', ?)";
                    $values[] = $insert['fila_id'];
                    $values[] = $insert['uuid_allert'];
                    $values[] = $insert['user_id'];
                    $values[] = $insert['email'];
                    $values[] = $insert['phone'];
                    $values[] = $insert['metodo'];
                    $values[] = $insert['data_criacao'];
                }

                $sqlInsert = "
                    INSERT INTO fila_envio_detalhes
                        (fila_id, uuid_allert, user_id, email, phone, metodo, status_envio, data_criacao)
                    VALUES " . implode(', ', $placeholders);

                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute($values);

                // 5. Atualizar status da fila_envio
                $uuidsAlertas = array_unique(array_column($insertsFilaEnvio, 'uuid_allert'));

                $sqlUpdate = "
                    UPDATE fila_envio
                    SET status_envio = 'FILA', enviado = 1
                    WHERE uuid_alerta = ?
                ";
                $stmtUpdate = $pdo->prepare($sqlUpdate);

                foreach ($uuidsAlertas as $uuid) {
                    $stmtUpdate->execute([$uuid]);
                }

                $pdo->commit();

                $logger->info('Fila de envios criada', [
                    'total_registros' => count($insertsFilaEnvio)
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                $logger->error('Erro ao inserir filas de envio', [
                    'mensagem' => $e->getMessage()
                ]);
                throw $e;
            }
        } else {
            $logger->info('Nenhum usuário válido encontrado para enviar alertas');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $logger->error('Erro ao processar fila de notificações', [
            'mensagem' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        throw $e;
    }
}

// Execução principal
processNotificationQueue($pdo, $logger, $currentDateTime);

$endTime = microtime(true);
$totalTime = round($endTime - $startTime, 2);

$logger->info('notifications concluído', [
    'tempo_total_s' => $totalTime,
    'memoria_pico_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
]);

return true;