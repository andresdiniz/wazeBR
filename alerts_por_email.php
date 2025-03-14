<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(1200);

require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

$pdo = Database::getConnection();

// Função para definir o status e a cor
function getStatus($value, $overallAvg) {
    if ($overallAvg == 0) return ['danger', 'Crítico', '#ff4d4d']; // Vermelho forte
    if ($value >= $overallAvg * 1.20) return ['success', 'Excelente', '#28a745']; // Verde
    if ($value >= $overallAvg * 1.10) return ['info', 'Bom', '#17a2b8']; // Azul claro
    if ($value >= $overallAvg * 0.95) return ['primary', 'Normal', '#007bff']; // Azul padrão
    if ($value >= $overallAvg * 0.85) return ['warning', 'Atenção', '#ffc107']; // Amarelo
    return ['danger', 'Crítico', '#ff4d4d']; // Vermelho forte
}

// Buscar todas as rotas ativas
$sql = "SELECT * FROM routes";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Iniciando análise de rotas...<br>";

// Array para armazenar alertas agrupados por usuário
$alertasPorUsuario = [];

foreach ($routes as $route) {
    $historicDataStmt = $pdo->prepare("SELECT velocidade, tempo FROM historic_routes WHERE route_id = ? ORDER BY data ASC");
    $historicDataStmt->execute([$route['id']]);
    $historicData = $historicDataStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($historicData)) continue;

    // Separar os dados
    $velocidades = array_column($historicData, 'velocidade');
    $tempos = array_column($historicData, 'tempo');

    // Calcular médias
    $overallAvgSpeed = array_sum($velocidades) / count($velocidades);
    $overallAvgTime = array_sum($tempos) / count($tempos);

    // Pegar valores atuais
    $currentSpeed = end($velocidades);
    $currentTime = end($tempos);

    // Determinar status atual
    [$currentStatus, $currentStatusText, $statusColor] = getStatus($currentSpeed, $overallAvgSpeed);

    echo "Rota: {$route['name']} - Velocidade Atual: $currentSpeed, Média: $overallAvgSpeed, Tempo Atual: $currentTime, Média de Tempo: $overallAvgTime, Status: $currentStatusText<br>";

    // Se a velocidade for crítica, armazenar a rota no array para envio de e-mail
    if ($currentStatus === 'danger') {
        $usersStmt = $pdo->prepare("
            SELECT id, email FROM users 
            WHERE receber_email = '1' 
            AND (id_parceiro = ? OR id_parceiro = 99)
        ");
        $usersStmt->execute([$route['id_parceiro']]);
        $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as $user) {
            $alertasPorUsuario[$user['email']][] = [
                'nome_rota' => $route['name'],
                'velocidade_atual' => number_format($currentSpeed, 1),
                'media_geral' => number_format($overallAvgSpeed, 1),
                'tempo_atual' => number_format($currentTime, 1),
                'media_tempo' => number_format($overallAvgTime, 1),
                'status' => $currentStatusText,
                'cor_status' => $statusColor
            ];
        }
    }
}

// Enviar e-mails agrupados por usuário
foreach ($alertasPorUsuario as $email => $rotas) {
    $corpoEmail = "<h2 style='color: red;'>⚠️ Alerta Crítico de Rotas</h2>";
    $corpoEmail .= "<p>As seguintes rotas apresentam status crítico:</p>";

    foreach ($rotas as $rota) {
        $corpoEmail .= "<div style='border: 2px solid {$rota['cor_status']}; padding: 15px; margin-bottom: 15px; border-radius: 5px;'>";
        $corpoEmail .= "<h3 style='color: {$rota['cor_status']}'>🚨 Rota: {$rota['nome_rota']}</h3>";
        $corpoEmail .= "<p><strong>Status:</strong> <span style='color: {$rota['cor_status']};'>{$rota['status']}</span></p>";
        $corpoEmail .= "<p><strong>Velocidade Atual:</strong> {$rota['velocidade_atual']} km/h</p>";
        $corpoEmail .= "<p><strong>Média de Velocidade:</strong> {$rota['media_geral']} km/h</p>";
        $corpoEmail .= "<p><strong>Tempo Atual:</strong> {$rota['tempo_atual']} min</p>";
        $corpoEmail .= "<p><strong>Média de Tempo:</strong> {$rota['media_tempo']} min</p>";
        $corpoEmail .= "</div>";
    }

    if (function_exists('sendEmail')) {
        sendEmail($email, $corpoEmail, "🚨 Alerta Crítico - Rotas Monitoradas");
    } else {
        error_log("Erro: Função sendEmail() não está definida.");
    }
}

echo "Processo concluído!";
?>
