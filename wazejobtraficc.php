<?php
declare(strict_types=1);

/**
 * Script: wazejobtraficc.php
 * Responsabilidade: Processar dados de tráfego (rotas, sub-rotas, irregularidades)
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
    throw new RuntimeException('Logger não disponível em wazejobtraficc.php');
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('PDO não disponível em wazejobtraficc.php');
}

$startTime = microtime(true);
$currentDateTime = date('Y-m-d H:i:s');

$logger->info('wazejobtraficc iniciado', ['datetime' => $currentDateTime]);

// Tempo máximo deste worker (5 minutos)
set_time_limit(300);

/**
 * Gera UUID único
 */
function generateUuid(): string
{
    return uniqid('', true);
}

/**
 * Salva dados históricos de rotas
 */
function saveHistoricRoutesData(PDO $pdo, Logger $logger, string $routeId, float $avgSpeed, float $avgTime): void
{
    $currentDateTime = date('Y-m-d H:i:s');
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO historic_routes (route_id, data, velocidade, tempo) 
            VALUES (:route_id, :data, :velocidade, :tempo)
        ");
        
        $stmt->execute([
            ':route_id' => $routeId,
            ':data' => $currentDateTime,
            ':velocidade' => $avgSpeed,
            ':tempo' => $avgTime
        ]);
        
        $logger->debug('Dados históricos salvos', ['route_id' => $routeId]);
    } catch (PDOException $e) {
        $logger->error('Erro ao salvar histórico', [
            'route_id' => $routeId,
            'mensagem' => $e->getMessage()
        ]);
    }
}

/**
 * Processa dados de tráfego
 */
function processTrafficData(PDO $pdo, Logger $logger): void
{
    // Buscar URLs configuradas
    $stmt = $pdo->prepare("SELECT url, id_parceiro FROM urls");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($results)) {
        $logger->warning('Nenhuma URL encontrada em urls');
        return;
    }

    foreach ($results as $row) {
        $jsonUrl = $row['url'];
        $id_parceiro = (int)$row['id_parceiro'];

        $logger->info('Processando URL de tráfego', [
            'url' => $jsonUrl,
            'id_parceiro' => $id_parceiro
        ]);

        $startUrl = microtime(true);

        // Carregar dados da API
        $ch = curl_init($jsonUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $jsonData = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            
            $logger->error('Erro cURL ao buscar dados de tráfego', [
                'url' => $jsonUrl,
                'erro' => $error
            ]);
            continue;
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            $logger->warning('Resposta HTTP não-sucesso', [
                'url' => $jsonUrl,
                'status_code' => $statusCode
            ]);
            continue;
        }

        $data = json_decode($jsonData, true);

        if ($data === null) {
            $logger->error('Erro ao decodificar JSON', [
                'url' => $jsonUrl,
                'json_error' => json_last_error_msg()
            ]);
            continue;
        }

        try {
            $pdo->beginTransaction();

            // Verificar/inserir URL
            $stmtCheckUrl = $pdo->prepare("SELECT id FROM urls WHERE url = :url");
            $stmtCheckUrl->execute([':url' => $jsonUrl]);
            $urlData = $stmtCheckUrl->fetch();

            if ($urlData) {
                $urlId = $urlData['id'];
            } else {
                $stmtUrl = $pdo->prepare("INSERT INTO urls (url) VALUES (:url)");
                $stmtUrl->execute([':url' => $jsonUrl]);
                $urlId = $pdo->lastInsertId();
            }

            // Processar users_on_jams
            if (isset($data['usersOnJams']) && is_array($data['usersOnJams'])) {
                $stmtUsers = $pdo->prepare("
                    INSERT INTO users_on_jams (jam_level, wazers_count, url_id, id_parceiro, created_at)
                    VALUES (:jam_level, :wazers_count, :url_id, :id_parceiro, :created_at)
                ");

                foreach ($data['usersOnJams'] as $userJam) {
                    $stmtUsers->execute([
                        ':jam_level' => $userJam['jamLevel'] ?? null,
                        ':wazers_count' => $userJam['wazersCount'] ?? 0,
                        ':url_id' => $urlId,
                        ':id_parceiro' => $id_parceiro,
                        ':created_at' => $currentDateTime
                    ]);
                }
            }

            // Processar rotas
            if (isset($data['routes']) && is_array($data['routes'])) {
                processRoutes($pdo, $logger, $data['routes'], $urlId, $id_parceiro);
            }

            // Processar irregularidades
            if (isset($data['irregularities']) && is_array($data['irregularities'])) {
                processIrregularities($pdo, $logger, $data['irregularities'], $urlId, $id_parceiro);
            } else {
                // Desativar todas as irregularidades se não houver
                $stmtDeactivate = $pdo->prepare("UPDATE irregularities SET is_active = 0 WHERE url_id = :url_id");
                $stmtDeactivate->execute([':url_id' => $urlId]);
            }

            $pdo->commit();

            $endUrl = microtime(true);
            $logger->info('URL de tráfego processada', [
                'url' => $jsonUrl,
                'id_parceiro' => $id_parceiro,
                'tempo_s' => round($endUrl - $startUrl, 2)
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $logger->error('Erro ao processar dados de tráfego', [
                'url' => $jsonUrl,
                'mensagem' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
}

/**
 * Processa rotas
 */
function processRoutes(PDO $pdo, Logger $logger, array $routes, int $urlId, int $id_parceiro): void
{
    $stmtRoutes = $pdo->prepare("
        INSERT INTO routes (
            id, name, from_name, to_name, length, jam_level, time, type, 
            bbox_min_x, bbox_min_y, bbox_max_x, bbox_max_y, url_id, avg_speed, avg_time, 
            historic_speed, historic_time, id_parceiro
        ) VALUES (
            :id, :name, :from_name, :to_name, :length, :jam_level, :time, :type, 
            :bbox_min_x, :bbox_min_y, :bbox_max_x, :bbox_max_y, :url_id, :avg_speed, 
            :avg_time, :historic_speed, :historic_time, :id_parceiro
        )
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name), 
            from_name = VALUES(from_name), 
            to_name = VALUES(to_name), 
            length = VALUES(length), 
            jam_level = VALUES(jam_level), 
            time = VALUES(time), 
            type = VALUES(type),
            bbox_min_x = VALUES(bbox_min_x), 
            bbox_min_y = VALUES(bbox_min_y), 
            bbox_max_x = VALUES(bbox_max_x), 
            bbox_max_y = VALUES(bbox_max_y), 
            avg_speed = VALUES(avg_speed),
            avg_time = VALUES(avg_time), 
            historic_speed = VALUES(historic_speed),
            historic_time = VALUES(historic_time),
            id_parceiro = VALUES(id_parceiro)
    ");

    foreach ($routes as $route) {
        $length = $route['length'] ?? 0;
        $time = $route['time'] ?? 1;
        $historicTime = $route['historicTime'] ?? 1;

        if ($length <= 0) $length = 1;
        if ($time <= 0) $time = 1;
        if ($historicTime <= 0) $historicTime = 1;

        $avgSpeed = ($length / 1000) / ($time / 3600);
        $avgTime = $time;
        $historicSpeed = ($length / 1000) / ($historicTime / 3600);
        $historicTime = $historicTime;

        $stmtRoutes->execute([
            ':id' => $route['id'],
            ':name' => $route['name'] ?? '',
            ':from_name' => $route['fromName'] ?? '',
            ':to_name' => $route['toName'] ?? '',
            ':length' => $length,
            ':jam_level' => $route['jamLevel'] ?? 0,
            ':time' => $time,
            ':type' => $route['type'] ?? '',
            ':bbox_min_x' => $route['bbox']['minX'] ?? 0,
            ':bbox_min_y' => $route['bbox']['minY'] ?? 0,
            ':bbox_max_x' => $route['bbox']['maxX'] ?? 0,
            ':bbox_max_y' => $route['bbox']['maxY'] ?? 0,
            ':url_id' => $urlId,
            ':avg_speed' => $avgSpeed,
            ':avg_time' => $avgTime,
            ':historic_speed' => $historicSpeed,
            ':historic_time' => $historicTime,
            ':id_parceiro' => $id_parceiro
        ]);

        saveHistoricRoutesData($pdo, $logger, $route['id'], $avgSpeed, $avgTime);

        // Processar coordenadas (route_lines)
        if (isset($route['line']) && is_array($route['line'])) {
            processRouteLines($pdo, $logger, $route['id'], $route['line']);
        }

        // Processar sub-rotas
        if (isset($route['subRoutes']) && is_array($route['subRoutes'])) {
            processSubRoutes($pdo, $logger, $route['id'], $route['subRoutes'], $id_parceiro);
        }
    }
}

/**
 * Processa coordenadas das rotas
 */
function processRouteLines(PDO $pdo, Logger $logger, string $routeId, array $linePoints): void
{
    try {
        // Buscar coordenadas existentes
        $stmtFetch = $pdo->prepare("SELECT x, y FROM route_lines WHERE route_id = :route_id");
        $stmtFetch->execute([':route_id' => $routeId]);
        $existingPoints = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);

        // Formatar para comparação
        $existingFormatted = array_map(function ($p) {
            return number_format((float)$p['x'], 6) . ',' . number_format((float)$p['y'], 6);
        }, $existingPoints);

        $newFormatted = array_map(function ($p) {
            return number_format((float)$p['x'], 6) . ',' . number_format((float)$p['y'], 6);
        }, $linePoints);

        sort($existingFormatted);
        sort($newFormatted);

        // Se mudou, atualizar
        if ($existingFormatted !== $newFormatted) {
            $stmtDelete = $pdo->prepare("DELETE FROM route_lines WHERE route_id = :route_id");
            $stmtDelete->execute([':route_id' => $routeId]);

            if (!empty($linePoints)) {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO route_lines (route_id, x, y) VALUES (:route_id, :x, :y)
                ");

                foreach ($linePoints as $point) {
                    $stmtInsert->execute([
                        ':route_id' => $routeId,
                        ':x' => $point['x'],
                        ':y' => $point['y']
                    ]);
                }
            }
        }
    } catch (PDOException $e) {
        $logger->error('Erro ao processar route_lines', [
            'route_id' => $routeId,
            'mensagem' => $e->getMessage()
        ]);
    }
}

/**
 * Processa sub-rotas
 */
function processSubRoutes(PDO $pdo, Logger $logger, string $routeId, array $subRoutes, int $id_parceiro): void
{
    try {
        // Desativar todas as sub-rotas desta rota
        $stmtDeactivate = $pdo->prepare("UPDATE subroutes SET is_active = 0 WHERE route_id = :route_id");
        $stmtDeactivate->execute([':route_id' => $routeId]);

        $stmtSubRoutes = $pdo->prepare("
            INSERT INTO subroutes (
                id, route_id, to_name, historic_time, length, jam_level, time, type, 
                bbox_min_x, bbox_min_y, bbox_max_x, bbox_max_y, avg_speed, historic_speed, is_active,
                lead_alert_id, lead_alert_type, lead_alert_sub_type, lead_alert_position, 
                lead_alert_num_comments, lead_alert_num_thumbs_up, lead_alert_num_not_there_reports, 
                lead_alert_street, id_parceiro
            ) VALUES (
                :id, :route_id, :to_name, :historic_time, :length, :jam_level, :time, :type, 
                :bbox_min_x, :bbox_min_y, :bbox_max_x, :bbox_max_y, :avg_speed, :historic_speed, 1,
                :lead_alert_id, :lead_alert_type, :lead_alert_sub_type, :lead_alert_position, 
                :lead_alert_num_comments, :lead_alert_num_thumbs_up, :lead_alert_num_not_there_reports, 
                :lead_alert_street, :id_parceiro
            )
            ON DUPLICATE KEY UPDATE 
                to_name = VALUES(to_name), 
                historic_time = VALUES(historic_time), 
                length = VALUES(length), 
                jam_level = VALUES(jam_level), 
                time = VALUES(time), 
                type = VALUES(type), 
                avg_speed = VALUES(avg_speed), 
                historic_speed = VALUES(historic_speed), 
                is_active = 1,
                lead_alert_id = VALUES(lead_alert_id),
                lead_alert_type = VALUES(lead_alert_type),
                lead_alert_sub_type = VALUES(lead_alert_sub_type),
                lead_alert_position = VALUES(lead_alert_position),
                lead_alert_num_comments = VALUES(lead_alert_num_comments),
                lead_alert_num_thumbs_up = VALUES(lead_alert_num_thumbs_up),
                lead_alert_num_not_there_reports = VALUES(lead_alert_num_not_there_reports),
                lead_alert_street = VALUES(lead_alert_street),
                id_parceiro = VALUES(id_parceiro)
        ");

        foreach ($subRoutes as $subRoute) {
            $length = $subRoute['length'] ?? 1;
            $time = $subRoute['time'] ?? 1;
            $historicTime = $subRoute['historicTime'] ?? 1;

            if ($length <= 0) $length = 1;
            if ($time <= 0) $time = 1;
            if ($historicTime <= 0) $historicTime = 1;

            $avgSpeed = ($length / 1000) / ($time / 3600);
            $historicSpeed = ($length / 1000) / ($historicTime / 3600);

            $leadAlert = $subRoute['leadAlert'] ?? null;

            $stmtSubRoutes->execute([
                ':id' => generateUuid(),
                ':route_id' => $routeId,
                ':to_name' => $subRoute['toName'] ?? '',
                ':historic_time' => $historicTime,
                ':length' => $length,
                ':jam_level' => $subRoute['jamLevel'] ?? 0,
                ':time' => $time,
                ':type' => $subRoute['type'] ?? '',
                ':bbox_min_x' => $subRoute['bbox']['minX'] ?? 0,
                ':bbox_min_y' => $subRoute['bbox']['minY'] ?? 0,
                ':bbox_max_x' => $subRoute['bbox']['maxX'] ?? 0,
                ':bbox_max_y' => $subRoute['bbox']['maxY'] ?? 0,
                ':avg_speed' => $avgSpeed,
                ':historic_speed' => $historicSpeed,
                ':lead_alert_id' => $leadAlert['id'] ?? null,
                ':lead_alert_type' => $leadAlert['type'] ?? null,
                ':lead_alert_sub_type' => $leadAlert['subType'] ?? null,
                ':lead_alert_position' => $leadAlert['position'] ?? null,
                ':lead_alert_num_comments' => $leadAlert['numComments'] ?? 0,
                ':lead_alert_num_thumbs_up' => $leadAlert['numThumbsUp'] ?? 0,
                ':lead_alert_num_not_there_reports' => $leadAlert['numNotThereReports'] ?? 0,
                ':lead_alert_street' => $leadAlert['street'] ?? null,
                ':id_parceiro' => $id_parceiro
            ]);
        }
    } catch (Exception $e) {
        $logger->error('Erro ao processar sub-rotas', [
            'route_id' => $routeId,
            'mensagem' => $e->getMessage()
        ]);
    }
}

/**
 * Processa irregularidades
 */
function processIrregularities(PDO $pdo, Logger $logger, array $irregularities, int $urlId, int $id_parceiro): void
{
    try {
        // Desativar todas as irregularidades desta URL
        $stmtDeactivate = $pdo->prepare("UPDATE irregularities SET is_active = 0 WHERE url_id = :url_id");
        $stmtDeactivate->execute([':url_id' => $urlId]);

        $stmtIrregularities = $pdo->prepare("
            INSERT INTO irregularities (
                id, name, from_name, to_name, length, jam_level, time, leadtype, 
                bbox_min_x, bbox_min_y, bbox_max_x, bbox_max_y, is_active, 
                url_id, avg_speed, avg_time, historic_speed, historic_time, update_time, 
                num_comments, city, external_image_id, num_thumbs_up, street, sub_type, 
                position, num_not_there_reports, id_parceiro
            ) VALUES (
                :id, :name, :from_name, :to_name, :length, :jam_level, :time, :leadtype, 
                :bbox_min_x, :bbox_min_y, :bbox_max_x, :bbox_max_y, :is_active, 
                :url_id, :avg_speed, :avg_time, :historic_speed, :historic_time, :update_time, 
                :num_comments, :city, :external_image_id, :num_thumbs_up, :street, :sub_type, 
                :position, :num_not_there_reports, :id_parceiro
            )
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name),
                from_name = VALUES(from_name),
                to_name = VALUES(to_name),
                length = VALUES(length),
                jam_level = VALUES(jam_level),
                time = VALUES(time),
                leadtype = VALUES(leadtype),
                bbox_min_x = VALUES(bbox_min_x),
                bbox_min_y = VALUES(bbox_min_y),
                bbox_max_x = VALUES(bbox_max_x),
                bbox_max_y = VALUES(bbox_max_y),
                is_active = VALUES(is_active),
                avg_speed = VALUES(avg_speed),
                avg_time = VALUES(avg_time),
                historic_speed = VALUES(historic_speed),
                historic_time = VALUES(historic_time),
                update_time = VALUES(update_time),
                num_comments = VALUES(num_comments),
                city = VALUES(city),
                external_image_id = VALUES(external_image_id),
                num_thumbs_up = VALUES(num_thumbs_up),
                street = VALUES(street),
                sub_type = VALUES(sub_type),
                position = VALUES(position),
                num_not_there_reports = VALUES(num_not_there_reports),
                id_parceiro = VALUES(id_parceiro)
        ");

        foreach ($irregularities as $irregularity) {
            $irregularityId = generateUuid();
            
            $time = $irregularity['time'] ?? 1;
            $historicTime = $irregularity['historicTime'] ?? 1;
            $length = $irregularity['length'] ?? 1;

            if ($time <= 0) $time = 1;
            if ($historicTime <= 0) $historicTime = 1;
            if ($length <= 0) $length = 1;

            $avgSpeed = ($length / 1000) / ($time / 3600);
            $historicSpeed = ($length / 1000) / ($historicTime / 3600);

            $leadAlert = $irregularity['leadAlert'] ?? null;

            $stmtIrregularities->execute([
                ':id' => $irregularityId,
                ':name' => $irregularity['name'] ?? '',
                ':from_name' => $irregularity['fromName'] ?? '',
                ':to_name' => $irregularity['toName'] ?? '',
                ':length' => $length,
                ':jam_level' => $irregularity['jamLevel'] ?? 0,
                ':time' => $time,
                ':leadtype' => $leadAlert['type'] ?? '',
                ':bbox_min_x' => $irregularity['bbox']['minX'] ?? 0,
                ':bbox_min_y' => $irregularity['bbox']['minY'] ?? 0,
                ':bbox_max_x' => $irregularity['bbox']['maxX'] ?? 0,
                ':bbox_max_y' => $irregularity['bbox']['maxY'] ?? 0,
                ':is_active' => 1,
                ':url_id' => $urlId,
                ':avg_speed' => $avgSpeed,
                ':avg_time' => $time,
                ':historic_speed' => $historicSpeed,
                ':historic_time' => $historicTime,
                ':update_time' => date('Y-m-d H:i:s'),
                ':num_comments' => $leadAlert['numComments'] ?? 0,
                ':city' => $leadAlert['city'] ?? '',
                ':external_image_id' => $leadAlert['externalImageId'] ?? '',
                ':num_thumbs_up' => $leadAlert['numThumbsUp'] ?? 0,
                ':street' => $leadAlert['street'] ?? '',
                ':sub_type' => $leadAlert['subType'] ?? 'NO_SUBTYPE',
                ':position' => $leadAlert['position'] ?? '',
                ':num_not_there_reports' => $leadAlert['numNotThereReports'] ?? 0,
                ':id_parceiro' => $id_parceiro
            ]);

            // Processar coordenadas da irregularidade
            if (isset($irregularity['line']) && is_array($irregularity['line'])) {
                $stmtRouteLine = $pdo->prepare("
                    INSERT INTO route_lines (irregularity_id, x, y)
                    VALUES (:irregularity_id, :x, :y)
                ");

                foreach ($irregularity['line'] as $point) {
                    $stmtRouteLine->execute([
                        ':irregularity_id' => $irregularityId,
                        ':x' => $point['x'],
                        ':y' => $point['y']
                    ]);
                }
            }
        }
    } catch (Exception $e) {
        $logger->error('Erro ao processar irregularidades', [
            'url_id' => $urlId,
            'mensagem' => $e->getMessage()
        ]);
    }
}

// Execução principal
processTrafficData($pdo, $logger);

$endTime = microtime(true);
$totalTime = round($endTime - $startTime, 2);

$logger->info('wazejobtraficc concluído', [
    'tempo_total_s' => $totalTime,
    'memoria_pico_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
]);

return true;