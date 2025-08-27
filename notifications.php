<?php

$startTime = microtime(true);

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
$currentDateTime = date('Y-m-d H:i:s');

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();

    // 1. Buscar todos os alertas pendentes e ativos
    $sqlFila = "
        SELECT f.id AS fila_id, f.uuid_alerta, f.id_parceiro, a.type AS alert_type, a.subtype AS alert_subtype,
               a.street, a.city, a.country
        FROM fila_envio f
        JOIN alerts a ON f.uuid_alerta = a.uuid
        WHERE f.enviado = 0 AND a.status = 1
        FOR UPDATE
    ";
    $stmtFila = $pdo->query($sqlFila);
    $filaPendentes = $stmtFila->fetchAll();

    if (!$filaPendentes) {
        echo "Não há alertas pendentes.\n";
        $pdo->commit();
        exit;
    }

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
    $usuariosTodos = $stmtUsuarios->fetchAll();

    // Agrupar usuários por parceiro, type e subtype
    $usuariosPorChave = [];
    foreach ($usuariosTodos as $u) {
        $key = $u['id_parceiro'] . '|' . $u['type'] . '|' . ($u['subtype'] ?? '');
        $usuariosPorChave[$key][] = $u;
    }

    // 2.1 Buscar pares já existentes na fila_envio_detalhes
    $stmtExistentes = $pdo->query("SELECT uuid_allert, user_id, metodo FROM fila_envio_detalhes");
    $paresExistentes = [];
    foreach ($stmtExistentes->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $paresExistentes[$row['uuid_allert']][$row['user_id'].'|'.$row['metodo']] = true;
    }

    $pdo->commit();
} catch (\Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("Erro: " . $e->getMessage());
}

// 3. Preparar array de inserção na fila detalhada
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

        // Preferência: usar email/phone da preferences, senão do user
        $email = $usuario['pref_email'] ?: $usuario['email'];
        $phone = $usuario['pref_phone'] ?: $usuario['phone_number'];

        // EMAIL
        if ($usuario['receive_email'] && !empty($email)) {
            $chave = $usuario['user_id'].'|EMAIL';
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
            $chave = $usuario['user_id'].'|SMS';
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
            $chave = $usuario['user_id'].'|WHATSAPP';
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

// 4. Inserção em bulk na tabela fila_envio_detalhes
if (!empty($insertsFilaEnvio)) {
    try {
        $pdo->beginTransaction();

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

        // 5. Atualizar status da fila_envio para FILA
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

        echo "Fila de envios criada com sucesso para " . count($insertsFilaEnvio) . " registros (usuários × métodos).\n";
    } catch (\Exception $e) {
        $pdo->rollBack();
        die("Erro ao inserir filas de envio: " . $e->getMessage());
    }
} else {
    echo "Nenhum usuário válido encontrado para enviar os alertas.\n";
}

$endTime = microtime(true);
$duration = round($endTime - $startTime, 2);
echo "Processamento finalizado em {$duration} segundos.\n";
