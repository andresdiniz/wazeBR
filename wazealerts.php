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
$logMessages = []; // Variável global para armazenar as mensagens de log

/**
 * Adiciona uma mensagem ao array de log global.
 * @param string $message A mensagem de log.
 * @param string $level O nível do log (ex: "info", "error", "warning").
 */

/**
 * Salva o array de logs em um arquivo JSON.
 * @param string $filePath O caminho completo para o arquivo.
 */
function saveLogFile($filePath)
{
    global $logMessages;
    $jsonContent = json_encode($logMessages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($filePath, $jsonContent);
}

logToJson("Horário de referência: $currentDateTime");

if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] == 'true') {
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/debug.log');
    if (!is_dir(__DIR__ . '/../logs')) {
        mkdir(__DIR__ . '/../logs', 0777, true);
    }
}

set_time_limit(30);

function haversineGreatCircleDistance(
    $latitudeFrom,
    $longitudeFrom,
    $latitudeTo,
    $longitudeTo,
    $earthRadius = 6371000
) {
    $latFrom = deg2rad($latitudeFrom);
    $lonFrom = deg2rad($longitudeFrom);
    $latTo = deg2rad($latitudeTo);
    $lonTo = deg2rad($longitudeTo);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
        cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
    return $angle * $earthRadius;
}

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
        logToJson("Erro ao buscar dados da API ($url): " . $e->getMessage(), 'error');
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
        // Busca alertas ativos do mesmo source_url
        $stmt = $pdo->prepare("SELECT * FROM alerts WHERE source_url = ? AND status = 1");
        $cleanUrl = strtolower(trim($url));
        $stmt->execute([$cleanUrl]);
        $existingAlerts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existingAlerts[$row['uuid']] = $row;
        }

        // Prepared statement para INSERT / UPDATE
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

        $incomingUuids = [];
        $DUPLICATE_DISTANCE_THRESHOLD = 1500;

        foreach ($alerts as $alert) {
            if (!isset($alert['location']['x'], $alert['location']['y'])) {
                continue;
            }

            $uuid = $alert['uuid'];
            $incomingUuids[] = $uuid;

            // Calcula km apenas se aplicável (dependendo do parceiro e da distância)
            $km = null;
            $lat = $alert['location']['y'];
            $lng = $alert['location']['x'];
            if ($lat && $lng) {
                // Somente alguns parceiros ou alertas podem gerar km
                if ($id_parceiro === 1 || $id_parceiro === 2) { // exemplo de regra de parceiro
                    $kmlPath = __DIR__ . '/kmls/eprviamineira/doc.kml';
                    $limiteKm = 2; // limite em km
                    $kmCalculado = encontrarKmPorCoordenadasEPR($lat, $lng, $kmlPath, $limiteKm);
                    if ($kmCalculado !== null) {
                        $km = $kmCalculado;
                    }
                }
            }

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
                'location_x' => $lng,
                'location_y' => $lat,
                'pubMillis' => $alert['pubMillis'] ?? null
            ];

            $isNew = !isset($existingAlerts[$uuid]);
            $shouldUpdate = $isNew || alertChanged($existingAlerts[$uuid], $flatAlert);
            $isDuplicate = false;

            // Verifica duplicidade na tabela de duplicados
            $alertsDuplicadosStmt = $pdo->prepare("SELECT * FROM duplicate_alerts WHERE uuid = ?");
            $alertsDuplicadosStmt->execute([$uuid]);
            $existingDuplicate = $alertsDuplicadosStmt->fetch(PDO::FETCH_ASSOC);
            if ($existingDuplicate) {
                logToJson("[DUPLICADO] Alerta $uuid já existe na tabela de duplicados.");
                $isDuplicate = true;
            }

            // Verifica duplicidade por proximidade
            if ($isNew && !$isDuplicate) {
                foreach ($existingAlerts as $existingUuid => $existingAlert) {
                    $distance = haversineGreatCircleDistance(
                        $flatAlert['location_y'],
                        $flatAlert['location_x'],
                        $existingAlert['location_y'],
                        $existingAlert['location_x']
                    );

                    if ($distance < $DUPLICATE_DISTANCE_THRESHOLD && $flatAlert['type'] === $existingAlert['type']) {
                        logToJson("[DUPLICADO] Alerta $uuid ignorado. Muito próximo do alerta ativo $existingUuid (distância: " . round($distance, 2) . "m)");

                        $stmtUpsertDuplicate = $pdo->prepare("
                            INSERT INTO duplicate_alerts (uuid, uuid_corresp, last_update)
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE last_update = VALUES(last_update)
                        ");
                        $stmtUpsertDuplicate->execute([$uuid, $existingUuid, $currentDateTime]);
                        logToJson("[DUPLICADO REGISTRADO] Registro de duplicidade para $uuid inserido ou atualizado.");

                        $isDuplicate = true;
                        break;
                    }
                }
            }

            // Insere ou atualiza alerta
            if (!$isDuplicate && $shouldUpdate) {
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

                $rows = $stmtInsertUpdate->rowCount();
                if ($rows === 1) {
                    logToJson("[INSERIDO] UUID: $uuid");
                    $stmtFila = $pdo->prepare("INSERT INTO fila_envio (uuid_alerta, type, subtype, id_parceiro, data_criacao, enviado) VALUES (?, ?, ?, ?, ?, 0)");
                    $stmtFila->execute([$uuid, $flatAlert['type'], $flatAlert['subtype'] ?? null, $id_parceiro, $currentDateTime]);
                    logToJson("Alerta $uuid adicionado à fila de envio.");
                } elseif ($rows === 2) {
                    logToJson("[ATUALIZADO] UUID: $uuid");
                } else {
                    logToJson("[SEM ALTERAÇÃO] UUID: $uuid ou valor inesperado rowCount(): $rows", 'warning');
                }
            }
        }

        // Desativa alertas antigos não enviados
        $stmtDeactivate = $pdo->prepare("UPDATE alerts SET status = 0, date_updated = ? WHERE uuid = ? AND source_url = ?");
        foreach (array_keys($existingAlerts) as $uuid) {
            if (!in_array($uuid, $incomingUuids)) {
                $stmtDeactivate->execute([$currentDateTime, $uuid, $url]);
                logToJson("Alerta desativado: $uuid");
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function saveJamsToDb(PDO $pdo, array $jams, $url, $id_parceiro)
{
    $currentDateTime = date('Y-m-d H:i:s');
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT uuid FROM jams WHERE source_url = ?");
        $stmt->execute([$url]);
        $existingUuids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $processedUuids = [];
        $stmtJam = $pdo->prepare("
            INSERT INTO jams (
                uuid, country, city, level, speedKMH, length, turnType, endNode, speed,
                roadType, delay, street, pubMillis, id_parceiro, source_url, status,
                date_received, date_updated
            ) VALUES (
                :uuid, :country, :city, :level, :speedKMH, :length, :turnType, :endNode, :speed,
                :roadType, :delay, :street, :pubMillis, :id_parceiro, :source_url, 1,
                :date_received, :date_updated
            )
            ON DUPLICATE KEY UPDATE
                country = VALUES(country),
                city = VALUES(city),
                level = VALUES(level),
                speedKMH = VALUES(speedKMH),
                length = VALUES(length),
                turnType = VALUES(turnType),
                endNode = VALUES(endNode),
                speed = VALUES(speed),
                roadType = VALUES(roadType),
                delay = VALUES(delay),
                street = VALUES(street),
                pubMillis = VALUES(pubMillis),
                status = 1,
                date_updated = NOW()
        ");

        $stmtDeleteLines = $pdo->prepare("DELETE FROM jam_lines WHERE jam_uuid = ?");
        $stmtInsertLine = $pdo->prepare("
            INSERT INTO jam_lines (jam_uuid, sequence, x, y)
            VALUES (:jam_uuid, :sequence, :x, :y)
        ");

        $stmtDeleteSegments = $pdo->prepare("DELETE FROM jam_segments WHERE jam_uuid = ?");
        $stmtInsertSegment = $pdo->prepare("
            INSERT INTO jam_segments (jam_uuid, fromNode, ID_segment, toNode, isForward)
            VALUES (:jam_uuid, :fromNode, :ID_segment, :toNode, :isForward)
        ");

        foreach ($jams as $jam) {
            $uuid = $jam['uuid'];
            $processedUuids[] = $uuid;

            $stmtJam->execute([
                ':uuid' => $uuid,
                ':country' => $jam['country'] ?? null,
                ':city' => $jam['city'] ?? null,
                ':level' => $jam['level'] ?? null,
                ':speedKMH' => $jam['speedKMH'] ?? null,
                ':length' => $jam['length'] ?? null,
                ':turnType' => $jam['turnType'] ?? null,
                ':endNode' => $jam['endNode'] ?? null,
                ':speed' => $jam['speed'] ?? null,
                ':roadType' => $jam['roadType'] ?? null,
                ':delay' => $jam['delay'] ?? null,
                ':street' => $jam['street'] ?? null,
                ':pubMillis' => $jam['pubMillis'] ?? null,
                ':id_parceiro' => $id_parceiro,
                ':source_url' => $url,
                ':date_received' => $currentDateTime,
                ':date_updated' => $currentDateTime
            ]);

            if (!empty($jam['line'])) {
                $stmtDeleteLines->execute([$uuid]);
                $sequence = 0;
                foreach ($jam['line'] as $point) {
                    $stmtInsertLine->execute([
                        ':jam_uuid' => $uuid,
                        ':sequence' => $sequence++,
                        ':x' => $point['x'],
                        ':y' => $point['y']
                    ]);
                }
            }

            if (!empty($jam['segments'])) {
                $stmtDeleteSegments->execute([$uuid]);
                foreach ($jam['segments'] as $segment) {
                    $stmtInsertSegment->execute([
                        ':jam_uuid' => $uuid,
                        ':fromNode' => $segment['fromNode'] ?? null,
                        ':ID_segment' => $segment['ID'] ?? null,
                        ':toNode' => $segment['toNode'] ?? null,
                        ':isForward' => $segment['isForward'] ?? null
                    ]);
                }
            }
        }

        $uuidsToDeactivate = array_diff($existingUuids, $processedUuids);
        $batchSize = 1000;

        if (!empty($uuidsToDeactivate)) {
            $batches = array_chunk($uuidsToDeactivate, $batchSize);

            foreach ($batches as $batch) {
                $placeholders = implode(',', array_fill(0, count($batch), '?'));
                $stmtDeactivate = $pdo->prepare("
                    UPDATE jams 
                    SET status = 0, date_updated = NOW()
                    WHERE uuid IN ($placeholders) 
                    AND source_url = ?
                    AND status = 1
                ");
                $params = array_merge($batch, [$url]);
                $stmtDeactivate->execute($params);
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception("Erro ao salvar jams: " . $e->getMessage());
    }
}

function saveJamsToDbEmpty(PDO $pdo, $id_parceiro)
{
    $currentDateTime = date('Y-m-d H:i:s');
    $pdo->beginTransaction();

    try {
        $stmtDeactivate = $pdo->prepare("
            UPDATE jams 
            SET status = 0, date_updated = NOW()
            WHERE id_parceiro = ?
        ");
        $stmtDeactivate->execute([$id_parceiro]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception("Erro ao desativar jams: " . $e->getMessage());
    }
}

function processAlerts()
{
    $pdo = Database::getConnection();
    $urls = getUrlsFromDb($pdo);

    foreach ($urls as $entry) {
        $url = $entry['url'];
        $id_parceiro = $entry['id_parceiro'];

        logToJson("Processando URL: $url");
        $startUrl = microtime(true);

        $jsonData = fetchAlertsFromApi($url);

        if ($jsonData && !empty($jsonData['alerts'])) {
            try {
                $startAlerts = microtime(true);
                saveAlertsToDb($pdo, $jsonData['alerts'], $url, $id_parceiro);
                $endAlerts = microtime(true);
                logToJson("Tempo salvar alertas: " . round($endAlerts - $startAlerts, 2) . " segundos");
            } catch (Exception $e) {
                logToJson("Erro ao processar alertas: " . $e->getMessage(), 'error');
            }
        }

        if (array_key_exists('jams', $jsonData)) {
            saveJamsToDb($pdo, $jsonData['jams'], $url, $id_parceiro);
        } else {
            logToJson("Desativando alertas para o parceiro: $id_parceiro");
            saveJamsToDbEmpty($pdo, $id_parceiro);
        }
        if (empty($jsonData['jams'])) {
            logToJson("Nenhum jam encontrado na URL: $url");
            saveJamsToDbEmpty($pdo, $id_parceiro);
        }

        $endUrl = microtime(true);
        logToJson("Tempo total da URL: " . round($endUrl - $startUrl, 2) . " segundos");
    }
}

logToJson("Iniciando o processo de atualização dos alertas...");
processAlerts();
logToJson("Processamento concluído!");

$endTime = microtime(true);
$totalTime = $endTime - $startTime;
logToJson("Tempo total de execução: " . round($totalTime, 2) . " segundos");

// Salva o log completo no arquivo
$logFilePath = __DIR__ . '/logs/log_' . date('Y-m-d_H-i-s') . '.json';
echo "Salvando log em: $logFilePath" . PHP_EOL;

saveLogFile($logFilePath);

?>