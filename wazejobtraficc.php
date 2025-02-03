<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(1200);  // Define o tempo limite para 5 minutos (300 segundos)

require_once __DIR__ . '/config/configbd.php';

require_once __DIR__ . '/functions/scripts.php';

$pdo = Database::getConnection();

// URLs para o JSON
$jsonUrls = [
    "https://www.waze.com/row-partnerhub-api/feeds-tvt/?id=1725279881116",
    "https://www.waze.com/row-partnerhub-api/feeds-tvt/?id=12699055487",
];

$irregularitiesFound = false; // Variável para monitorar se irregularidades foram encontradas

foreach ($jsonUrls as $jsonUrl) {
    echo "Carregando dados da URL: $jsonUrl\n";

    // Iniciar uma sessão cURL
    $ch = curl_init($jsonUrl);
    
    // Configurar cURL para retornar o resultado como string
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Executar a requisição cURL
    $jsonData = curl_exec($ch);
    
    // Verificar se houve erro na requisição
    if(curl_errno($ch)) {
        die("Erro ao carregar os dados JSON de $jsonUrl: " . curl_error($ch));
    }
    
    // Fechar a sessão cURL
    curl_close($ch);
    
    // Decodificar o JSON
    $data = json_decode($jsonData, true);
    
    // Verificar se a decodificação falhou
    if ($data === null) {
        die("Erro ao decodificar os dados JSON de $jsonUrl.");
    }

    try {
        $pdo->beginTransaction();

        // Verificar se a URL já existe
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

        // Inserção/Atualização de `users_on_jams` com historic_time e historic_speed
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

        // Inserção/Atualização de `routes`
        $stmtRoutes = $pdo->prepare("
            INSERT INTO routes (id, name, from_name, to_name, length, jam_level, time, type, bbox_min_x, bbox_min_y, bbox_max_x, bbox_max_y, url_id, avg_speed, avg_time, historic_speed, historic_time)
            VALUES (:id, :name, :from_name, :to_name, :length, :jam_level, :time, :type, :bbox_min_x, :bbox_min_y, :bbox_max_x, :bbox_max_y, :url_id, :avg_speed, :avg_time, :historic_speed, :historic_time)
            ON DUPLICATE KEY UPDATE 
                name = :name, 
                from_name = :from_name, 
                to_name = :to_name, 
                length = :length, 
                jam_level = :jam_level, 
                time = :time, 
                type = :type,
                bbox_min_x = :bbox_min_x, 
                bbox_min_y = :bbox_min_y, 
                bbox_max_x = :bbox_max_x, 
                bbox_max_y = :bbox_max_y, 
                avg_speed = :avg_speed,
                avg_time = :avg_time, 
                historic_speed = :historic_speed,
                historic_time = :historic_time
        ");

        foreach ($data['routes'] as $route) {
            // Cálculos das velocidades para a rota principal
            echo "Tempo da rota " . $route['fromName'] . " é " . $route['time'];
            $avgSpeed = ($route['length'] / 1000) / ($route['time'] / 3600); // km/h
            $avgTime = $route['time'];
            $historicSpeed = ($route['length'] / 1000) / ($route['historicTime'] / 3600); // km/h
            $historicTime = $route['historicTime'];

            // Inserção da rota principal
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
            ]);

            // Codigo para inserir as coordenadas

            if (isset($route['line']) && is_array($route['line'])) {
                echo "Processando linha da rota: {$route['toName']}\n";
                $routeId = $route['id'];
                $linePoints = $route['line'];

                try {
                    // Buscar todos os pontos existentes no banco
                    $stmtFetchExisting = $pdo->prepare("SELECT x, y FROM route_lines WHERE route_id = :route_id");
                    $stmtFetchExisting->execute([':route_id' => $routeId]);
                    $existingPoints = $stmtFetchExisting->fetchAll(PDO::FETCH_ASSOC);

                    // Converter para formato comparável
                    $existingFormatted = array_map(function($p) {
                        return number_format($p['x'], 6) . ',' . number_format($p['y'], 6);
                    }, $existingPoints);

                    // Preparar novos pontos para comparação
                    $newFormatted = array_map(function($p) {
                        return number_format($p['x'], 6) . ',' . number_format($p['y'], 6);
                    }, $linePoints);

                    // Ordenar e comparar
                    sort($existingFormatted);
                    sort($newFormatted);

                    // Se houver diferenças, apagar e recriar todos os pontos
                    if ($existingFormatted !== $newFormatted) {
                        echo "Atualizando coordenadas para a rota {$routeId}\n";

                        // Apagar pontos antigos
                        $stmtDelete = $pdo->prepare("DELETE FROM route_lines WHERE route_id = :route_id");
                        $stmtDelete->execute([':route_id' => $routeId]);

                        // Inserir novos pontos em lote
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
                    } else {
                        echo "Nenhuma alteração nas coordenadas da rota\n";
                    }
                } catch (PDOException $e) {
                    echo "Erro ao processar coordenadas: " . $e->getMessage();
                }
            }

            

            // Verifica se existem sub-rotas e se são um array
            try {
                if (isset($route['subRoutes']) && is_array($route['subRoutes'])) {
                    // Desativa todas as sub-rotas da rota inicialmente
                    $stmtDeactivateAll = $pdo->prepare("
                        UPDATE subroutes 
                        SET is_active = 0 
                        WHERE route_id = :route_id
                    ");
                    $stmtDeactivateAll->execute([':route_id' => $route['id']]);
                
                    // Prepara a consulta para inserção de sub-rotas
                    $stmtSubRoutes = $pdo->prepare("
                        INSERT INTO subroutes (
                            id, route_id, to_name, historic_time, length, jam_level, time, type, 
                            bbox_min_x, bbox_min_y, bbox_max_x, bbox_max_y, avg_speed, historic_speed, is_active,
                            lead_alert_id, lead_alert_type, lead_alert_sub_type, lead_alert_position, 
                            lead_alert_num_comments, lead_alert_num_thumbs_up, lead_alert_num_not_there_reports, lead_alert_street
                        ) VALUES (
                            :id, :route_id, :to_name, :historic_time, :length, :jam_level, :time, :type, 
                            :bbox_min_x, :bbox_min_y, :bbox_max_x, :bbox_max_y, :avg_speed, :historic_speed, 1,
                            :lead_alert_id, :lead_alert_type, :lead_alert_sub_type, :lead_alert_position, 
                            :lead_alert_num_comments, :lead_alert_num_thumbs_up, :lead_alert_num_not_there_reports, :lead_alert_street
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
                            lead_alert_street = VALUES(lead_alert_street)
                    ");
                
                    foreach ($route['subRoutes'] as $subRoute) {
                        // Prepara os dados da sub-rota
                        $length = isset($subRoute['length']) ? $subRoute['length'] : 0;
                        $time = isset($subRoute['time']) ? $subRoute['time'] : 1; // Evitar divisão por zero
                        $historicTime = isset($subRoute['historicTime']) ? $subRoute['historicTime'] : 1; // Evitar divisão por zero
                
                        $avgSpeedSubRoute = ($length / 1000) / ($time / 3600); // km/h
                        $historicSpeedSubRoute = ($length / 1000) / ($historicTime / 3600); // km/h
                
                        $subRoute['id'] = uniqid();
                
                        // Verifica se leadAlert existe
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
                            // Dados do leadAlert
                            ':lead_alert_id' => $leadAlert['id'] ?? null,
                            ':lead_alert_type' => $leadAlert['type'] ?? null,
                            ':lead_alert_sub_type' => $leadAlert['subType'] ?? null,
                            ':lead_alert_position' => $leadAlert['position'] ?? null,
                            ':lead_alert_num_comments' => $leadAlert['numComments'] ?? null,
                            ':lead_alert_num_thumbs_up' => $leadAlert['numThumbsUp'] ?? null,
                            ':lead_alert_num_not_there_reports' => $leadAlert['numNotThereReports'] ?? null,
                            ':lead_alert_street' => $leadAlert['street'] ?? null,
                        ];
                
                        // Insere ou atualiza a sub-rota
                        $stmtSubRoutes->execute($paramsInsert);
                    }
                }                
            } catch (Exception $e) {
                echo "Erro ao processar sub-rotas: " . $e->getMessage();
            }            
        }    

        // Insere irregularidades de tráfego encontradas
        if (isset($data['irregularities']) && !empty($data['irregularities'])) {
            $irregularitiesFound = true; // Marca que pelo menos uma irregularidade foi encontrada
            echo "Irregularidade encontrada\n";

            $stmtIrregularities = $pdo->prepare("
                INSERT INTO irregularities (
                    id, name, from_name, to_name, length, jam_level, time, leadtype, 
                    bbox_min_x, bbox_min_y, bbox_max_x, bbox_max_y, is_active, 
                    url_id, avg_speed, avg_time, historic_speed, historic_time, update_time, 
                    num_comments, city, external_image_id, num_thumbs_up, street, sub_type, position, num_not_there_reports
                ) VALUES (
                    :id, :name, :from_name, :to_name, :length, :jam_level, :time, :leadtype, 
                    :bbox_min_x, :bbox_min_y, :bbox_max_x, :bbox_max_y, :is_active, 
                    :url_id, :avg_speed, :avg_time, :historic_speed, :historic_time, :update_time, 
                    :num_comments, :city, :external_image_id, :num_thumbs_up, :street, :sub_type, :position, :num_not_there_reports
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
                    num_not_there_reports = VALUES(num_not_there_reports)
            ");

            // Atualizar is_active para 0 antes do processamento
            $stmtDeactivateAll = $pdo->prepare("UPDATE irregularities SET is_active = 0 WHERE url_id = :url_id");
            $stmtDeactivateAll->execute([':url_id' => $urlId]);

            foreach ($data['irregularities'] as $irregularity) {
                // Gerando um ID único para a irregularidade
                //$radius = calculateRadius($irregularity['bbox']);
                $impactScore = /*calculateImpactScore(
                    $irregularity['jamLevel'],
                    $irregularity['length'],
                    $radius
                );*/0;

                $irregularityId = uniqid('irreg_', true);
                
                // Cálculo das velocidades média e histórica
                $avgSpeed = ($irregularity['time'] > 0) ? ($irregularity['length'] / 1000) / ($irregularity['time'] / 3600) : 0;
                $historicSpeed = ($irregularity['historicTime'] > 0) ? ($irregularity['length'] / 1000) / ($irregularity['historicTime'] / 3600) : 0;

                // Verifica se a irregularidade já existe
                $stmtCheckExistence = $pdo->prepare("SELECT id FROM irregularities WHERE id = :id");
                $stmtCheckExistence->execute([':id' => $irregularityId]);
                $isIrregularityNew = ($stmtCheckExistence->rowCount() === 0);

                // Dados de LeadAlert (caso existam)
                $leadAlert = isset($irregularity['leadAlert']) ? $irregularity['leadAlert'] : null;
                $leadtype = $leadAlert ? $leadAlert['type'] : '';
                $numComments = $leadAlert ? $leadAlert['numComments'] : 0;
                $city = $leadAlert ? $leadAlert['city'] : '';
                $externalImageId = $leadAlert ? $leadAlert['externalImageId'] : '';
                $numThumbsUp = $leadAlert ? $leadAlert['numThumbsUp'] : 0;
                $street = $leadAlert ? $leadAlert['street'] : '';
                $subType = $leadAlert ? $leadAlert['subType'] : 'NO_SUBTYPE';
                $position = $leadAlert ? $leadAlert['position'] : '';
                $numNotThereReports = $leadAlert ? $leadAlert['numNotThereReports'] : 0;

                // Inserir ou atualizar irregularidade
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
                    ':is_active' => 1, // Sempre ativa ao ser processada
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
                    //':impact_score' => $impactScore
                ]);

                // Gravar coordenadas da linha (route_lines)
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

                // Enviar e-mail somente se a irregularidade for nova e nível >= 3

                if ($isIrregularityNew && $irregularity['jamLevel'] >= 3) {
                    $to = "andresoaresdiniz201218@gmail.com";
                    $subject = "🚨 Alerta de Congestionamento - {$irregularity['name']}";
                
                    // Coordenadas para o mapa
                    $centerX = ($irregularity['bbox']['minX'] + $irregularity['bbox']['maxX']) / 2;
                    $centerY = ($irregularity['bbox']['minY'] + $irregularity['bbox']['maxY']) / 2;
                    // Substitua a linha do mapa por:
$mapUrl = "https://static-maps.yandex.ru/1.x/?lang=pt_BR&ll={$centerX},{$centerY}&z=14&size=600,300&pt={$centerX},{$centerY},pm2rdl";
                
                    $message = '
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; }
                            .header { background-color: #005792; color: white; padding: 15px; border-radius: 10px 10px 0 0; }
                            .map { width: 100%; height: 300px; border: 1px solid #ddd; margin: 15px 0; }
                            .details { padding: 15px; background-color: #f8f9fa; border-radius: 5px; }
                            .alert-level { color: #dc3545; font-weight: bold; }
                            .footer { margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee; font-size: 0.9em; color: #666; }
                        </style>
                    </head>
                    <body>
                        <div class="container">
                            <div class="header">
                                <h2>🚨 Alerta de Tráfego</h2>
                            </div>
                            
                            <img src="'.$mapUrl.'" alt="Mapa da Área" class="map">
                            
                            <div class="details">
                                <h3>'.$irregularity['name'].'</h3>
                                
                                <table>
                                    <tr>
                                        <td><strong>Local:</strong></td>
                                        <td>'.$irregularity['fromName'].' para '.$irregularity['toName'].'</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Nível:</strong></td>
                                        <td><span class="alert-level">'.$irregularity['jamLevel'].'/5</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Tipo:</strong></td>
                                        <td>'.$irregularity['type'].' ('.$subType.')</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Extensão:</strong></td>
                                        <td>'.number_format($irregularity['length']/1000, 2).' km</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Velocidade:</strong></td>
                                        <td>'.number_format($avgSpeed, 1).' km/h</td>
                                    </tr>
                                </table>
                                
                                <h4>📌 Detalhes Adicionais</h4>
                                <ul>
                                    <li>Comentários: '.$numComments.'</li>
                                    <li>Confirmações: '.$numThumbsUp.'</li>
                                    <li>Relatos de inexistência: '.$numNotThereReports.'</li>
                                </ul>
                            </div>
                            
                            <div class="footer">
                                <p>🔔 Você está recebendo este e-mail porque se inscreveu em alertas de trânsito.</p>
                                <p><a href="[URL_DE_DESINSCRIÇÃO]" style="color: #005792;">Gerenciar preferências</a> | 
                                <a href="https://www.google.com/maps?q='.$centerY.','.$centerX.'" style="color: #005792;">Abrir no Maps</a></p>
                            </div>
                        </div>
                    </body>
                    </html>';

                    $message .= '<div style="font-size: 8px; color: #666;">
                            Map data © <a href="https://openstreetmap.org" style="color: #666;">OpenStreetMap</a> contributors
                        </div>';
                
                }
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

        // Desativar todas as irregularidades se nenhuma foi encontrada
        if (!$irregularitiesFound) {
            echo "Nenhuma irregularidade encontrada em nenhuma URL. Desativando todas...\n";
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