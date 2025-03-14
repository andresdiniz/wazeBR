<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(1200);

require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

$pdo = Database::getConnection();

$sql = "SELECT url, id_parceiro FROM urls";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$irregularitiesFound = false;

function saveHistoricRoutesData($pdo, $routeId, $avgSpeed, $avgTime) {
    $currentDateTime = date('Y-m-d H:i:s');
    $sql = "INSERT INTO historic_routes (route_id, data, velocidade, tempo) 
            VALUES (:route_id, :data, :velocidade, :tempo)";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':route_id' => $routeId,
            ':data' => $currentDateTime,
            ':velocidade' => $avgSpeed,
            ':tempo' => $avgTime
        ]);
        echo "✅ Dados históricos salvos para a rota ID: $routeId.\n";
    } catch (PDOException $e) {
        echo "❌ Erro ao salvar histórico: " . $e->getMessage() . "\n";
    }
}

foreach ($results as $row) {
    $jsonUrl = $row['url'];
    $id_parceiro = $row['id_parceiro'];
    
    echo "Carregando dados da URL: $jsonUrl\n";

    $ch = curl_init($jsonUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $jsonData = curl_exec($ch);
    
    if(curl_errno($ch)) {
        die("Erro ao carregar os dados JSON de $jsonUrl: " . curl_error($ch));
    }
    
    curl_close($ch);
    $data = json_decode($jsonData, true);
    
    if ($data === null) {
        die("Erro ao decodificar os dados JSON de $jsonUrl.");
    }

    try {
        $pdo->beginTransaction();

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

        $stmtUsers = $pdo->prepare("
            INSERT INTO users_on_jams (jam_level, wazers_count, url_id) 
            VALUES (:jam_level, :wazers_count, :url_id)
            ON DUPLICATE KEY UPDATE 
                wazers_count = :wazers_count
        ");

        foreach ($data['usersOnJams'] as $userJam) {
            $stmtUsers->execute([
                ':jam_level' => $userJam['jamLevel'],
                ':wazers_count' => $userJam['wazersCount'],
                ':url_id' => $urlId,
            ]);
        }

        $stmtRoutes = $pdo->prepare("
            INSERT INTO routes (id, name, from_name, to_name, length, jam_level, time, type, 
                bbox_min_x, bbox_min_y, bbox_max_x, bbox_max_y, url_id, avg_speed, avg_time, 
                historic_speed, historic_time, id_parceiro)
            VALUES (:id, :name, :from_name, :to_name, :length, :jam_level, :time, :type, 
                :bbox_min_x, :bbox_min_y, :bbox_max_x, :bbox_max_y, :url_id, :avg_speed, 
                :avg_time, :historic_speed, :historic_time, :id_parceiro)
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

        foreach ($data['routes'] as $route) {
            $avgSpeed = ($route['length'] / 1000) / ($route['time'] / 3600);
            $avgTime = $route['time'];
            $historicSpeed = ($route['length'] / 1000) / ($route['historicTime'] / 3600);
            $historicTime = $route['historicTime'];

            $stmtRoutes->execute([
                ':id' => $route['id'],
                ':name' => $route['name'],
                ':from_name' => $route['fromName'],
                ':to_name' => $route['toName'],
                ':length' => $route['length'],
                ':jam_level' => $route['jamLevel'],
                ':time' => $route['time'],
                ':type' => $route['type'],
                ':bbox_min_x' => $route['bbox']['minX'],
                ':bbox_min_y' => $route['bbox']['minY'],
                ':bbox_max_x' => $route['bbox']['maxX'],
                ':bbox_max_y' => $route['bbox']['maxY'],
                ':url_id' => $urlId,
                ':avg_speed' => $avgSpeed,
                ':avg_time' => $avgTime,
                ':historic_speed' => $historicSpeed,
                ':historic_time' => $historicTime,
                ':id_parceiro' => $id_parceiro
            ]);

            saveHistoricRoutesData($pdo, $route['id'], $avgSpeed, $avgTime);

            if (isset($route['line']) && is_array($route['line'])) {
                $routeId = $route['id'];
                $linePoints = $route['line'];

                try {
                    $stmtFetchExisting = $pdo->prepare("SELECT x, y FROM route_lines WHERE route_id = :route_id");
                    $stmtFetchExisting->execute([':route_id' => $routeId]);
                    $existingPoints = $stmtFetchExisting->fetchAll(PDO::FETCH_ASSOC);

                    $existingFormatted = array_map(function($p) {
                        return number_format($p['x'], 6) . ',' . number_format($p['y'], 6);
                    }, $existingPoints);

                    $newFormatted = array_map(function($p) {
                        return number_format($p['x'], 6) . ',' . number_format($p['y'], 6);
                    }, $linePoints);

                    sort($existingFormatted);
                    sort($newFormatted);

                    if ($existingFormatted !== $newFormatted) {
                        $stmtDelete = $pdo->prepare("DELETE FROM route_lines WHERE route_id = :route_id");
                        $stmtDelete->execute([':route_id' => $routeId]);

                        if (!empty($linePoints)) {
                            $insertValues = [];
                            foreach ($linePoints as $point) {
                                $insertValues[] = $routeId;
                                $insertValues[] = $point['x'];
                                $insertValues[] = $point['y'];
                            }

                            $placeholders = implode(',', array_fill(0, count($linePoints), '(?, ?, ?)'));
                            $stmtInsert = $pdo->prepare("INSERT INTO route_lines (route_id, x, y) VALUES $placeholders");
                            $stmtInsert->execute($insertValues);
                        }
                    }
                } catch (PDOException $e) {
                    echo "Erro ao processar coordenadas: " . $e->getMessage();
                }
            }

            try {
                if (isset($route['subRoutes']) && is_array($route['subRoutes'])) {
                    $stmtDeactivateAll = $pdo->prepare("
                        UPDATE subroutes 
                        SET is_active = 0 
                        WHERE route_id = :route_id
                    ");
                    $stmtDeactivateAll->execute([':route_id' => $route['id']]);

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

                    foreach ($route['subRoutes'] as $subRoute) {
                        $length = $subRoute['length'] ?? 0;
                        $time = $subRoute['time'] ?? 1;
                        $historicTime = $subRoute['historicTime'] ?? 1;

                        $avgSpeedSubRoute = ($length / 1000) / ($time / 3600);
                        $historicSpeedSubRoute = ($length / 1000) / ($historicTime / 3600);

                        $subRoute['id'] = uniqid();
                        $leadAlert = $subRoute['leadAlert'] ?? null;

                        $paramsInsert = [
                            ':id' => $subRoute['id'],
                            ':route_id' => $route['id'],
                            ':to_name' => $subRoute['toName'] ?? 'Indefinido',
                            ':historic_time' => $historicTime,
                            ':length' => $length,
                            ':jam_level' => $subRoute['jamLevel'] ?? 0,
                            ':time' => $time,
                            ':type' => $subRoute['type'] ?? null,
                            ':avg_speed' => $avgSpeedSubRoute,
                            ':historic_speed' => $historicSpeedSubRoute,
                            ':bbox_min_x' => $subRoute['bbox']['minX'] ?? 0,
                            ':bbox_min_y' => $subRoute['bbox']['minY'] ?? 0,
                            ':bbox_max_x' => $subRoute['bbox']['maxX'] ?? 0,
                            ':bbox_max_y' => $subRoute['bbox']['maxY'] ?? 0,
                            ':lead_alert_id' => $leadAlert['id'] ?? null,
                            ':lead_alert_type' => $leadAlert['type'] ?? null,
                            ':lead_alert_sub_type' => $leadAlert['subType'] ?? null,
                            ':lead_alert_position' => $leadAlert['position'] ?? null,
                            ':lead_alert_num_comments' => $leadAlert['numComments'] ?? null,
                            ':lead_alert_num_thumbs_up' => $leadAlert['numThumbsUp'] ?? null,
                            ':lead_alert_num_not_there_reports' => $leadAlert['numNotThereReports'] ?? null,
                            ':lead_alert_street' => $leadAlert['street'] ?? null,
                            ':id_parceiro' => $id_parceiro
                        ];

                        $stmtSubRoutes->execute($paramsInsert);
                    }
                }
            } catch (Exception $e) {
                echo "Erro ao processar sub-rotas: " . $e->getMessage();
            }
        }

        if (isset($data['irregularities']) && !empty($data['irregularities'])) {
            $irregularitiesFound = true;
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

            $stmtDeactivateAll = $pdo->prepare("UPDATE irregularities SET is_active = 0 WHERE url_id = :url_id");
            $stmtDeactivateAll->execute([':url_id' => $urlId]);

            foreach ($data['irregularities'] as $irregularity) {                
                $irregularityId = generateUuid();
                $avgSpeed = ($irregularity['time'] > 0) ? ($irregularity['length'] / 1000) / ($irregularity['time'] / 3600) : 0;
                $historicSpeed = ($irregularity['historicTime'] > 0) ? ($irregularity['length'] / 1000) / ($irregularity['historicTime'] / 3600) : 0;

                $stmtCheckExistence = $pdo->prepare("SELECT id FROM irregularities WHERE id = :id");
                $stmtCheckExistence->execute([':id' => $irregularityId]);
                $isIrregularityNew = ($stmtCheckExistence->rowCount() === 0);

                $leadAlert = $irregularity['leadAlert'] ?? null;
                $leadtype = $leadAlert ? $leadAlert['type'] : '';
                $numComments = $leadAlert ? $leadAlert['numComments'] : 0;
                $city = $leadAlert ? $leadAlert['city'] : '';
                $externalImageId = $leadAlert ? $leadAlert['externalImageId'] : '';
                $numThumbsUp = $leadAlert ? $leadAlert['numThumbsUp'] : 0;
                $street = $leadAlert ? $leadAlert['street'] : '';
                $subType = $leadAlert ? $leadAlert['subType'] : 'NO_SUBTYPE';
                $position = $leadAlert ? $leadAlert['position'] : '';
                $numNotThereReports = $leadAlert ? $leadAlert['numNotThereReports'] : 0;

                $stmtIrregularities->execute([
                    ':id' => $irregularityId,
                    ':name' => $irregularity['name'],
                    ':from_name' => $irregularity['fromName'],
                    ':to_name' => $irregularity['toName'],
                    ':length' => $irregularity['length'],
                    ':jam_level' => $irregularity['jamLevel'],
                    ':time' => $irregularity['time'],
                    ':leadtype' => $leadtype,
                    ':bbox_min_x' => $irregularity['bbox']['minX'],
                    ':bbox_min_y' => $irregularity['bbox']['minY'],
                    ':bbox_max_x' => $irregularity['bbox']['maxX'],
                    ':bbox_max_y' => $irregularity['bbox']['maxY'],
                    ':is_active' => 1,
                    ':url_id' => $urlId,
                    ':avg_speed' => $avgSpeed,
                    ':avg_time' => $irregularity['time'],
                    ':historic_speed' => $historicSpeed,
                    ':historic_time' => $irregularity['historicTime'],
                    ':update_time' => date('Y-m-d H:i:s'),
                    ':num_comments' => $numComments,
                    ':city' => $city,
                    ':external_image_id' => $externalImageId,
                    ':num_thumbs_up' => $numThumbsUp,
                    ':street' => $street,
                    ':sub_type' => $subType,
                    ':position' => $position,
                    ':num_not_there_reports' => $numNotThereReports,
                    ':id_parceiro' => $id_parceiro
                ]);

                foreach ($irregularity['line'] as $point) {
                    $stmtRouteLine = $pdo->prepare("
                        INSERT INTO route_lines (irregularity_id, x, y)
                        VALUES (:irregularity_id, :x, :y)
                    ");
                    $stmtRouteLine->execute([
                        ':irregularity_id' => $irregularityId,
                        ':x' => $point['x'],
                        ':y' => $point['y']
                    ]);
                }

                if ($isIrregularityNew && $irregularity['jamLevel'] >= 3) {
                    $to = "andresoaresdiniz201218@gmail.com";
                    $subject = "🚨 Alerta de Congestionamento - {$irregularity['name']}";

                    // Coordenadas para o mapa
                    $centerX = ($irregularity['bbox']['minX'] + $irregularity['bbox']['maxX']) / 2;
                    $centerY = ($irregularity['bbox']['minY'] + $irregularity['bbox']['maxY']) / 2;

                    // Supondo que a variável $centerX e $centerY contenham as coordenadas de latitude e longitude
                    $wazeUrl = "https://waze.com/ul?ll=$centerY,$centerX&z=12"; // Link do Waze
                    $mapEmbedUrl = "https://embed.waze.com/pt-BR/iframe?zoom=12&lat=$centerY&lon=$centerX"; // iframe do Waze

                    $message = '<!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="utf-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1">
                        <style>
                            body { margin: 0; padding: 0; background: #f4f4f4; font-family: Arial, sans-serif; }
                            .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                            .header { background: #d9534f; padding: 20px; text-align: center; color: white; font-size: 24px; font-weight: bold; }
                            .content { padding: 20px; color: #333333; }
                            .alert-badge { background: #dc3545; color: white; padding: 8px 16px; border-radius: 20px; display: inline-block; font-weight: bold; margin-bottom: 16px; }
                            .info-box { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 10px; border-left: 5px solid #d9534f; }
                            .map-container { text-align: center; margin: 20px 0; }
                            iframe { width: 100%; height: 250px; border: none; border-radius: 8px; }
                            .button { display: inline-block; padding: 12px 20px; background: #d9534f; color: white; border-radius: 8px; text-decoration: none; font-weight: bold; }
                            .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #666; }
                        </style>
                    </head>
                    <body>
                        <div class="container">
                            <div class="header">🚨 Alerta de Tráfego</div>

                            <div class="content">
                                <div class="alert-badge">Congestionamento nível '.$irregularity['jamLevel'].'/5</div>
                                <h2>'.$irregularity['name'].'</h2>

                                <div class="info-box"><strong>Extensão:</strong> '.number_format($irregularity['length']/1000, 2).' km</div>
                                <div class="info-box"><strong>Velocidade:</strong> '.number_format($avgSpeed, 1).' km/h</div>
                                <div class="info-box"><strong>Local:</strong> '.$irregularity['fromName'].' → '.$irregularity['toName'].'</div>
                                <div class="info-box"><strong>Tipo:</strong> '.$irregularity['type'].' ('.$subType.')</div>
                                <div class="info-box"><strong>Última atualização:</strong> '.date('d/m/Y H:i').'</div>

                                <div class="map-container">
                                    <iframe src="'.$mapEmbedUrl.'" title="Mapa do Waze"></iframe>
                                </div>

                                <div style="text-align: center;">
                                    <a href="'.$wazeUrl.'" class="button">🗺️ Abrir no Waze</a>
                                </div>
                            </div>

                            <div class="footer">
                                <p><a href="[UNSUBSCRIBE_URL]">Cancelar inscrição</a> | <a href="[VIEW_IN_BROWSER_URL]">Ver no navegador</a></p>
                                <p>Dados de mapa © <a href="https://www.mapbox.com/">Mapbox</a>, © <a href="https://www.openstreetmap.org/">OpenStreetMap</a></p>
                            </div>
                        </div>
                    </body>
                    </html>';

                    // Gerar hash único estável baseado na localização e características
                    $alertHash = sha1(json_encode([
                        round($irregularity['bbox']['minX'], 4),
                        round($irregularity['bbox']['minY'], 4),
                        round($irregularity['bbox']['maxX'], 4),
                        round($irregularity['bbox']['maxY'], 4),
                        $irregularity['type'],
                        $subType,
                        date('Y-m-d H') // Agrupar por hora
                    ]));

                    // Verificar cooldown
                    $stmtCooldown = $pdo->prepare("
                        SELECT cooldown_until, send_count 
                        FROM alert_cooldown 
                        WHERE alert_hash = ?
                    ");
                    $stmtCooldown->execute([$alertHash]);
                    $cooldownData = $stmtCooldown->fetch();

                    $shouldSend = true;
                    $now = new DateTime();

                    if ($cooldownData) {
                        $cooldownUntil = new DateTime($cooldownData['cooldown_until']);
                        $sendCount = $cooldownData['send_count'];

                        // Regras de cooldown progressivo
                        if ($now < $cooldownUntil) {
                            $shouldSend = false;
                        } else {
                            // Aumentar o cooldown baseado no número de envios anteriores
                            if ($sendCount >= 5) {
                                $newCooldown = '30 MINUTE';
                            } elseif ($sendCount >= 3) {
                                $newCooldown = '15 MINUTE';
                            } else {
                                $newCooldown = '1 MINUTE';
                            }
                        }
                    } else {
                        $newCooldown = '30 MINUTE'; // Primeiro alerta tem cooldown curto
                    }

                    if ($shouldSend) {
                        if (sendEmail($to, $message, $subject)) {
                            echo "Alerta de e-mail enviado para $to.\n";

                            // Atualizar registro de cooldown
                            $sendCount = $cooldownData['send_count'] ?? 0;
                            $sendCount++;

                            $stmtUpsert = $pdo->prepare("
                                INSERT INTO alert_cooldown 
                                (alert_hash, last_sent, cooldown_until, send_count)
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    last_sent = VALUES(last_sent),
                                    cooldown_until = VALUES(cooldown_until),
                                    send_count = VALUES(send_count)
                            ");

                            $cooldownUntil = $now->modify("+$newCooldown")->format('Y-m-d H:i:s');
                            $stmtUpsert->execute([
                                $alertHash,
                                date('Y-m-d H:i:s'),
                                $cooldownUntil,
                                $sendCount
                            ]);
                        }
                    } else {
                        echo "Alerta em cooldown até {$cooldownData['cooldown_until']}. Não enviando.\n";
                    }
                }

            } 
        }

        if (!$irregularitiesFound) {
            $stmtDeactivateAllIrregularities = $pdo->prepare("UPDATE irregularities SET is_active = 0");
            $stmtDeactivateAllIrregularities->execute();
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Erro ao processar os dados na consulta: " . $e->getMessage() . "\n";
        echo "Detalhes da consulta: " . $e->getTraceAsString() . "\n";
    }
}
//require __DIR__ . '/alerts_por_email.php';
?>