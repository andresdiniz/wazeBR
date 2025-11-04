<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'error_log.log');

require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/config/configs.php';

try {
    $pdo = Database::getConnection();
} catch (PDOException $e) {
    die("Erro ao conectar ao banco de dados: " . $e->getMessage());
}

$currentDateTime = date('Y-m-d H:i:s');

// ====================================================================
// NOVAS FUNÇÕES AUXILIARES
// ====================================================================

/**
 * Gera um ID único longo (UUID) para o JSON.
 * Usado para o evento duplicado, garantindo que o 'id' nunca seja vazio.
 * @return string
 */
function generateWazeLikeUuid(): string {
    // Usa hash SHA-256 de um ID único para simular um UUID longo e não-nulo
    return hash('sha256', uniqid(true) . microtime()); 
}

/**
 * Inverte a string da polyline.
 * Ex: 'lat1, lon1, lat2, lon2' se torna 'lat2, lon2, lat1, lon1'.
 * * @param string $polylineString A polyline no formato 'lat, lon, lat, lon...'
 * @return string A polyline invertida.
 */
function invertPolyline(string $polylineString): string {
    // Divide a string em coordenadas individuais
    $coords = array_map('trim', explode(',', $polylineString));
    
    // Agrupa em pares de (lat, lon)
    $pairs = [];
    for ($i = 0; $i < count($coords); $i += 2) {
        // Garante que o par completo existe
        if (isset($coords[$i + 1])) {
            $pairs[] = [$coords[$i], $coords[$i + 1]];
        }
    }
    
    // Inverte a ordem dos pares
    $reversedPairs = array_reverse($pairs);
    
    // Reconstrói a string no formato 'lat, lon, lat, lon...'
    $invertedCoords = [];
    foreach ($reversedPairs as $pair) {
        $invertedCoords[] = $pair[0]; // lat
        $invertedCoords[] = $pair[1]; // lon
    }
    
    return implode(', ', $invertedCoords);
}

// ====================================================================
// FIM DAS FUNÇÕES AUXILIARES
// ====================================================================


// 🔴 Buscar parceiros distintos
$parceiroQuery = "SELECT DISTINCT id_parceiro FROM events";
$parceiroStmt = $pdo->prepare($parceiroQuery);
$parceiroStmt->execute();
$parceiros = $parceiroStmt->fetchAll(PDO::FETCH_COLUMN);

// 🔴 Buscar eventos ativos e não expirados
$query = "
    SELECT 
        e.uuid AS event_uuid, e.id, e.parent_event_id, e.creationtime, e.updatetime,
        e.type, e.subtype, e.description, e.street, e.polyline, e.direction,
        e.starttime, e.endtime, e.id_parceiro, 
        s.id AS source_id, s.reference, s.name AS source_name, s.url AS source_url,
        l.id AS lane_impact_id, l.total_closed_lanes, l.roadside,
        sc.day_of_week, sc.start_time AS schedule_start_time, sc.end_time AS schedule_end_time
    FROM 
        events e
    LEFT JOIN 
        sources s ON e.id = s.event_id
    LEFT JOIN 
        lane_impacts l ON e.id = l.event_id
    LEFT JOIN 
        schedules sc ON e.id = sc.event_id
    WHERE 
        e.is_active = 1
        AND e.endtime >= :currentDateTime
    ORDER BY 
        e.id_parceiro, e.uuid, s.id, l.id, sc.id
";

$statement = $pdo->prepare($query);
$statement->bindParam(':currentDateTime', $currentDateTime, PDO::PARAM_STR);
$statement->execute();
$rows = $statement->fetchAll(PDO::FETCH_ASSOC);

// 🔴 Organizar eventos por parceiro
$eventosPorParceiro = [];
foreach ($rows as $row) {
    $idParceiro = $row['id_parceiro'];

    if (!isset($eventosPorParceiro[$idParceiro])) {
        $eventosPorParceiro[$idParceiro] = [];
    }

    $eventUuid = $row['event_uuid'];

    // Se o UUID estiver vazio por algum erro no DB, geramos um temporário para evitar falha no JSON
    if (empty($eventUuid)) {
        // ATENÇÃO: Se o UUID for null aqui, o problema é na INSERÇÃO.
        // Gerar um UUID temporário para a exportação
        $eventUuid = generateWazeLikeUuid(); 
        // Associa-o temporariamente ao evento (apenas no array PHP)
        $row['event_uuid'] = $eventUuid;
    }


    if (!isset($eventosPorParceiro[$idParceiro][$eventUuid])) {
        $eventosPorParceiro[$idParceiro][$eventUuid] = [
            'uuid' => $eventUuid,
            'parent_event_id' => $row['parent_event_id'],
            'creationtime' => $row['creationtime'],
            'updatetime' => $row['updatetime'],
            'type' => $row['type'],
            'subtype' => $row['subtype'],
            'description' => mb_substr($row['description'], 0, 250),
            'street' => $row['street'],
            'polyline' => $row['polyline'],
            'direction' => $row['direction'],
            'starttime' => $row['starttime'],
            'endtime' => $row['endtime'],
            'sources' => [],
            'lane_impacts' => [],
            'schedules' => [],
        ];
    }    

    if ($row['source_id']) {
        // Evita duplicatas se o JOIN retornar várias linhas
        $sourceData = [
            'reference' => $row['reference'],
            'name' => $row['source_name'],
            'url' => $row['source_url'],
        ];
        if (!in_array($sourceData, $eventosPorParceiro[$idParceiro][$eventUuid]['sources'])) {
            $eventosPorParceiro[$idParceiro][$eventUuid]['sources'][] = $sourceData;
        }
    }

    if ($row['lane_impact_id']) {
        // Evita duplicatas se o JOIN retornar várias linhas
        $impactData = [
            'total_closed_lanes' => $row['total_closed_lanes'],
            'roadside' => $row['roadside'],
        ];
        if (!in_array($impactData, $eventosPorParceiro[$idParceiro][$eventUuid]['lane_impacts'])) {
             $eventosPorParceiro[$idParceiro][$eventUuid]['lane_impacts'][] = $impactData;
        }
    }

    if ($row['day_of_week']) {
        // Evita duplicatas se o JOIN retornar várias linhas
        $scheduleData = [
            'day_of_week' => $row['day_of_week'],
            'start_time' => $row['schedule_start_time'],
            'end_time' => $row['schedule_end_time'],
        ];
        if (!in_array($scheduleData, $eventosPorParceiro[$idParceiro][$eventUuid]['schedules'])) {
            $eventosPorParceiro[$idParceiro][$eventUuid]['schedules'][] = $scheduleData;
        }
    }
}

// 🔴 Lógica de Geração de JSON e Duplicação Condicional
foreach ($parceiros as $idParceiro) {
    $incidents = [];

    if (!empty($eventosPorParceiro[$idParceiro])) {
        foreach ($eventosPorParceiro[$idParceiro] as $event) {
            
            // 1. Incidente Principal (Direto do DB)
            $incident = [
                'id' => $event['uuid'],
                'creationtime' => $event['creationtime'],
                'updatetime' => $event['updatetime'],
                'description' => $event['description'],
                'street' => $event['street'],
                'direction' => $event['direction'],
                'polyline' => $event['polyline'],
                'starttime' => $event['starttime'],
                'endtime' => $event['endtime'],
                'type' => $event['type'],
            ];

            // Adiciona campos opcionais/detalhes
            if (!empty($event['subtype'])) {
                $incident['subtype'] = $event['subtype'];
            }
            if (!empty($event['sources'])) {
                $incident['sources'] = $event['sources'];
            }
            if (!empty($event['lane_impacts'])) {
                $incident['lane_impacts'] = $event['lane_impacts'];
            }
            if (!empty($event['schedules'])) {
                $incident['schedules'] = $event['schedules'];
            }
            
            // Adiciona o incidente principal
            $incidents[] = $incident;


            // 2. Lógica de Duplicação Condicional
            if ($event['type'] === 'ROAD_CLOSED' && $event['direction'] === 'BOTH_DIRECTION') {
                
                // Inverter a polyline
                $invertedPolyline = invertPolyline($event['polyline']);
                
                // Clona o incidente original
                $invertedIncident = $incident; 
                
                // Aplica as modificações para o evento reverso:
                // a) NOVO UUID: Garante que é único e não-nulo
                $invertedIncident['id'] = generateWazeLikeUuid(); 
                // b) DIREÇÃO: O evento reverso é SEMPRE 'ONE_DIRECTION'
                $invertedIncident['direction'] = 'ONE_DIRECTION'; 
                // c) POLYLINE: Coordenadas invertidas
                $invertedIncident['polyline'] = $invertedPolyline; 
                
                // Adiciona o incidente reverso à lista JSON (SEM INSERÇÃO NO DB)
                $incidents[] = $invertedIncident;
            }
        }
    }

    // Criar JSON para o parceiro
    $json = [
        'incidents' => $incidents,
    ];

    $jsonPath = __DIR__ . "/events_parceiro_{$idParceiro}.json";
    file_put_contents($jsonPath, json_encode($json, JSON_PRETTY_PRINT));
    echo "Arquivo JSON atualizado para parceiro {$idParceiro}: {$jsonPath}\n";
}

// 🔴 Atualizar eventos expirados para is_active = 2
$updateQuery = "
    UPDATE events 
    SET is_active = 2 
    WHERE endtime < :currentDateTime AND is_active = 1
";
$updateStmt = $pdo->prepare($updateQuery);
$updateStmt->bindParam(':currentDateTime', $currentDateTime, PDO::PARAM_STR);
$updateStmt->execute();
?>