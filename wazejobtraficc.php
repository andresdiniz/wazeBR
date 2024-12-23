<?php
set_time_limit(1200);  // Define o tempo limite para 5 minutos (300 segundos)

require_once __DIR__ . '/config/configbd.php';

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

            if (isset($route['line']) && is_array($route['line'])) {
                echo "Processando linha da rota: {$route['toName']}\n";
            
                $routeId = $route['id'];
                $linePoints = $route['line'];
            
                // Buscar todos os pontos já existentes para a rota
                $stmtFetchExisting = $pdo->prepare("
                    SELECT CONCAT(x, ',', y) AS point_key
                    FROM route_lines
                    WHERE route_id = :route_id
                ");
                $stmtFetchExisting->execute([':route_id' => $routeId]);
                $existingPoints = $stmtFetchExisting->fetchAll(PDO::FETCH_COLUMN);
            
                // Criar um conjunto para checagem rápida
                $existingPointsSet = array_flip($existingPoints); // Transforma em um array associativo para busca O(1)
            
                $pointsToInsert = []; // Lista para acumular os pontos que precisam ser inseridos
            
                // Iterar sobre cada ponto e verificar se já existe
                foreach ($linePoints as $point) {
                    $x = $point['x'];
                    $y = $point['y'];
                    $pointKey = "{$x},{$y}";
            
                    if (!isset($existingPointsSet[$pointKey])) {
                        // Adicionar o ponto na lista de inserção
                        $pointsToInsert[] = [
                            ':route_id' => $routeId,
                            ':x' => $x,
                            ':y' => $y,
                        ];
            
                        echo "Ponto a ser inserido: x = $x, y = $y\n";
                    } else {
                        echo "Linha já existe para a rota: {$route['toName']}, ponto: x = $x, y = $y\n";
                    }
                }
            
                // Inserir todos os novos pontos de uma vez
                if (!empty($pointsToInsert)) {
                    $insertQuery = "
                        INSERT INTO route_lines (route_id, x, y)
                        VALUES " . implode(',', array_fill(0, count($pointsToInsert), "(?, ?, ?)"));
                    
                    $stmtInsert = $pdo->prepare($insertQuery);
            
                    // Flatten a lista de valores para execução
                    $flattenedValues = [];
                    foreach ($pointsToInsert as $point) {
                        $flattenedValues[] = $point[':route_id'];
                        $flattenedValues[] = $point[':x'];
                        $flattenedValues[] = $point[':y'];
                    }
            
                    $stmtInsert->execute($flattenedValues);
                    echo "Inserção em batch concluída: " . count($pointsToInsert) . " pontos inseridos.\n";
                } else {
                    echo "Nenhum ponto novo para inserir na rota: {$route['toName']}\n";
                }
            } else {
                echo "Nenhuma linha encontrada para a rota: {$route['toName']}\n";
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
                    ':num_not_there_reports' => $numNotThereReports
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
                    $subject = "Alerta de Congestionamento na Estação {$irregularity['name']}";
                    $message = "Atenção! Uma irregularidade de tráfego foi detectada:\n\n" .
                        "Nome: {$irregularity['name']}\n" .
                        "Nível de Congestionamento: {$irregularity['jamLevel']}\n" .
                        "Tipo: {$irregularity['type']}\n" .
                        "Local: {$irregularity['fromName']} para {$irregularity['toName']}\n" .
                        "Coordenadas: ({$irregularity['bbox']['minX']}, {$irregularity['bbox']['minY']}) a ({$irregularity['bbox']['maxX']}, {$irregularity['bbox']['maxY']})\n\n" .
                        "Por favor, tome as devidas precauções.";

                    $headers = "From: atendimento@clouatacado.com";

                    if (mail($to, $subject, $message, $headers)) {
                        echo "Alerta de e-mail enviado para $to.\n";
                    } else {
                        echo "Erro ao enviar o alerta de e-mail.\n";
                    }
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
