<?php
require_once __DIR__ . '/config/configbd.php';

try {
    // Conectar ao banco de dadosn  TESTE GIT
    $pdo = Database::getConnection();
} catch (PDOException $e) {
    die("Erro ao conectar ao banco de dados: " . $e->getMessage());
}

// Consulta para eventos e dados relacionados
$query = "
    SELECT 
        e.id AS event_id, e.parent_event_id, e.creationtime, e.updatetime,
        e.type, e.subtype, e.description, e.street, e.polyline, e.direction,
        e.starttime, e.endtime,
        s.id AS source_id, s.reference, s.name AS source_name, s.url AS source_url,
        l.id AS lane_impact_id, l.total_closed_lanes, l.roadside
    FROM 
        events e
    LEFT JOIN 
        sources s ON e.id = s.event_id
    LEFT JOIN 
        lane_impacts l ON e.id = l.event_id
    ORDER BY e.id, s.id, l.id
";

$statement = $pdo->prepare($query);
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
            'description' => $row['description'],
            'street' => $row['street'],
            'polyline' => $row['polyline'],
            'direction' => $row['direction'],
            'starttime' => $row['starttime'],
            'endtime' => $row['endtime'],
            'sources' => [],
            'lane_impacts' => [],
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
}

// Criar XML com formatação
$xml = new DOMDocument('1.0', 'UTF-8');
$xml->formatOutput = true;

// Elemento raiz
$root = $xml->createElement('events');
$xml->appendChild($root);

// Adicionar eventos ao XML
foreach ($events as $event) {
    $eventNode = $xml->createElement('event');
    $eventNode->setAttribute('id', $event['id']);
    if ($event['parent_event_id']) {
        $eventNode->setAttribute('parent_event_id', $event['parent_event_id']);
    }

    // Adicionar elementos de dados do evento
    foreach (['creationtime', 'updatetime', 'type', 'subtype', 'description', 'street', 'polyline', 'direction', 'starttime', 'endtime'] as $key) {
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

    $root->appendChild($eventNode);
}

// Exibir ou salvar o XML
header('Content-Type: application/xml; charset=utf-8');
echo $xml->saveXML();
