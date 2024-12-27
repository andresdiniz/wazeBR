<?php
// Configuração do banco de dados
$host = 'localhost';
$dbname = 'incidents_db';
$username = 'root';
$password = '';

// Conectar ao banco de dados
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Erro de conexão: " . $e->getMessage();
    exit;
}

// Ler os dados JSON enviados pela requisição POST
$dados_json = file_get_contents('php://input');
$data = json_decode($dados_json, true);

// Verificar se os dados foram recebidos corretamente
if ($data === null) {
    echo json_encode(['error' => 'Dados inválidos']);
    exit;
}

// Verificar se a data de início (starttime) foi fornecida
if (empty($data['starttime'])) {
    echo json_encode(['error' => 'A data de início (starttime) é obrigatória']);
    exit;
}

// Inserir os dados da fonte (se fornecido)
$source_id = null;
if (isset($data['source'])) {
    $stmt = $pdo->prepare("INSERT INTO sources (reference, name, url) VALUES (?, ?, ?)");
    $stmt->execute([
        $data['source']['reference'] ?? null,
        $data['source']['name'] ?? null,
        $data['source']['url'] ?? null
    ]);
    $source_id = $pdo->lastInsertId(); // Obter o ID da fonte inserida
}

// Inserir o incidente
$stmt = $pdo->prepare("INSERT INTO incidents (parent_event_id, creationtime, updatetime, source_id, type, subtype, description, street, polyline, direction, starttime, endtime, timestamp) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([
    $data['parent_event']['id'] ?? null,
    $data['creationtime'] ?? null,
    $data['updatetime'] ?? null,
    $source_id,
    $data['type'] ?? null,
    $data['subtype'] ?? null,
    $data['description'] ?? null,
    $data['street'] ?? null,
    $data['polyline'] ?? null,
    $data['direction'] ?? null,
    $data['starttime'], // Data de início é obrigatória
    $data['endtime'] ?? null,  // Data de fim é opcional
    $data['timestamp'] ?? null // Timestamp é opcional
]);
$incident_id = $pdo->lastInsertId(); // Obter o ID do incidente inserido

// Inserir dados de impacto de faixa (se houver)
if (isset($data['lane_impact'])) {
    $stmt = $pdo->prepare("INSERT INTO lane_impacts (incident_id, total_closed_lanes, roadside) VALUES (?, ?, ?)");
    $stmt->execute([
        $incident_id,
        $data['lane_impact']['total_closed_lanes'] ?? null,
        $data['lane_impact']['roadside'] ?? null
    ]);
}

// Inserir dados das faixas (se houver)
if (isset($data['lanes'])) {
    foreach ($data['lanes'] as $lane) {
        $stmt = $pdo->prepare("INSERT INTO lanes (incident_id, lane_order, type, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $incident_id,
            $lane['order'],
            $lane['type'],
            $lane['status']
        ]);
    }
}

// Inserir o cronograma (se houver)
if (isset($data['schedule'])) {
    foreach ($data['schedule'] as $day => $times) {
        foreach ($times as $time_range) {
            list($start_time, $end_time) = explode('-', $time_range);
            $stmt = $pdo->prepare("INSERT INTO schedule (incident_id, weekday, start_time, end_time) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $incident_id,
                $day,
                $start_time,
                $end_time
            ]);
        }
    }
}

echo json_encode(['success' => 'Evento inserido com sucesso!']);
?>
