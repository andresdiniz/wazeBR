<?php
// Configurações do banco de dados
$host = 'localhost';
$db   = 'nome_do_banco';
$user = 'usuario';
$pass = 'senha';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die('Falha na conexão: ' . $e->getMessage());
}

// URL da API
$url = 'https://storage.googleapis.com/waze-tile-build-public/current-build/intl-status.json';

// Ler o JSON da URL
$json = file_get_contents($url);

if ($json === false) {
    die('Erro ao acessar a URL.');
}

// Decodificar JSON
$data = json_decode($json, true);

if ($data === null) {
    die('Erro ao decodificar o JSON.');
}

// Função para inserir os dados
function insertBuild($pdo, $buildType, $buildData) {
    $sql = "INSERT INTO waze_builds (
                build_type, build_id, build_status, last_edit_time, 
                estimated_completion_time, progress_percent, delay_minutes, 
                start_time, release_time, is_rollback, estimated_start_time
            ) VALUES (
                :build_type, :build_id, :build_status, :last_edit_time, 
                :estimated_completion_time, :progress_percent, :delay_minutes, 
                :start_time, :release_time, :is_rollback, :estimated_start_time
            )";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':build_type' => $buildType,
        ':build_id' => isset($buildData['build_id']) ? $buildData['build_id'] : null,
        ':build_status' => isset($buildData['build_status']) ? $buildData['build_status'] : null,
        ':last_edit_time' => isset($buildData['last_edit_time']) ? $buildData['last_edit_time'] : null,
        ':estimated_completion_time' => isset($buildData['estimated_completion_time']) ? $buildData['estimated_completion_time'] : null,
        ':progress_percent' => isset($buildData['progress_percent']) ? $buildData['progress_percent'] : null,
        ':delay_minutes' => isset($buildData['delay_minutes']) ? $buildData['delay_minutes'] : null,
        ':start_time' => isset($buildData['start_time']) ? $buildData['start_time'] : null,
        ':release_time' => isset($buildData['release_time']) ? $buildData['release_time'] : null,
        ':is_rollback' => isset($buildData['is_rollback']) ? (int)$buildData['is_rollback'] : null,
        ':estimated_start_time' => isset($buildData['estimated_start_time']) ? $buildData['estimated_start_time'] : null,
    ]);
}

// Inserir os dados de cada build
if (isset($data['current_build'])) {
    insertBuild($pdo, 'current', $data['current_build']);
}

if (isset($data['previous_build'])) {
    insertBuild($pdo, 'previous', $data['previous_build']);
}

if (isset($data['next_build'])) {
    insertBuild($pdo, 'next', $data['next_build']);
}

echo "Dados inseridos com sucesso.";
?>
