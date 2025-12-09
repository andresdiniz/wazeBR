<?php
declare(strict_types=1);

/**
 * Script: alerts_por_email.php
 * Responsabilidade: Monitorar rotas e enviar alertas por email
 *
 * Pré-requisitos (fornecidos pelo wazejob.php):
 *   - $logger: Logger
 *   - $pdo: PDO
 */

if (!isset($logger) || !($logger instanceof Logger)) {
    throw new RuntimeException('Logger não disponível em alerts_por_email.php');
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('PDO não disponível em alerts_por_email.php');
}

$startTime = microtime(true);
$currentDateTime = date('Y-m-d H:i:s');

$logger->info('alerts_por_email iniciado', ['datetime' => $currentDateTime]);

set_time_limit(1800); // 30 minutos

// Constantes
define('MIN_HISTORIC_RECORDS', 5);
define('MAX_ALERTS_PER_EMAIL', 15);

/**
 * Formata tempo em segundos para "X min Y seg"
 */
function formatTime(float $seconds): string
{
    if ($seconds <= 0) return "0 min 0 seg";
    $minutes = (int)floor($seconds / 60);
    $seconds = (int)($seconds % 60);
    return "{$minutes} min {$seconds} seg";
}

/**
 * Determina o status com base na comparação com a média
 */
function getStatus(float $current, float $average): array
{
    if ($average <= 0) return ['danger', 'Crítico', '#ff4d4d'];

    $ratio = $current / $average;

    if ($ratio >= 1.20) return ['success', 'Excelente', '#28a745'];
    if ($ratio >= 1.10) return ['info', 'Bom', '#17a2b8'];
    if ($ratio >= 0.95) return ['primary', 'Normal', '#007bff'];
    if ($ratio >= 0.85) return ['warning', 'Atenção', '#ffc107'];

    return ['danger', 'Crítico', '#ff4d4d'];
}

/**
 * Sanitiza saída HTML
 */
function safeOutput(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Busca dados de rotas
 */
function getRouteData(PDO $pdo, Logger $logger): array
{
    try {
        $sql = "
            SELECT 
                r.id, 
                r.name, 
                r.id_parceiro,
                COUNT(hr.id) AS total_registros,
                AVG(hr.velocidade) AS media_geral_velocidade,
                AVG(hr.tempo) AS media_geral_tempo,
                MAX(hr.data) AS ultima_data,
                (
                    SELECT velocidade 
                    FROM historic_routes 
                    WHERE route_id = r.id 
                    ORDER BY data DESC 
                    LIMIT 1
                ) AS velocidade_atual,
                (
                    SELECT tempo 
                    FROM historic_routes 
                    WHERE route_id = r.id 
                    ORDER BY data DESC 
                    LIMIT 1
                ) AS tempo_atual
            FROM routes r
            LEFT JOIN historic_routes hr ON hr.route_id = r.id
            GROUP BY r.id
            HAVING total_registros >= :min_records
            ORDER BY r.name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':min_records', MIN_HISTORIC_RECORDS, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $logger->error('Erro ao buscar rotas', ['mensagem' => $e->getMessage()]);
        return [];
    }
}

/**
 * Cria cache de usuários
 */
function getUserCache(PDO $pdo, Logger $logger): array
{
    try {
        $cache = [];
        $sql = "SELECT id, email, id_parceiro FROM users WHERE receber_email = '1'";
        $stmt = $pdo->query($sql);

        while ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cache[$user['id_parceiro']][] = $user;
            $cache[99][] = $user; // Usuário global
        }

        return $cache;
    } catch (PDOException $e) {
        $logger->error('Erro ao buscar usuários', ['mensagem' => $e->getMessage()]);
        return [];
    }
}

/**
 * Gera o corpo do email
 */
function buildEmailBody(array $rotas): string
{
    $html = <<<HTML
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            .container { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; }
            .header { background-color: #f8f9fa; padding: 20px; text-align: center; border-bottom: 1px solid #dee2e6; }
            .alert-box { border-left: 5px solid; padding: 15px; margin-bottom: 20px; border-radius: 4px; background-color: #f8d7da; }
            .alert-title { margin-top: 0; color: inherit; }
            .stats { margin: 10px 0; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; text-align: center; color: #6c757d; font-size: 0.9em; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2 style="color: #dc3545;">⚠️ Alertas de Rotas Críticas</h2>
                <p>As seguintes rotas apresentam condições críticas</p>
            </div>
    HTML;

    $count = 0;
    foreach ($rotas as $rota) {
        if ($count >= MAX_ALERTS_PER_EMAIL) break;

        $safeName = safeOutput($rota['nome_rota']);
        $safeSpeed = safeOutput($rota['velocidade_atual']);
        $safeAvgSpeed = safeOutput($rota['media_geral']);
        $safeTime = safeOutput($rota['tempo_atual']);
        $safeAvgTime = safeOutput($rota['media_tempo']);
        $safeStatus = safeOutput($rota['status']);

        $html .= <<<HTML
            <div class="alert-box" style="border-left-color: {$rota['cor_status']}">
                <h3 class="alert-title" style="color: {$rota['cor_status']}">🚨 Rota: {$safeName}</h3>
                <div class="stats">
                    <p><strong>Status:</strong> <span style="color: {$rota['cor_status']}">{$safeStatus}</span></p>
                    <p><strong>Velocidade Atual:</strong> {$safeSpeed} km/h</p>
                    <p><strong>Média de Velocidade:</strong> {$safeAvgSpeed} km/h</p>
                    <p><strong>Tempo Atual:</strong> {$safeTime}</p>
                    <p><strong>Média de Tempo:</strong> {$safeAvgTime}</p>
                </div>
            </div>
        HTML;

        $count++;
    }

    if (count($rotas) > MAX_ALERTS_PER_EMAIL) {
        $html .= '<p><strong>Nota:</strong> Mostrando ' . MAX_ALERTS_PER_EMAIL . ' de ' . count($rotas) . ' alertas críticos</p>';
    }

    $html .= <<<HTML
            <div class="footer">
                <p>Este é um e-mail automático. Por favor, não responda.</p>
                <p>© {$GLOBALS['currentDateTime']} Sistema de Monitoramento de Rotas</p>
            </div>
        </div>
    </body>
    </html>
    HTML;

    return $html;
}

/**
 * Envia alertas por email
 */
function sendAlertEmails(array $alertasPorUsuario, Logger $logger): array
{
    $totalEmails = 0;
    $totalAlerts = 0;

    foreach ($alertasPorUsuario as $email => $rotas) {
        // Limitar número de alertas por email
        if (count($rotas) > MAX_ALERTS_PER_EMAIL) {
            $rotas = array_slice($rotas, 0, MAX_ALERTS_PER_EMAIL);
        }

        $corpoEmail = buildEmailBody($rotas);
        $subject = count($rotas) > 1
            ? "🚨 Alertas Críticos - " . count($rotas) . " rotas"
            : "🚨 Alerta Crítico - Rota " . safeOutput($rotas[0]['nome_rota']);

        if (function_exists('sendEmail')) {
            try {
                sendEmail($email, $corpoEmail, $subject);
                $totalEmails++;
                $totalAlerts += count($rotas);

                $logger->info('Email de alerta enviado', [
                    'destinatario' => $email,
                    'qtd_rotas' => count($rotas)
                ]);
            } catch (Exception $e) {
                $logger->error('Erro ao enviar email', [
                    'destinatario' => $email,
                    'mensagem' => $e->getMessage()
                ]);
            }
        } else {
            $logger->error('Função sendEmail() não disponível');
        }
    }

    return [$totalEmails, $totalAlerts];
}

/**
 * Processa monitoramento de rotas
 */
function processRouteMonitoring(PDO $pdo, Logger $logger): void
{
    // Buscar rotas
    $routes = getRouteData($pdo, $logger);
    $totalRotas = count($routes);

    $logger->info('Rotas para processar', ['total' => $totalRotas]);

    if ($totalRotas === 0) {
        $logger->warning('Nenhuma rota encontrada para processar');
        return;
    }

    // Buscar usuários
    $userCache = getUserCache($pdo, $logger);

    // Processar cada rota
    $alertasPorUsuario = [];
    $rotasCriticas = 0;

    foreach ($routes as $route) {
        $currentSpeed = is_numeric($route['velocidade_atual']) ? (float)$route['velocidade_atual'] : 0;
        $avgSpeed = is_numeric($route['media_geral_velocidade']) ? (float)$route['media_geral_velocidade'] : 0;

        [$status, $statusText, $color] = getStatus($currentSpeed, $avgSpeed);

        if ($status === 'danger') {
            $rotasCriticas++;
            $id_parceiro = $route['id_parceiro'];

            // Buscar usuários associados
            $users = array_merge(
                $userCache[$id_parceiro] ?? [],
                $userCache[99] ?? []
            );

            // Preparar dados da rota
            $routeData = [
                'nome_rota' => $route['name'],
                'velocidade_atual' => number_format($currentSpeed, 1),
                'media_geral' => number_format($avgSpeed, 1),
                'tempo_atual' => formatTime($route['tempo_atual'] ?? 0),
                'media_tempo' => formatTime($route['media_geral_tempo'] ?? 0),
                'status' => $statusText,
                'cor_status' => $color
            ];

            // Adicionar alerta para cada usuário
            foreach ($users as $user) {
                if (!empty($user['email'])) {
                    $alertasPorUsuario[$user['email']][] = $routeData;
                }
            }

            $logger->info('Alerta crítico detectado', [
                'rota' => $route['name'],
                'velocidade_atual' => $currentSpeed,
                'media' => $avgSpeed
            ]);
        }
    }

    $logger->info('Rotas críticas detectadas', ['total' => $rotasCriticas]);

    // Enviar alertas
    if (!empty($alertasPorUsuario)) {
        [$totalEmails, $totalAlerts] = sendAlertEmails($alertasPorUsuario, $logger);

        $logger->info('Alertas enviados', [
            'emails_enviados' => $totalEmails,
            'alertas_totais' => $totalAlerts
        ]);
    } else {
        $logger->info('Nenhum alerta crítico para enviar');
    }
}

// Execução principal
processRouteMonitoring($pdo, $logger);

$endTime = microtime(true);
$totalTime = round($endTime - $startTime, 2);

$logger->info('alerts_por_email concluído', [
    'tempo_total_s' => $totalTime,
    'memoria_pico_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
]);

return true;