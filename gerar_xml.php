<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

ini_set('log_errors', 1);
ini_set('error_log', 'error_log.log');

require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/config/configs.php';

try {
    // Conectar ao banco de dados
    $pdo = Database::getConnection();
} catch (PDOException $e) {
    die("Erro ao conectar ao banco de dados: " . $e->getMessage());
}

// Obter a data e hora atual
$currentDateTime = date('Y-m-d H:i:s');
$TIMEZONE;

// Atualizar eventos cujo endtime já passou para is_active = 2
$updateQuery = "
    UPDATE events 
    SET is_active = 2 
    WHERE endtime < :currentDateTime AND is_active = 1
";
$updateStmt = $pdo->prepare($updateQuery);
$updateStmt->bindParam(':currentDateTime', $currentDateTime, PDO::PARAM_STR);
$updateStmt->execute();

// Consulta para eventos ativos (is_active = 1) e dados relacionados, incluindo agendamentos
$query = "
    SELECT 
        e.id AS event_id, e.parent_event_id, e.creationtime, e.updatetime,
        e.type, e.subtype, e.description, e.street, e.polyline, e.direction,
        e.starttime, e.endtime,
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
        e.is_active = 1 AND e.endtime >= :currentDateTime -- Filtra eventos ativos cujo endtime ainda não passou
    ORDER BY 
        e.id, s.id, l.id, sc.id
";

$statement = $pdo->prepare($query);
$statement->bindParam(':currentDateTime', $currentDateTime, PDO::PARAM_STR);
$statement->execute();
$rows = $statement->fetchAll(PDO::FETCH_ASSOC);

// Organizar dados em estrutura hierárquica
$events = [];
foreach ($rows as $row) {
    $eventId = $row['event_id'];

    if (!isset($events[$eventId])) {
        $events[$eventId] = [
            'id' => $row['event_id'],
            'parent_event_id' => $row['parent_event_id'],
            'creationtime' => $row['creationtime'],
            'updatetime' => $row['updatetime'],
            'type' => $row['type'],
            'subtype' => $row['subtype'],
            'description' => mb_substr($row['description'], 0, 40), // Limita a descrição a 40 caracteres
            'street' => $row['street'],
            'polyline' => $row['polyline'],
            'direction' => $row['direction'],
            'starttime' => $row['starttime'],
            'endtime' => $row['endtime'],
            'sources' => [],
            'lane_impacts' => [],
            'schedules' => [], // Adicionando o campo para armazenar agendamentos
        ];
    }

    if ($row['source_id']) {
        $events[$eventId]['sources'][] = [
            'reference' => $row['reference'],
            'name' => $row['source_name'],
            'url' => $row['source_url'],
        ];
    }

    if ($row['lane_impact_id']) {
        $events[$eventId]['lane_impacts'][] = [
            'total_closed_lanes' => $row['total_closed_lanes'],
            'roadside' => $row['roadside'],
        ];
    }

    // Adicionando agendamento se existir
    if ($row['day_of_week']) {
        $events[$eventId]['schedules'][] = [
            'day_of_week' => $row['day_of_week'],
            'start_time' => $row['schedule_start_time'],
            'end_time' => $row['schedule_end_time'],
        ];
    }
}

// Criar XML com formatação
$xml = new DOMDocument('1.0', 'UTF-8');
$xml->formatOutput = true;

// Criar o elemento raiz "incidents"
$root = $xml->createElement('incidents');

// Adicionar os atributos de namespace e esquema
$root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
$root->setAttribute('xsi:noNamespaceSchemaLocation', 'https://www.gstatic.com/road-incidents/cifsv2.xsd');

// Adicionar o elemento raiz ao XML
$xml->appendChild($root);

// Adicionar eventos ao XML
foreach ($events as $event) {
    $eventNode = $xml->createElement('incident');
    $eventNode->setAttribute('id', $event['id']);
    if ($event['parent_event_id']) {
        $eventNode->setAttribute('parent_event_id', $event['parent_event_id']);
    }

    // Adicionar elementos obrigatórios
    foreach (['type', 'street', 'polyline', 'starttime'] as $key) {
        if (!empty($event[$key])) {
            $eventNode->appendChild($xml->createElement($key, htmlspecialchars($event[$key])));
        }
    }

    // Adicionar elementos opcionais
    foreach (['direction', 'endtime', 'description', 'subtype'] as $key) {
        if (!empty($event[$key])) {
            $eventNode->appendChild($xml->createElement($key, htmlspecialchars($event[$key])));
        }
    }

    // Adicionar fontes
    if (!empty($event['sources'])) {
        $sourcesNode = $xml->createElement('sources');
        foreach ($event['sources'] as $source) {
            $sourceNode = $xml->createElement('source');
            $sourceNode->appendChild($xml->createElement('reference', htmlspecialchars($source['reference'])));
            $sourceNode->appendChild($xml->createElement('name', htmlspecialchars($source['name'])));
            if (!empty($source['url'])) {
                $sourceNode->appendChild($xml->createElement('url', htmlspecialchars($source['url'])));
            }
            $sourcesNode->appendChild($sourceNode);
        }
        $eventNode->appendChild($sourcesNode);
    }

    // Adicionar impactos nas faixas
    if (!empty($event['lane_impacts'])) {
        $laneImpactsNode = $xml->createElement('lane_impacts');
        foreach ($event['lane_impacts'] as $impact) {
            $impactNode = $xml->createElement('lane_impact');
            $impactNode->appendChild($xml->createElement('total_closed_lanes', htmlspecialchars($impact['total_closed_lanes'])));
            if (!empty($impact['roadside'])) {
                $impactNode->appendChild($xml->createElement('roadside', htmlspecialchars($impact['roadside'])));
            }
            $laneImpactsNode->appendChild($impactNode);
        }
        $eventNode->appendChild($laneImpactsNode);
    }

    // Adicionar agendamentos
    if (!empty($event['schedules'])) {
        $schedulesNode = $xml->createElement('schedules');
        foreach ($event['schedules'] as $schedule) {
            $scheduleNode = $xml->createElement('schedule');
            $scheduleNode->appendChild($xml->createElement('day_of_week', htmlspecialchars($schedule['day_of_week'])));
            $scheduleNode->appendChild($xml->createElement('start_time', htmlspecialchars($schedule['start_time'])));
            $scheduleNode->appendChild($xml->createElement('end_time', htmlspecialchars($schedule['end_time'])));
            $schedulesNode->appendChild($scheduleNode);
        }
        $eventNode->appendChild($schedulesNode);
    }

    $root->appendChild($eventNode);
}

// Exibir ou salvar o XML
header('Content-Type: application/xml; charset=utf-8');
$xml->save('events.xml');
echo $xml->saveXML();

?>