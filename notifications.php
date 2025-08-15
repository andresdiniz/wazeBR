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

// 1. Buscar todos os alertas pendentes e ativos
try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();
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

    // 2. Buscar usuários relevantes de uma vez
    $sqlUsuarios = "
        SELECT u.id AS user_id, u.email, u.phone_number, p.id_parceiro, p.type, p.subtype,
               p.receive_email, p.receive_sms, p.receive_whatsapp
        FROM user_notification_preferences p
        JOIN users u ON p.id_user = u.id
    ";
    $stmtUsuarios = $pdo->query($sqlUsuarios);
    $usuariosTodos = $stmtUsuarios->fetchAll();

    // Agrupar usuários por parceiro e tipo/subtipo
    $usuariosPorChave = [];
    foreach ($usuariosTodos as $u) {
        $key = $u['id_parceiro'].'|'.$u['type'].'|'.($u['subtype'] ?? '');
        $usuariosPorChave[$key][] = $u;
    }

    $pdo->commit();
} catch (\Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Erro: " . $e->getMessage());
}

// 3. Preparar inserções de fila em lote
$insertsFilaEnvio = [];

foreach ($filaPendentes as $alerta) {
    $keyExato = $alerta['id_parceiro'].'|'.$alerta['alert_type'].'|'.($alerta['alert_subtype'] ?? '');
    $keyGenerico = $alerta['id_parceiro'].'|'.$alerta['alert_type'].'|';

    $usuariosAlvo = array_merge(
        $usuariosPorChave[$keyExato] ?? [],
        $usuariosPorChave[$keyGenerico] ?? []
    );

    foreach ($usuariosAlvo as $usuario) {
        $insertsFilaEnvio[] = [
            'fila_id' => $alerta['fila_id'],
            'uuid_allert' => $alerta['uuid_alerta'],// uuid_allert pode ser nulo se não for usado
            'user_id' => $usuario['user_id'],
            'email'   => $usuario['receive_email'] ? $usuario['email'] : null,
            'phone'   => ($usuario['receive_sms'] || $usuario['receive_whatsapp']) ? $usuario['phone_number'] : null,
            'data_criacao' => $currentDateTime  // <-- data/hora do PHP
        ];
    }
}

// 4. Inserir todas as filas de envio de uma vez
try {
    $pdo->beginTransaction();

    $sqlInsert = "INSERT INTO fila_envio_detalhes (fila_id, uuid_allert, user_id, email, phone, status_envio, data_criacao) VALUES (?,?, ?, ?, ?, 'PENDENTE', ?)";
    $stmtInsert = $pdo->prepare($sqlInsert);

    foreach ($insertsFilaEnvio as $insert) {
        $stmtInsert->execute([
            $insert['fila_id'], 
            $insert['uuid_allert'], // uuid_allert pode ser nulo se não for usado
            $insert['user_id'], 
            $insert['email'], 
            $insert['phone'], 
            $insert['data_criacao']
        ]);
    }

    $pdo->commit();
} catch (\Exception $e) {
    $pdo->rollBack();
    die("Erro ao inserir filas de envio: ".$e->getMessage());
}

echo "Fila de envios criada com sucesso. Agora processe os envios via worker separado.\n";
