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

// 🔴 Atualizar eventos expirados para is_active = 2
$updateQuery = "
    UPDATE events 
    SET is_active = 2 
    WHERE endtime < :currentDateTime AND is_active = 1
";
$updateStmt = $pdo->prepare($updateQuery);
$updateStmt->bindParam(':currentDateTime', $currentDateTime, PDO::PARAM_STR);
$updateStmt->execute();

// 🔴 Buscar apenas eventos ativos
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

$eventosPorParceiro = [];
foreach ($rows as $row) {
    $idParceiro = $row['id_parceiro'];

    if (!isset($eventosPorParceiro[$idParceiro])) {
        $eventosPorParceiro[$idParceiro] = [];
    }

    $eventUuid = $row['event_uuid'];

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
        $eventosPorParceiro[$idParceiro][$eventUuid]['sources'][] = [
            'reference' => $row['reference'],
            'name' => $row['source_name'],
            'url' => $row['source_url'],
        ];
    }

    if ($row['lane_impact_id']) {
        $eventosPorParceiro[$idParceiro][$eventUuid]['lane_impacts'][] = [
            'total_closed_lanes' => $row['total_closed_lanes'],
            'roadside' => $row['roadside'],
        ];
    }

    if ($row['day_of_week']) {
        $eventosPorParceiro[$idParceiro][$eventUuid]['schedules'][] = [
            'day_of_week' => $row['day_of_week'],
            'start_time' => $row['schedule_start_time'],
            'end_time' => $row['schedule_end_time'],
        ];
    }
}

// 🔴 Gerar XMLs e garantir remoção de eventos inativos
foreach ($eventosPorParceiro as $idParceiro => $eventos) {
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;

    $root = $xml->createElement('incidents');
    $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
    $root->setAttribute('xsi:noNamespaceSchemaLocation', 'https://www.gstatic.com/road-incidents/cifsv2.xsd');
    $xml->appendChild($root);

    foreach ($eventos as $event) {
        $eventNode = $xml->createElement('incident');
        $eventNode->setAttribute('id', $event['uuid']);
        if ($event['parent_event_id']) {
            $eventNode->setAttribute('parent_event_id', $event['parent_event_id']);
        }

        foreach (['type', 'street', 'polyline', 'starttime', 'direction', 'endtime', 'description', 'subtype'] as $key) {
            if (!empty($event[$key])) {
                $eventNode->appendChild($xml->createElement($key, htmlspecialchars($event[$key])));
            }
        }

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

        $root->appendChild($eventNode);
    }

    $xmlPath = __DIR__ . "/events" . $idParceiro . ".xml";

    // 🔴 Se não houver eventos, gerar um XML vazio
    if (empty($eventos)) {
        file_put_contents($xmlPath, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<incidents xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xsi:noNamespaceSchemaLocation=\"https://www.gstatic.com/road-incidents/cifsv2.xsd\"></incidents>");
        echo "Arquivo XML atualizado para parceiro {$idParceiro}, agora vazio.\n";
    } else {
        $xml->save($xmlPath);
        echo "Arquivo XML atualizado para parceiro {$idParceiro}: {$xmlPath}\n";
    }
}

// 🔴 Atualizar UUIDs a cada 5 minutos
if (time() % (5 * 60) == 0) {
    $updateUuidQuery = "
        UPDATE events
        SET uuid = UUID()
        WHERE is_active = 1 AND endtime >= :currentDateTime
    ";
    $updateUuidStmt = $pdo->prepare($updateUuidQuery);
    $updateUuidStmt->bindParam(':currentDateTime', $currentDateTime, PDO::PARAM_STR);
    $updateUuidStmt->execute();

    echo "UUIDs atualizados.\n";
}

?>