<?php
declare(strict_types=1);

/**
 * Script: wazealerts.php
 * Responsabilidade: Buscar dados da API Waze (alerts + jams),
 *                   salvar/atualizar no banco e desativar registros antigos.
 *
 * Pré-requisitos (fornecidos pelo wazejob.php):
 *   - $logger: Logger
 *   - $pdo: PDO
 *
 * Este script NÃO deve:
 *   - carregar .env
 *   - mudar ini_set / display_errors
 *   - dar echo (a não ser que o master permita, via DEBUG)
 */

if (!isset($logger) || !($logger instanceof Logger)) {
    throw new RuntimeException('Logger não disponível em wazealerts.php');
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('PDO não disponível em wazealerts.php');
}

$startTime       = microtime(true);
$currentDateTime = date('Y-m-d H:i:s');

$logger->info('wazealerts iniciado', [
    'datetime' => $currentDateTime
]);

// Tempo máximo deste worker (2 minutos)
set_time_limit(120);

/**
 * Distância Haversine (em metros)
 */
function haversineGreatCircleDistance(
    float $latitudeFrom,
    float $longitudeFrom,
    float $latitudeTo,
    float $longitudeTo,
    float $earthRadius = 6371000
): float {
    $latFrom = deg2rad($latitudeFrom);
    $lonFrom = deg2rad($longitudeFrom);
    $latTo   = deg2rad($latitudeTo);
    $lonTo   = deg2rad($longitudeTo);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $angle = 2 * asin(sqrt(
        pow(sin($latDelta / 2), 2) +
        cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
    ));

    return $angle * $earthRadius;
}

/**
 * URLs de parceiros cadastradas no banco
 */
function getUrlsFromDb(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT url, id_parceiro FROM urls_alerts");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Busca dados da API (alerts + jams)
 */
function fetchAlertsFromApi(string $url, Logger $logger): ?array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);

        $logger->error('Erro cURL ao buscar dados da API', [
            'url'     => $url,
            'erro'    => $err,
        ]);

        return null;
    }

    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($statusCode < 200 || $statusCode >= 300) {
        $logger->warning('Resposta HTTP não-sucesso da API Waze', [
            'url'         => $url,
            'status_code' => $statusCode
        ]);
        return null;
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        $logger->error('Resposta inválida (JSON) da API Waze', [
            'url'   => $url,
            'trecho' => mb_substr($response, 0, 500)
        ]);
        return null;
    }

    return $decoded;
}

/**
 * Compara se um alerta mudou entre DB e API
 */
function alertChanged(array $existing, array $new): bool
{
    $fields = [
        'country', 'city', 'reportRating', 'reportByMunicipalityUser',
        'confidence', 'reliability', 'type', 'roadType', 'magvar',
        'subtype', 'street', 'location_x', 'location_y', 'pubMillis'
    ];

    foreach ($fields as $field) {
        $dbVal  = $existing[$field] ?? null;
        $newVal = $new[$field] ?? null;

        if ($dbVal != $newVal) {
            return true;
        }
    }
    return false;
}

/**
 * Salva / atualiza alerts no banco, gerencia duplicados e fila de envio
 */
function saveAlertsToDb(
    PDO $pdo,
    Logger $logger,
    array $alerts,
    string $url,
    int $id_parceiro,
    string $currentDateTime
): void {
    $pdo->beginTransaction();

    try {
        $cleanUrl = strtolower(trim($url));

        // 1) Busca alertas ativos desse source_url
        $stmt = $pdo->prepare("
            SELECT * 
            FROM alerts 
            WHERE source_url = ? AND status = 1
        ");
        $stmt->execute([$cleanUrl]);

        $existingAlerts       = [];
        $existingAlertsByType = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existingAlerts[$row['uuid']] = $row;
            $type = $row['type'] ?? '_NULL_';
            $existingAlertsByType[$type][] = $row;
        }

        // 2) Carrega duplicados uma vez só (performance)
        $dupStmt = $pdo->query("SELECT uuid FROM duplicate_alerts");
        $duplicatedUuids = [];
        if ($dupStmt !== false) {
            $duplicatedUuids = $dupStmt->fetchAll(PDO::FETCH_COLUMN);
            $duplicatedUuids = array_flip($duplicatedUuids);
        }

        // 3) Prepared statement para alerts
        $stmtInsertUpdate = $pdo->prepare("
            INSERT INTO alerts (
                uuid, country, city, reportRating, reportByMunicipalityUser, confidence,
                reliability, type, roadType, magvar, subtype, street, location_x, location_y, pubMillis,
                status, source_url, date_received, date_updated, km, id_parceiro
            ) VALUES (
                :uuid, :country, :city, :reportRating, :reportByMunicipalityUser, :confidence,
                :reliability, :type, :roadType, :magvar, :subtype, :street, :location_x, :location_y, :pubMillis,
                1, :source_url, :date_received, :date_updated, :km, :id_parceiro
            )
            ON DUPLICATE KEY UPDATE
                country                 = VALUES(country),
                city                    = VALUES(city),
                reportRating            = VALUES(reportRating),
                reportByMunicipalityUser= VALUES(reportByMunicipalityUser),
                confidence              = VALUES(confidence),
                reliability             = VALUES(reliability),
                type                    = VALUES(type),
                roadType                = VALUES(roadType),
                magvar                  = VALUES(magvar),
                subtype                 = VALUES(subtype),
                street                  = VALUES(street),
                location_x              = VALUES(location_x),
                location_y              = VALUES(location_y),
                pubMillis               = VALUES(pubMillis),
                status                  = 1,
                date_updated            = VALUES(date_updated),
                km                      = VALUES(km),
                id_parceiro             = VALUES(id_parceiro)
        ");

        // 4) Prepared para duplicados
        $stmtUpsertDuplicate = $pdo->prepare("
            INSERT INTO duplicate_alerts (uuid, uuid_corresp, last_update)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE last_update = VALUES(last_update)
        ");

        // 5) Prepared para fila_envio
        $stmtFila = $pdo->prepare("
            INSERT INTO fila_envio (uuid_alerta, type, subtype, id_parceiro, data_criacao, enviado)
            VALUES (?, ?, ?, ?, ?, 0)
        ");

        $incomingUuids = [];
        $DUPLICATE_DISTANCE_THRESHOLD = 1500; // metros

        foreach ($alerts as $alert) {
            if (
                empty($alert['location']['x']) ||
                empty($alert['location']['y']) ||
                empty($alert['uuid'])
            ) {
                continue;
            }

            $uuid = $alert['uuid'];
            $incomingUuids[] = $uuid;

            // Se já marcado como duplicado anteriormente, ignora
            if (isset($duplicatedUuids[$uuid])) {
                $logger->debug('Alerta ignorado por duplicidade pré-existente', [
                    'uuid' => $uuid
                ]);
                continue;
            }

            $lat = (float)$alert['location']['y'];
            $lng = (float)$alert['location']['x'];

            // km opcional para alguns parceiros
            $km = null;
            if ($lat && $lng && in_array($id_parceiro, [1, 2], true)) {
                // Função definida em outro lugar, mantida
                $limiteKm    = 2.0;
                $kmCalculado = encontrarKmPorCoordenadasEPR($lat, $lng, $limiteKm);
                if ($kmCalculado !== null) {
                    $km = $kmCalculado;
                }
            }

            $flatAlert = [
                'uuid'                     => $uuid,
                'country'                  => $alert['country'] ?? null,
                'city'                     => $alert['city'] ?? null,
                'reportRating'             => $alert['reportRating'] ?? null,
                'reportByMunicipalityUser' => $alert['reportByMunicipalityUser'] ?? null,
                'confidence'               => $alert['confidence'] ?? null,
                'reliability'              => $alert['reliability'] ?? null,
                'type'                     => $alert['type'] ?? null,
                'roadType'                 => $alert['roadType'] ?? null,
                'magvar'                   => $alert['magvar'] ?? null,
                'subtype'                  => $alert['subtype'] ?? null,
                'street'                   => $alert['street'] ?? null,
                'location_x'               => $lng,
                'location_y'               => $lat,
                'pubMillis'                => $alert['pubMillis'] ?? null,
            ];

            $isNew        = !isset($existingAlerts[$uuid]);
            $shouldUpdate = $isNew || alertChanged($existingAlerts[$uuid] ?? [], $flatAlert);
            $isDuplicate  = false;

            // Verifica duplicidade por proximidade apenas para candidatos novos
            if ($isNew) {
                $typeKey = $flatAlert['type'] ?? '_NULL_';
                $candidates = $existingAlertsByType[$typeKey] ?? [];

                foreach ($candidates as $existingAlert) {
                    if (empty($existingAlert['location_y']) || empty($existingAlert['location_x'])) {
                        continue;
                    }

                    $distance = haversineGreatCircleDistance(
                        (float)$flatAlert['location_y'],
                        (float)$flatAlert['location_x'],
                        (float)$existingAlert['location_y'],
                        (float)$existingAlert['location_x']
                    );

                    if ($distance < $DUPLICATE_DISTANCE_THRESHOLD) {
                        $logger->info('Alerta marcado como duplicado por proximidade', [
                            'uuid_novo'     => $uuid,
                            'uuid_existente'=> $existingAlert['uuid'] ?? null,
                            'distancia_m'   => round($distance, 2),
                            'tipo'          => $flatAlert['type']
                        ]);

                        $stmtUpsertDuplicate->execute([
                            $uuid,
                            $existingAlert['uuid'] ?? null,
                            $currentDateTime
                        ]);

                        $isDuplicate = true;
                        break;
                    }
                }
            }

            if ($isDuplicate || !$shouldUpdate) {
                continue;
            }

            // INSERT / UPDATE alert
            $stmtInsertUpdate->execute([
                ':uuid'                     => $flatAlert['uuid'],
                ':country'                  => $flatAlert['country'],
                ':city'                     => $flatAlert['city'],
                ':reportRating'             => $flatAlert['reportRating'],
                ':reportByMunicipalityUser' => $flatAlert['reportByMunicipalityUser'],
                ':confidence'               => $flatAlert['confidence'],
                ':reliability'              => $flatAlert['reliability'],
                ':type'                     => $flatAlert['type'],
                ':roadType'                 => $flatAlert['roadType'],
                ':magvar'                   => $flatAlert['magvar'],
                ':subtype'                  => $flatAlert['subtype'],
                ':street'                   => $flatAlert['street'],
                ':location_x'               => $flatAlert['location_x'],
                ':location_y'               => $flatAlert['location_y'],
                ':pubMillis'                => $flatAlert['pubMillis'],
                ':source_url'               => $cleanUrl,
                ':date_received'            => $currentDateTime,
                ':date_updated'             => $currentDateTime,
                ':km'                       => $km,
                ':id_parceiro'              => $id_parceiro
            ]);

            $rows = $stmtInsertUpdate->rowCount();

            if ($rows === 1) {
                // Novo registro
                $logger->info('Alerta inserido', ['uuid' => $uuid]);

                // Adiciona à fila de envio
                $stmtFila->execute([
                    $uuid,
                    $flatAlert['type'],
                    $flatAlert['subtype'] ?? null,
                    $id_parceiro,
                    $currentDateTime
                ]);

            } elseif ($rows === 2) {
                // Atualizado
                $logger->info('Alerta atualizado', ['uuid' => $uuid]);
            } else {
                $logger->warning('rowCount inesperado em INSERT/UPDATE de alerta', [
                    'uuid'  => $uuid,
                    'rows'  => $rows
                ]);
            }
        }

        // Desativa alertas antigos não recebidos desta vez
        $stmtDeactivate = $pdo->prepare("
            UPDATE alerts 
            SET status = 0, date_updated = ? 
            WHERE uuid = ? AND source_url = ?
        ");

        foreach (array_keys($existingAlerts) as $uuid) {
            if (!in_array($uuid, $incomingUuids, true)) {
                $stmtDeactivate->execute([$currentDateTime, $uuid, $cleanUrl]);
                $logger->info('Alerta desativado', ['uuid' => $uuid]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $logger->error('Erro ao salvar alerts no banco', [
            'mensagem' => $e->getMessage(),
            'file'     => $e->getFile(),
            'line'     => $e->getLine()
        ]);
        throw $e;
    }
}

/**
 * Salva / atualiza jams
 */
function saveJamsToDb(
    PDO $pdo,
    Logger $logger,
    array $jams,
    string $url,
    int $id_parceiro,
    string $currentDateTime
): void {
    $pdo->beginTransaction();

    try {
        // Jams existentes para esse source_url
        $stmt = $pdo->prepare("SELECT uuid FROM jams WHERE source_url = ?");
        $stmt->execute([$url]);
        $existingUuids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

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
                country      = VALUES(country),
                city         = VALUES(city),
                level        = VALUES(level),
                speedKMH     = VALUES(speedKMH),
                length       = VALUES(length),
                turnType     = VALUES(turnType),
                endNode      = VALUES(endNode),
                speed        = VALUES(speed),
                roadType     = VALUES(roadType),
                delay        = VALUES(delay),
                street       = VALUES(street),
                pubMillis    = VALUES(pubMillis),
                status       = 1,
                date_updated = NOW()
        ");

        $stmtDeleteLines = $pdo->prepare("DELETE FROM jam_lines WHERE jam_uuid = ?");
        $stmtInsertLine  = $pdo->prepare("
            INSERT INTO jam_lines (jam_uuid, sequence, x, y)
            VALUES (:jam_uuid, :sequence, :x, :y)
        ");

        $stmtDeleteSegments = $pdo->prepare("DELETE FROM jam_segments WHERE jam_uuid = ?");
        $stmtInsertSegment  = $pdo->prepare("
            INSERT INTO jam_segments (jam_uuid, fromNode, ID_segment, toNode, isForward)
            VALUES (:jam_uuid, :fromNode, :ID_segment, :toNode, :isForward)
        ");

        foreach ($jams as $jam) {
            if (empty($jam['uuid'])) {
                continue;
            }
            $uuid = $jam['uuid'];
            $processedUuids[] = $uuid;

            $stmtJam->execute([
                ':uuid'         => $uuid,
                ':country'      => $jam['country'] ?? null,
                ':city'         => $jam['city'] ?? null,
                ':level'        => $jam['level'] ?? null,
                ':speedKMH'     => $jam['speedKMH'] ?? null,
                ':length'       => $jam['length'] ?? null,
                ':turnType'     => $jam['turnType'] ?? null,
                ':endNode'      => $jam['endNode'] ?? null,
                ':speed'        => $jam['speed'] ?? null,
                ':roadType'     => $jam['roadType'] ?? null,
                ':delay'        => $jam['delay'] ?? null,
                ':street'       => $jam['street'] ?? null,
                ':pubMillis'    => $jam['pubMillis'] ?? null,
                ':id_parceiro'  => $id_parceiro,
                ':source_url'   => $url,
                ':date_received'=> $currentDateTime,
                ':date_updated' => $currentDateTime
            ]);

            if (!empty($jam['line'])) {
                $stmtDeleteLines->execute([$uuid]);
                $sequence = 0;
                foreach ($jam['line'] as $point) {
                    if (!isset($point['x'], $point['y'])) {
                        continue;
                    }
                    $stmtInsertLine->execute([
                        ':jam_uuid' => $uuid,
                        ':sequence' => $sequence++,
                        ':x'        => $point['x'],
                        ':y'        => $point['y']
                    ]);
                }
            }

            if (!empty($jam['segments'])) {
                $stmtDeleteSegments->execute([$uuid]);
                foreach ($jam['segments'] as $segment) {
                    $stmtInsertSegment->execute([
                        ':jam_uuid'  => $uuid,
                        ':fromNode'  => $segment['fromNode'] ?? null,
                        ':ID_segment'=> $segment['ID'] ?? null,
                        ':toNode'    => $segment['toNode'] ?? null,
                        ':isForward' => $segment['isForward'] ?? null
                    ]);
                }
            }
        }

        // Desativar jams não recebidos desta vez
        $uuidsToDeactivate = array_diff($existingUuids, $processedUuids);
        $batchSize         = 1000;

        if (!empty($uuidsToDeactivate)) {
            $batches = array_chunk($uuidsToDeactivate, $batchSize);

            foreach ($batches as $batch) {
                $placeholders  = implode(',', array_fill(0, count($batch), '?'));
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
    } catch (Throwable $e) {
        $pdo->rollBack();
        $logger->error('Erro ao salvar jams no banco', [
            'mensagem' => $e->getMessage(),
            'file'     => $e->getFile(),
            'line'     => $e->getLine()
        ]);
        throw $e;
    }
}

/**
 * Desativa todos os jams de um parceiro (quando API veio vazia)
 */
function saveJamsToDbEmpty(
    PDO $pdo,
    Logger $logger,
    int $id_parceiro
): void {
    $pdo->beginTransaction();

    try {
        $stmtDeactivate = $pdo->prepare("
            UPDATE jams 
            SET status = 0, date_updated = NOW()
            WHERE id_parceiro = ?
        ");
        $stmtDeactivate->execute([$id_parceiro]);

        $pdo->commit();

        $logger->info('Todos os jams desativados para parceiro', [
            'id_parceiro' => $id_parceiro
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        $logger->error('Erro ao desativar jams de parceiro', [
            'id_parceiro' => $id_parceiro,
            'mensagem'    => $e->getMessage()
        ]);
        throw $e;
    }
}

/**
 * Orquestra o processo para todas as URLs configuradas
 */
function processAlerts(PDO $pdo, Logger $logger): void
{
    $urls = getUrlsFromDb($pdo);

    if (empty($urls)) {
        $logger->warning('Nenhuma URL encontrada em urls_alerts');
        return;
    }

    foreach ($urls as $entry) {
        $url         = $entry['url'];
        $id_parceiro = (int)$entry['id_parceiro'];

        $logger->info('Processando URL de parceiro', [
            'url'         => $url,
            'id_parceiro' => $id_parceiro
        ]);

        $startUrl   = microtime(true);
        $jsonData   = fetchAlertsFromApi($url, $logger);
        $hadAlerts  = false;
        $hadJams    = false;

        if ($jsonData && !empty($jsonData['alerts'])) {
            try {
                $startAlerts = microtime(true);
                saveAlertsToDb(
                    $pdo,
                    $logger,
                    $jsonData['alerts'],
                    $url,
                    $id_parceiro,
                    date('Y-m-d H:i:s')
                );
                $endAlerts = microtime(true);

                $logger->info('Alertas salvos com sucesso', [
                    'id_parceiro'   => $id_parceiro,
                    'url'           => $url,
                    'qtd_alertas'   => count($jsonData['alerts']),
                    'tempo_alertas' => round($endAlerts - $startAlerts, 2)
                ]);

                $hadAlerts = true;
            } catch (Throwable $e) {
                $logger->error('Erro ao processar alertas da URL', [
                    'url'       => $url,
                    'mensagem'  => $e->getMessage()
                ]);
            }
        }

        if ($jsonData && array_key_exists('jams', $jsonData)) {
            if (!empty($jsonData['jams'])) {
                $startJams = microtime(true);

                saveJamsToDb(
                    $pdo,
                    $logger,
                    $jsonData['jams'],
                    $url,
                    $id_parceiro,
                    date('Y-m-d H:i:s')
                );

                $endJams = microtime(true);

                $logger->info('Jams salvos/atualizados', [
                    'url'        => $url,
                    'id_parceiro'=> $id_parceiro,
                    'qtd_jams'   => count($jsonData['jams']),
                    'tempo_jams' => round($endJams - $startJams, 2)
                ]);

                $hadJams = true;
            } else {
                // Chave existe mas vazia
                $logger->info('Nenhum jam retornado para URL; desativando jams do parceiro', [
                    'url'         => $url,
                    'id_parceiro' => $id_parceiro
                ]);
                saveJamsToDbEmpty($pdo, $logger, $id_parceiro);
            }
        } else {
            // Nenhuma chave 'jams' na resposta
            $logger->info('Resposta sem chave jams; desativando jams do parceiro', [
                'url'         => $url,
                'id_parceiro' => $id_parceiro
            ]);
            saveJamsToDbEmpty($pdo, $logger, $id_parceiro);
        }

        $endUrl = microtime(true);

        $logger->info('URL processada', [
            'url'           => $url,
            'id_parceiro'   => $id_parceiro,
            'teve_alertas'  => $hadAlerts,
            'teve_jams'     => $hadJams,
            'tempo_total_s' => round($endUrl - $startUrl, 2),
            'memoria_mb'    => round(memory_get_usage(true) / 1024 / 1024, 2)
        ]);
    }
}

/**
 * Execução principal deste worker
 */
processAlerts($pdo, $logger);

$endTime   = microtime(true);
$totalTime = round($endTime - $startTime, 2);

$logger->info('wazealerts concluído', [
    'tempo_total_s' => $totalTime,
    'memoria_pico_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
]);

// Não faz exit aqui: quem manda é o wazejob.php
return true;
