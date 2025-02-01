<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

set_time_limit(1200);
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';
require_once __DIR__ . '/functions/email_service.php'; // Novo arquivo para e-mails

$pdo = Database::getConnection();

// URLs para o JSON
$jsonUrls = [
    "https://www.waze.com/row-partnerhub-api/feeds-tvt/?id=1725279881116",
    "https://www.waze.com/row-partnerhub-api/feeds-tvt/?id=12699055487",
];

$irregularitiesFound = false;

foreach ($jsonUrls as $jsonUrl) {
    echo "Carregando dados da URL: $jsonUrl\n";

    try {
        // Obter dados JSON com tratamento de erros
        $jsonData = fetchJsonData($jsonUrl);
        $data = validateJsonData($jsonData, $jsonUrl);
        
        $pdo->beginTransaction();

        // Gerenciamento de URLs
        $urlId = handleUrl($pdo, $jsonUrl);

        // Processar usuários em congestionamentos
        processUsersOnJams($pdo, $data['usersOnJams'], $urlId);

        // Processar rotas principais
        foreach ($data['routes'] as $route) {
            processMainRoute($pdo, $route, $urlId);
        }

        // Processar irregularidades
        if (!empty($data['irregularities'])) {
            $irregularitiesFound = true;
            processIrregularities($pdo, $data['irregularities'], $urlId);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        logError("Erro processando $jsonUrl: " . $e->getMessage());
    }
}

// Gerar relatório diário após processar todas URLs
generateDailyReport($pdo);

// Funções de Suporte
function fetchJsonData($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    
    $data = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception("Erro cURL: " . curl_error($ch));
    }
    curl_close($ch);
    return $data;
}

function validateJsonData($jsonData, $url) {
    $data = json_decode($jsonData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON inválido de $url: " . json_last_error_msg());
    }
    return $data;
}

function handleUrl($pdo, $url) {
    $stmt = $pdo->prepare("SELECT id FROM urls WHERE url = ?");
    $stmt->execute([$url]);
    
    if ($stmt->rowCount() > 0) {
        return $stmt->fetchColumn();
    }
    
    $pdo->prepare("INSERT INTO urls (url) VALUES (?)")
        ->execute([$url]);
    return $pdo->lastInsertId();
}

function processUsersOnJams($pdo, $usersData, $urlId) {
    $stmt = $pdo->prepare("
        INSERT INTO users_on_jams (jam_level, wazers_count, url_id)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE wazers_count = VALUES(wazers_count)
    ");
    
    foreach ($usersData as $userJam) {
        $stmt->execute([
            $userJam['jamLevel'],
            $userJam['wazersCount'],
            $urlId
        ]);
    }
}

function processMainRoute($pdo, $route, $urlId) {
    // Cálculos de velocidade
    $avgSpeed = ($route['length'] / 1000) / ($route['time'] / 3600);
    $historicSpeed = ($route['length'] / 1000) / ($route['historicTime'] / 3600);

    // Inserir rota principal
    $stmt = $pdo->prepare("
        INSERT INTO routes (id, name, from_name, to_name, length, jam_level, time, type, 
        bbox_min_x, bbox_min_y, bbox_max_x, bbox_max_y, url_id, avg_speed, avg_time, 
        historic_speed, historic_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            jam_level = VALUES(jam_level),
            avg_speed = VALUES(avg_speed),
            historic_speed = VALUES(historic_speed)
    ");
    
    $stmt->execute([
        $route['id'],
        $route['name'],
        $route['fromName'],
        $route['toName'],
        $route['length'],
        $route['jamLevel'],
        $route['time'],
        $route['type'],
        $route['bbox']['minX'],
        $route['bbox']['minY'],
        $route['bbox']['maxX'],
        $route['bbox']['maxY'],
        $urlId,
        $avgSpeed,
        $route['time'],
        $historicSpeed,
        $route['historicTime']
    ]);

    processRouteLines($pdo, $route);
    processSubRoutes($pdo, $route);
}

function processRouteLines($pdo, $route) {
    if (empty($route['line'])) return;

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO route_lines (route_id, x, y)
        VALUES (?, ?, ?)
    ");
    
    foreach ($route['line'] as $point) {
        $stmt->execute([
            $route['id'],
            $point['x'],
            $point['y']
        ]);
    }
}

function processSubRoutes($pdo, $route) {
    if (empty($route['subRoutes'])) return;

    // Desativar sub-rotas antigas
    $pdo->prepare("UPDATE subroutes SET is_active = 0 WHERE route_id = ?")
        ->execute([$route['id']]);

    $stmt = $pdo->prepare("
        INSERT INTO subroutes (id, route_id, to_name, historic_time, length, jam_level, 
        time, type, bbox_min_x, bbox_min_y, bbox_max_x, bbox_max_y, avg_speed, 
        historic_speed, is_active, lead_alert_id, lead_alert_type, lead_alert_sub_type,
        lead_alert_position, lead_alert_num_comments, lead_alert_num_thumbs_up, 
        lead_alert_num_not_there_reports, lead_alert_street)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            is_active = 1,
            jam_level = VALUES(jam_level),
            avg_speed = VALUES(avg_speed)
    ");

    foreach ($route['subRoutes'] as $subRoute) {
        $avgSpeed = ($subRoute['length'] / 1000) / ($subRoute['time'] / 3600);
        $historicSpeed = ($subRoute['length'] / 1000) / ($subRoute['historicTime'] / 3600);
        
        $stmt->execute([
            $subRoute['id'] ?? uniqid('sub_'),
            $route['id'],
            $subRoute['toName'] ?? 'Indefinido',
            $subRoute['historicTime'],
            $subRoute['length'],
            $subRoute['jamLevel'],
            $subRoute['time'],
            $subRoute['type'],
            $subRoute['bbox']['minX'],
            $subRoute['bbox']['minY'],
            $subRoute['bbox']['maxX'],
            $subRoute['bbox']['maxY'],
            $avgSpeed,
            $historicSpeed,
            $subRoute['leadAlert']['id'] ?? null,
            $subRoute['leadAlert']['type'] ?? null,
            $subRoute['leadAlert']['subType'] ?? null,
            $subRoute['leadAlert']['position'] ?? null,
            $subRoute['leadAlert']['numComments'] ?? 0,
            $subRoute['leadAlert']['numThumbsUp'] ?? 0,
            $subRoute['leadAlert']['numNotThereReports'] ?? 0,
            $subRoute['leadAlert']['street'] ?? null
        ]);
    }
}

function processIrregularities($pdo, $irregularities, $urlId) {
    $irregularityStmt = $pdo->prepare("..."); // Consulta anterior atualizada
    
    // Desativar irregularidades antigas
    $pdo->prepare("UPDATE irregularities SET is_active = 0 WHERE url_id = ?")
        ->execute([$urlId]);

    foreach ($irregularities as $irreg) {
        $irregularityId = uniqid('irreg_', true);
        $impactScore = calculateImpactScore($irreg);
        
        // Inserir irregularidade
        $irregularityStmt->execute([
            $irregularityId,
            $irreg['name'],
            $irreg['fromName'],
            $irreg['toName'],
            $irreg['length'],
            $irreg['jamLevel'],
            $irreg['time'],
            $irreg['leadAlert']['type'] ?? null,
            $irreg['bbox']['minX'],
            $irreg['bbox']['minY'],
            $irreg['bbox']['maxX'],
            $irreg['bbox']['maxY'],
            1, // is_active
            $urlId,
            ($irreg['length']/1000)/($irreg['time']/3600),
            $irreg['time'],
            ($irreg['length']/1000)/($irreg['historicTime']/3600),
            $irreg['historicTime'],
            date('Y-m-d H:i:s'),
            $irreg['leadAlert']['numComments'] ?? 0,
            $irreg['leadAlert']['city'] ?? null,
            $irreg['leadAlert']['externalImageId'] ?? null,
            $irreg['leadAlert']['numThumbsUp'] ?? 0,
            $irreg['leadAlert']['street'] ?? null,
            $irreg['leadAlert']['subType'] ?? 'NO_SUBTYPE',
            $irreg['leadAlert']['position'] ?? null,
            $irreg['leadAlert']['numNotThereReports'] ?? 0,
            $impactScore
        ]);

        // Processar área de influência
        $areaData = calculateInfluenceArea($irreg['bbox']);
        $pdo->prepare("
            INSERT INTO congestion_areas 
            (id, irregularity_id, area, radius, population_density)
            VALUES (?, ?, ST_GeomFromText(?), ?, ?)
        ")->execute([
            uniqid('area_'),
            $irregularityId,
            $areaData['polygon'],
            $areaData['radius'],
            getPopulationDensity($areaData['center'])
        ]);

        // Processar alertas
        handleAlerts($pdo, $irregularityId, $irreg, $impactScore);
    }
}

function calculateImpactScore($irreg) {
    $lengthKm = $irreg['length'] / 1000;
    $durationHours = $irreg['time'] / 3600;
    $radius = calculateRadius($irreg['bbox']);
    
    return ($irreg['jamLevel'] * 0.5) + 
           ($lengthKm * 0.25) + 
           ($radius * 0.25) + 
           (log10($durationHours + 1) * 0.2);
}

function calculateRadius($bbox) {
    return sqrt(
        pow($bbox['maxX'] - $bbox['minX'], 2) + 
        pow($bbox['maxY'] - $bbox['minY'], 2)
    ) / 2;
}

function handleAlerts($pdo, $irregularityId, $irreg, $impactScore) {
    $stmt = $pdo->prepare("
        SELECT cooldown_until 
        FROM alerts_log 
        WHERE irregularity_id = ? 
        ORDER BY sent_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$irregularityId]);
    $lastAlert = $stmt->fetch();

    if (!$lastAlert || new DateTime() > new DateTime($lastAlert['cooldown_until'])) {
        $estimatedEnd = estimateResolution($irreg);
        sendEnhancedAlert($irreg, $impactScore, $estimatedEnd);
        
        $pdo->prepare("
            INSERT INTO alerts_log 
            (id, irregularity_id, sent_at, recipients, cooldown_until)
            VALUES (?, ?, NOW(), ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
        ")->execute([
            uniqid('alert_'),
            $irregularityId,
            'andresoaresdiniz201218@gmail.com,outro@email.com'
        ]);
    }
}

function generateDailyReport($pdo) {
    try {
        $reportDate = date('Y-m-d');
        $stmt = $pdo->prepare("
            INSERT INTO daily_reports 
            (id, report_date, total_congestions, avg_duration, max_jam_level, affected_area, hotspots)
            SELECT 
                ?, 
                CURDATE(),
                COUNT(*),
                AVG(time),
                MAX(jam_level),
                ST_Union(area),
                JSON_ARRAYAGG(id)
            FROM irregularities
            WHERE DATE(created_at) = CURDATE() - INTERVAL 1 DAY
        ");
        
        $stmt->execute([uniqid('rep_')]);
    } catch (Exception $e) {
        logError("Erro gerando relatório: " . $e->getMessage());
    }
}

// functions/email_service.php
function sendEnhancedAlert($irreg, $score, $estimatedEnd) {
    $to = "andresoaresdiniz201218@gmail.com";
    $subject = "🚨 Alerta de Tráfego: {$irreg['name']} (Score: " . number_format($score,1) . ")";
    
    $mapUrl = generateStaticMap($irreg['bbox']);
    
    $message = "
        <html>
        <body>
            <h2>{$irreg['name']}</h2>
            <img src='$mapUrl' alt='Mapa da Área'>
            <p>Nível: {$irreg['jamLevel']}/5</p>
            <p>Previsão de Resolução: " . date('H:i', $estimatedEnd) . "</p>
            <p>Impacto Estimado: " . number_format($score,1) . "/10</p>
        </body>
        </html>
    ";
    
    return sendEmail($to, $message, $subject);
}