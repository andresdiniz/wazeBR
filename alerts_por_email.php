<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(1200);

require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

$pdo = Database::getConnection();

// Função para analisar o status
function getStatus($value, $overallAvg) {
    if ($overallAvg == 0) return ['danger', 'Crítico']; // Evita divisão por zero
    if ($value >= $overallAvg * 1.20) return ['success', 'Excelente'];
    if ($value >= $overallAvg * 1.10) return ['info', 'Bom'];
    if ($value >= $overallAvg * 0.95) return ['primary', 'Normal'];
    if ($value >= $overallAvg * 0.85) return ['warning', 'Atenção'];
    return ['danger', 'Crítico'];
}

// Buscar todas as rotas ativas
$sql = "SELECT * FROM routes";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Iniciando análise de rotas...<br>";

// Array para armazenar as rotas críticas por usuário
$alertasPorUsuario = [];

foreach ($routes as $route) {
    $historicDataStmt = $pdo->prepare("SELECT velocidade FROM historic_routes WHERE route_id = ? ORDER BY data ASC");
    $historicDataStmt->execute([$route['id']]);
    $historicData = $historicDataStmt->fetchAll(PDO::FETCH_COLUMN); // Busca apenas velocidades

    if (empty($historicData)) continue;

    $overallAvg = array_sum($historicData) / count($historicData);
    $currentSpeed = end($historicData);

    // Determinar status atual
    [$currentStatus, $currentStatusText] = getStatus($currentSpeed, $overallAvg);

    echo "Rota: {$route['name']} - Velocidade Atual: $currentSpeed, Média: $overallAvg, Status: $currentStatusText<br>";

    // Se a velocidade atual for crítica, armazenar a rota no array para envio de e-mail
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
                'media_geral' => number_format($overallAvg, 1),
                'status' => $currentStatusText
            ];
        }
    }
}

// Enviar e-mails agrupados por usuário
foreach ($alertasPorUsuario as $email => $rotas) {
    $corpoEmail = "<h2>⚠️ Alerta Crítico de Rotas</h2>";
    $corpoEmail .= "<p>As seguintes rotas apresentam status crítico:</p>";

    foreach ($rotas as $rota) {
        $corpoEmail .= "<div style='border: 1px solid red; padding: 10px; margin-bottom: 10px;'>";
        $corpoEmail .= "<h3>🚨 Rota: {$rota['nome_rota']}</h3>";
        $corpoEmail .= "<p><strong>Status:</strong> {$rota['status']}</p>";
        $corpoEmail .= "<p><strong>Velocidade Atual:</strong> {$rota['velocidade_atual']} km/h</p>";
        $corpoEmail .= "<p><strong>Média Geral:</strong> {$rota['media_geral']} km/h</p>";
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
