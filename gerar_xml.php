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

/**
 * Função para atualizar o UUID de eventos ativos a cada 5 minutos
 */
$currentDateTime = date('Y-m-d H:i:s');

/**
 * Atualiza os UUIDs apenas se passaram 10 minutos desde a última atualização
 */
function atualizarUUIDsSeNecessario($pdo) {
    // Buscar a última atualização do banco de dados (UTC)
    $checkQuery = "SELECT MAX(ultima_atualizacao) AS ultima FROM events WHERE is_active = 1";
    $stmt = $pdo->query($checkQuery);
    $ultimaAtualizacaoUTC = $stmt->fetch(PDO::FETCH_ASSOC)['ultima'];

    if (!$ultimaAtualizacaoUTC) {
        // Se não houver última atualização, força uma atualização agora
        atualizarUUIDs($pdo);
        return;
    }

    // Converter UTC para UTC-3 (São Paulo)
    $ultimaAtualizacao = new DateTime($ultimaAtualizacaoUTC, new DateTimeZone('UTC'));
    $ultimaAtualizacao->setTimezone(new DateTimeZone('America/Sao_Paulo'));

    // Tempo atual em UTC-3
    $agora = new DateTime();

    // Diferença entre o tempo atual e a última atualização
    $diferencaMinutos = ($agora->getTimestamp() - $ultimaAtualizacao->getTimestamp()) / 60;

    // Só atualiza se passaram pelo menos 10 minutos
    if ($diferencaMinutos >= 10) {
        atualizarUUIDs($pdo);
    }
    echo number_format($diferencaMinutos, 2) . " minutos desde a última atualização\n";}

/**
 * Atualiza os UUIDs no banco de dados
 */
function atualizarUUIDs($pdo) {
    $agora = new DateTime();
    $agoraFormatado = $agora->format('Y-m-d H:i:s');

    $updateUUIDQuery = "
        UPDATE events 
        SET uuid = UUID(), ultima_atualizacao = :agora
        WHERE is_active = 1
          AND endtime >= :agora
    ";

    $stmt = $pdo->prepare($updateUUIDQuery);
    $stmt->bindParam(':agora', $agoraFormatado, PDO::PARAM_STR);
    $stmt->execute();

    echo "UUIDs atualizados em " . $agoraFormatado . " (UTC-3)\n";
}

// 🔴 Chamar a função no início do script
atualizarUUIDsSeNecessario($pdo);

// 🔴 Atualizar eventos expirados para is_active = 2
$updateQuery = "
    UPDATE events 
    SET is_active = 2 
    WHERE endtime < :currentDateTime AND is_active = 1
";
$updateStmt = $pdo->prepare($updateQuery);
$updateStmt->bindParam(':currentDateTime', $currentDateTime, PDO::PARAM_STR);
$updateStmt->execute();

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

// 🔴 Garantir que todos os parceiros tenham arquivos, mesmo sem eventos
foreach ($parceiros as $idParceiro) {
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;

    $root = $xml->createElement('incidents');
    $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
    $root->setAttribute('xsi:noNamespaceSchemaLocation', 'https://www.gstatic.com/road-incidents/cifsv2.xsd');
    $xml->appendChild($root);

    if (!empty($eventosPorParceiro[$idParceiro])) {
        foreach ($eventosPorParceiro[$idParceiro] as $event) {
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
    }

    $xmlPath = __DIR__ . "/events" . $idParceiro . ".xml";
    $xml->save($xmlPath);
    echo "Arquivo XML atualizado para parceiro {$idParceiro}: {$xmlPath}\n";
}

?>
