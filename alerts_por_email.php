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
$routes = $pdo->query("SELECT id, id_parceiro, name FROM routes WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

foreach ($routes as $route) {
    $historicDataStmt = $pdo->prepare("SELECT velocidade, data FROM historic_routes WHERE route_id = ? ORDER BY data ASC");
    $historicDataStmt->execute([$route['id']]);
    $historicData = $historicDataStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($historicData) === 0) continue;

    $velocidades = array_column($historicData, 'velocidade');
    $overallAvg = count($velocidades) > 0 ? array_sum($velocidades) / count($velocidades) : 0;
    $currentSpeed = end($velocidades);

    // Determinar status atual
    [$currentStatus, $currentStatusText] = getStatus($currentSpeed, $overallAvg);

    // Análise por períodos do dia
    $timeAnalysis = [
        ['start' => 0, 'end' => 3, 'speeds' => [], 'label' => 'Madrugada (00:00 - 03:00)'],
        ['start' => 3, 'end' => 6, 'speeds' => [], 'label' => 'Madrugada (03:00 - 06:00)'],
        ['start' => 6, 'end' => 9, 'speeds' => [], 'label' => 'Manhã (06:00 - 09:00)'],
        ['start' => 9, 'end' => 12, 'speeds' => [], 'label' => 'Manhã (09:00 - 12:00)'],
        ['start' => 12, 'end' => 15, 'speeds' => [], 'label' => 'Tarde (12:00 - 15:00)'],
        ['start' => 15, 'end' => 18, 'speeds' => [], 'label' => 'Tarde (15:00 - 18:00)'],
        ['start' => 18, 'end' => 21, 'speeds' => [], 'label' => 'Noite (18:00 - 21:00)'],
        ['start' => 21, 'end' => 24, 'speeds' => [], 'label' => 'Noite (21:00 - 00:00)']
    ];

    foreach ($historicData as $entry) {
        $hour = (int)(new DateTime($entry['data']))->format('G');
        foreach ($timeAnalysis as &$period) {
            if ($hour >= $period['start'] && $hour < $period['end']) {
                $period['speeds'][] = $entry['velocidade'];
                break;
            }
        }
    }

    // Verificar períodos críticos
    $alertas = [];
    foreach ($timeAnalysis as $period) {
        if (!empty($period['speeds'])) {
            $periodAvg = array_sum($period['speeds']) / count($period['speeds']);
            [$status, ] = getStatus($periodAvg, $overallAvg);
            if ($status === 'danger') $alertas[] = $period['label'];
        }
    }

    echo("Classificação PHP - Velocidade: $velocidade, Média: $overallAvg, Status: $status");
    // Se houver alertas críticos
    if ($currentStatus === 'danger' || !empty($alertas)) {
        $usersStmt = $pdo->prepare("
            SELECT email FROM users 
            WHERE receber_email = '1' 
            AND (id_parceiro = ? OR id_parceiro = 99)
        ");
        $usersStmt->execute([$route['id_parceiro']]);
        $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($users)) {
            foreach ($users as $user) {
                $corpoEmail = "<h2>Alerta na Rota {$route['name']}</h2>";
                $corpoEmail .= "<p>Status Atual: $currentStatusText</p>";
                $corpoEmail .= "<p>Velocidade Atual: " . number_format($currentSpeed, 1) . " km/h</p>";
                $corpoEmail .= "<p>Média Geral: " . number_format($overallAvg, 1) . " km/h</p>";

                if (!empty($alertas)) {
                    $corpoEmail .= "<p>Períodos com problemas:</p><ul>";
                    foreach ($alertas as $alerta) {
                        $corpoEmail .= "<li>$alerta</li>";
                    }
                    $corpoEmail .= "</ul>";
                }

                // Verifica se a função sendEmail() está definida antes de chamar
                if (function_exists('sendEmail')) {
                    sendEmail($user['email'], $corpoEmail, "Alerta de Tráfego - {$route['name']}");
                } else {
                    error_log("Erro: Função sendEmail() não está definida.");
                }
            }
        }
    } else {
        echo "Nenhum alerta para a rota {$route['name']}<br>";
    }
}
?>
