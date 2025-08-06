<?php

$startTime = microtime(true);

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/debug.log');

require_once __DIR__ . '/vendor/autoload.php';
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
echo "Horário de referência: $currentDateTime" . PHP_EOL;

if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] == 'true') {
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/debug.log');
    if (!is_dir(__DIR__ . '/../logs')) {
        mkdir(__DIR__ . '/../logs', 0777, true);
    }
}

set_time_limit(30); // Define o tempo máximo de execução do script

require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';
require_once __DIR__ . '/config/configs.php';

function getUrlsFromDb(PDO $pdo)
{
    $stmt = $pdo->query("SELECT url, id_parceiro FROM urls_alerts");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchAlertsFromApi($url)
{
    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        if ($response === false) {
            throw new Exception("Erro cURL: " . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, true);
    } catch (Exception $e) {
        echo "Erro ao buscar dados da API ($url): " . $e->getMessage() . PHP_EOL;
        return null;
    }
}

function alertChanged($existing, $new)
{
    $fields = ['country', 'city', 'reportRating', 'reportByMunicipalityUser', 'confidence', 'reliability', 'type', 'roadType', 'magvar', 'subtype', 'street', 'location_x', 'location_y', 'pubMillis'];
    foreach ($fields as $field) {
        $dbVal = $existing[$field] ?? null;
        $newVal = $new[$field] ?? null;
        if ($dbVal != $newVal)
            return true;
    }
    return false;
}

function saveAlertsToDb(PDO $pdo, array $alerts, $url, $id_parceiro)
{
    global $currentDateTime;
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT * FROM alerts WHERE source_url = ? AND status = 1");
        $stmt->execute([$url]);
        $existingAlerts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existingAlerts[$row['uuid']] = $row;
        }

        $stmtInsertUpdate = $pdo->prepare("INSERT INTO alerts (
            uuid, country, city, reportRating, reportByMunicipalityUser, confidence,
            reliability, type, roadType, magvar, subtype, street, location_x, location_y, pubMillis,
            status, source_url, date_received, date_updated, km, id_parceiro
        ) VALUES (
            :uuid, :country, :city, :reportRating, :reportByMunicipalityUser, :confidence,
            :reliability, :type, :roadType, :magvar, :subtype, :street, :location_x, :location_y, :pubMillis,
            1, :source_url, :date_received, :date_updated, :km, :id_parceiro
        ) ON DUPLICATE KEY UPDATE
            country = VALUES(country), city = VALUES(city), reportRating = VALUES(reportRating),
            reportByMunicipalityUser = VALUES(reportByMunicipalityUser), confidence = VALUES(confidence),
            reliability = VALUES(reliability), type = VALUES(type), roadType = VALUES(roadType),
            magvar = VALUES(magvar), subtype = VALUES(subtype), street = VALUES(street),
            location_x = VALUES(location_x), location_y = VALUES(location_y), pubMillis = VALUES(pubMillis),
            status = 1, date_updated = VALUES(date_updated), km = VALUES(km), id_parceiro = VALUES(id_parceiro)");

        $deviceToken = 'fec20e76-c481-4316-966d-c09798ae0d95';
        $authToken = 'your-auth-token';
        $numero = '5531971408208';

        $incomingUuids = [];

        foreach ($alerts as $alert) {
            if (!isset($alert['location']['x'], $alert['location']['y']))
                continue;

            $uuid = $alert['uuid'];
            $incomingUuids[] = $uuid;
            $km = null;

            $flatAlert = [
                'uuid' => $uuid,
                'country' => $alert['country'] ?? null,
                'city' => $alert['city'] ?? null,
                'reportRating' => $alert['reportRating'] ?? null,
                'reportByMunicipalityUser' => $alert['reportByMunicipalityUser'] ?? null,
                'confidence' => $alert['confidence'] ?? null,
                'reliability' => $alert['reliability'] ?? null,
                'type' => $alert['type'] ?? null,
                'roadType' => $alert['roadType'] ?? null,
                'magvar' => $alert['magvar'] ?? null,
                'subtype' => $alert['subtype'] ?? null,
                'street' => $alert['street'] ?? null,
                'location_x' => $alert['location']['x'],
                'location_y' => $alert['location']['y'],
                'pubMillis' => $alert['pubMillis'] ?? null
            ];

            $isNew = !isset($existingAlerts[$uuid]);
            $shouldUpdate = $isNew || alertChanged($existingAlerts[$uuid], $flatAlert);

            if ($shouldUpdate) {
                $stmtInsertUpdate->execute([
                    ':uuid' => $flatAlert['uuid'],
                    ':country' => $flatAlert['country'],
                    ':city' => $flatAlert['city'],
                    ':reportRating' => $flatAlert['reportRating'],
                    ':reportByMunicipalityUser' => $flatAlert['reportByMunicipalityUser'],
                    ':confidence' => $flatAlert['confidence'],
                    ':reliability' => $flatAlert['reliability'],
                    ':type' => $flatAlert['type'],
                    ':roadType' => $flatAlert['roadType'],
                    ':magvar' => $flatAlert['magvar'],
                    ':subtype' => $flatAlert['subtype'],
                    ':street' => $flatAlert['street'],
                    ':location_x' => $flatAlert['location_x'],
                    ':location_y' => $flatAlert['location_y'],
                    ':pubMillis' => $flatAlert['pubMillis'],
                    ':source_url' => $url,
                    ':date_received' => $currentDateTime,
                    ':date_updated' => $currentDateTime,
                    ':km' => $km,
                    ':id_parceiro' => $id_parceiro
                ]);
                echo $isNew ? "Novo alerta: $uuid\n" : "Atualizado alerta: $uuid\n";
            }

            if ($isNew && $flatAlert['type'] === 'ACCIDENT' && $id_parceiro == 2) {
                enviarNotificacaoPush($deviceToken, $authToken, $numero, $alert);
            }
        }

        $stmtDeactivate = $pdo->prepare("UPDATE alerts SET status = 0, date_updated = ? WHERE uuid = ? AND source_url = ?");
        foreach (array_keys($existingAlerts) as $uuid) {
            if (!in_array($uuid, $incomingUuids)) {
                $stmtDeactivate->execute([$currentDateTime, $uuid, $url]);
                echo "Alerta desativado: $uuid\n";
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function processAlerts()
{
    $pdo = Database::getConnection();
    $urls = getUrlsFromDb($pdo);

    foreach ($urls as $entry) {
        $url = $entry['url'];
        $id_parceiro = $entry['id_parceiro'];

        echo PHP_EOL . "Processando URL: $url" . PHP_EOL;
        $startUrl = microtime(true);

        $jsonData = fetchAlertsFromApi($url);

        if ($jsonData && !empty($jsonData['alerts'])) {
            try {
                $startAlerts = microtime(true);
                saveAlertsToDb($pdo, $jsonData['alerts'], $url, $id_parceiro);
                $endAlerts = microtime(true);
                echo "Tempo salvar alertas: " . round($endAlerts - $startAlerts, 2) . " segundos" . PHP_EOL;
            } catch (Exception $e) {
                echo "Erro ao processar alertas: " . $e->getMessage() . PHP_EOL;
            }
        }

        $endUrl = microtime(true);
        echo "Tempo total da URL: " . round($endUrl - $startUrl, 2) . " segundos" . PHP_EOL;
    }
}

echo "Iniciando o processo de atualização dos alertas..." . PHP_EOL;
processAlerts();
echo "Processamento concluído!" . PHP_EOL;

$endTime = microtime(true);
$totalTime = $endTime - $startTime;
echo "Tempo total de execução: " . round($totalTime, 2) . " segundos" . PHP_EOL;
