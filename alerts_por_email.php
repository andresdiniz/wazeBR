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

// Buscar todas as rotas ativas sem filtrar pelo id_parceiro
$sql = "SELECT * FROM routes";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Iniciando análise de rotas...<br>";

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

    // Se a velocidade atual for crítica, enviar e-mail
    if ($currentStatus === 'danger') {
        $usersStmt = $pdo->prepare("
            SELECT email FROM users 
            WHERE receber_email = '1' 
            AND (id_parceiro = ? OR id_parceiro = 99)
        ");
        $usersStmt->execute([$route['id_parceiro']]);
        $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as $user) {
            $corpoEmail = "<h2>⚠️ Alerta Crítico na Rota {$route['name']}</h2>";
            $corpoEmail .= "<p>Status Atual: <strong>$currentStatusText</strong></p>";
            $corpoEmail .= "<p>Velocidade Atual: <strong>" . number_format($currentSpeed, 1) . " km/h</strong></p>";
            $corpoEmail .= "<p>Média Geral: " . number_format($overallAvg, 1) . " km/h</p>";

            if (function_exists('sendEmail')) {
                sendEmail($user['email'], $corpoEmail, "🚨 Alerta Crítico - {$route['name']}");
            } else {
                error_log("Erro: Função sendEmail() não está definida.");
            }
        }
    } else {
        echo "Nenhum alerta enviado para a rota {$route['name']}<br>";
    }
}
?>
