<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(1200);

define('DEBUG', true); // Ative/desative logs de debug

require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';
require_once __DIR__ . '/functions/helpers.php';
require_once __DIR__ . '/functions/email_templates.php';

$pdo = Database::getConnection();

function fetchAllUrls($pdo)
{
    $stmt = $pdo->query("SELECT url, id_parceiro FROM urls");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchJsonData($url)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $jsonData = curl_exec($ch);

    if (curl_errno($ch)) {
        logError("Erro ao carregar os dados JSON de $url: " . curl_error($ch));
        return null;
    }
    curl_close($ch);

    return json_decode($jsonData, true);
}

function logDebug($message)
{
    if (DEBUG) {
        echo "[DEBUG] $message\n";
    }
}

function logError($message)
{
    error_log("[ERROR] $message");
}

function logInfo($message)
{
    echo "[INFO] $message\n";
}

function getUrlId($pdo, $url)
{
    $stmt = $pdo->prepare("SELECT id FROM urls WHERE url = :url");
    $stmt->execute([':url' => $url]);
    $data = $stmt->fetch();

    if ($data) {
        return $data['id'];
    } else {
        $stmtInsert = $pdo->prepare("INSERT INTO urls (url) VALUES (:url)");
        $stmtInsert->execute([':url' => $url]);
        return $pdo->lastInsertId();
    }
}

function deactivateAllIrregularities($pdo, $urlId)
{
    $stmt = $pdo->prepare("UPDATE irregularities SET is_active = 0 WHERE url_id = :url_id");
    $stmt->execute([':url_id' => $urlId]);
}

function processAll($pdo)
{
    $urls = fetchAllUrls($pdo);

    foreach ($urls as $row) {
        $url = $row['url'];
        $id_parceiro = $row['id_parceiro'];

        logInfo("Processando URL: $url");
        $data = fetchJsonData($url);

        if (!$data) {
            logError("JSON inválido de $url");
            continue;
        }

        try {
            $pdo->beginTransaction();

            $urlId = getUrlId($pdo, $url);
            $currentTime = date('Y-m-d H:i:s');

            if (!empty($data['usersOnJams'])) {
                processUsersOnJams($pdo, $data['usersOnJams'], $urlId, $id_parceiro, $currentTime);
            }

            if (!empty($data['routes'])) {
                processRoutes($pdo, $data['routes'], $urlId, $id_parceiro);
            }

            if (!empty($data['irregularities'])) {
                processIrregularities($pdo, $data['irregularities'], $urlId, $id_parceiro);
            } else {
                deactivateAllIrregularities($pdo, $urlId);
            }

            $pdo->commit();
            logInfo("Processamento concluído para $url\n");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logError("Erro no processamento da URL $url: " . $e->getMessage());
        }
    }
}

// Executa tudo
processAll($pdo);
