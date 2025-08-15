<?php
// Configurações iniciais
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(1800); // 30 minutos

// Constantes de configuração
define('MIN_HISTORIC_RECORDS', 5);
define('MAX_ALERTS_PER_EMAIL', 15);
define('LOCK_FILE', __DIR__ . '/lock/route_monitor.lock');
define('LOG_FILE1', __DIR__ . '/logs/route_monitor.log');

// Inclusões necessárias
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

// Funções auxiliares **********************************************************

/**
 * Cria um arquivo de lock para evitar execuções simultâneas
 */
function createExecutionLock() {
    if (!file_exists(dirname(LOCK_FILE))) {
        mkdir(dirname(LOCK_FILE), 0755, true);
    }
    
    if (file_exists(LOCK_FILE)) {
        $lockTime = filemtime(LOCK_FILE);
        // Se o lock tiver mais de 1 hora, remove
        if (time() - $lockTime > 3600) {
            unlink(LOCK_FILE);
        } else {
            logMessage("Processo já em execução. Saindo.");
            exit;
        }
    }
    
    touch(LOCK_FILE);
}

/**
 * Remove o arquivo de lock
 */
function releaseExecutionLock() {
    if (file_exists(LOCK_FILE)) {
        unlink(LOCK_FILE);
    }
}

/**
 * Registra mensagens no log
 */
function logMessage($message) {
    if (!file_exists(dirname(LOG_FILE1))) {
        mkdir(dirname(LOG_FILE1), 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $memory = memory_get_usage() / 1024 / 1024;
    $formattedMessage = sprintf("[%s] [%.2fMB] %s\n", $timestamp, $memory, $message);
    
    file_put_contents(LOG_FILE1, $formattedMessage, FILE_APPEND);
    echo $formattedMessage;
}

/**
 * Formata tempo em segundos para "X min Y seg"
 */
function formatTime($seconds) {
    if ($seconds <= 0) return "0 min 0 seg";
    $minutes = (int) floor($seconds / 60);
    $seconds = (int) ($seconds % 60);
    return "{$minutes} min {$seconds} seg";
}

/**
 * Determina o status com base na comparação com a média
 */
function getStatus($current, $average) {
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
function safeOutput($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Busca dados de rotas otimizados
 */
function getRouteData(PDO $pdo) {
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
}

/**
 * Cria cache de usuários para envio de alertas
 */
function getUserCache(PDO $pdo) {
    $cache = [];
    $sql = "SELECT id, email, id_parceiro FROM users WHERE receber_email = '1'";
    $stmt = $pdo->query($sql);
    
    while ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cache[$user['id_parceiro']][] = $user;
        // Usuário global (id_parceiro = 99)
        $cache[99][] = $user;
    }
    
    return $cache;
}

/**
 * Gera o corpo do e-mail de alerta
 */
function buildEmailBody($rotas) {
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

    foreach ($rotas as $rota) {
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
    }

    // Limitar número de alertas por e-mail
    if (count($rotas) > MAX_ALERTS_PER_EMAIL) {
        $html .= '<p><strong>Nota:</strong> Mostrando ' . MAX_ALERTS_PER_EMAIL . ' de ' . count($rotas) . ' alertas críticos</p>';
    }

    $html .= <<<HTML
            <div class="footer">
                <p>Este é um e-mail automático. Por favor, não responda.</p>
                <p>© " . date('Y') . " Sistema de Monitoramento de Rotas</p>
            </div>
        </div>
    </body>
    </html>
    HTML;

    return $html;
}

/**
 * Envia e-mails de alerta
 */
function sendAlertEmails($alertasPorUsuario) {
    $totalEmails = 0;
    $totalAlerts = 0;
    
    foreach ($alertasPorUsuario as $email => $rotas) {
        // Limitar número de alertas por e-mail
        if (count($rotas) > MAX_ALERTS_PER_EMAIL) {
            $rotas = array_slice($rotas, 0, MAX_ALERTS_PER_EMAIL);
        }
        
        $corpoEmail = buildEmailBody($rotas);
        $subject = count($rotas) > 1 ? 
            "🚨 Alertas Críticos - " . count($rotas) . " rotas" : 
            "🚨 Alerta Crítico - Rota " . safeOutput($rotas[0]['nome_rota']);
        
        if (function_exists('sendEmail')) {
            sendEmail($email, $corpoEmail, $subject);
            $totalEmails++;
            $totalAlerts += count($rotas);
            logMessage("E-mail enviado para $email com " . count($rotas) . " alertas");
        } else {
            logMessage("ERRO: Função sendEmail() não definida");
        }
    }
    
    return [$totalEmails, $totalAlerts];
}

/**
 * Tratamento de erros
 */
function handleError(Exception $e) {
    logMessage("ERRO: " . $e->getMessage());
    logMessage("Trace: " . $e->getTraceAsString());
    releaseExecutionLock();
    exit(1);
}

// Execução principal **********************************************************
createExecutionLock();
$startTime = microtime(true);
$startMemory = memory_get_usage();

logMessage("Iniciando monitoramento de rotas");

try {
    // Conexão com o banco de dados
    $pdo = Database::getConnection();
    
    // Coletar dados otimizados
    $routes = getRouteData($pdo);
    $totalRotas = count($routes);
    logMessage("$totalRotas rotas para processar");
    
    // Criar cache de usuários
    $userCache = getUserCache($pdo);
    
    // Processar cada rota
    $alertasPorUsuario = [];
    $rotasCriticas = 0;
    
    foreach ($routes as $route) {
        // Validar dados numéricos
        $currentSpeed = is_numeric($route['velocidade_atual']) ? (float)$route['velocidade_atual'] : 0;
        $avgSpeed = is_numeric($route['media_geral_velocidade']) ? (float)$route['media_geral_velocidade'] : 0;
        
        // Determinar status
        [$status, $statusText, $color] = getStatus($currentSpeed, $avgSpeed);
        
        // Se for crítico, preparar alerta
        if ($status === 'danger') {
            $rotasCriticas++;
            $id_parceiro = $route['id_parceiro'];
            
            // Buscar usuários associados ao parceiro
            $users = array_merge(
                $userCache[$id_parceiro] ?? [],
                $userCache[99] ?? [] // Usuários globais
            );
            
            // Preparar dados da rota para alerta
            $routeData = [
                'nome_rota' => $route['name'],
                'velocidade_atual' => number_format($currentSpeed, 1),
                'media_geral' => number_format($avgSpeed, 1),
                'tempo_atual' => formatTime($route['tempo_atual']),
                'media_tempo' => formatTime($route['media_geral_tempo']),
                'status' => $statusText,
                'cor_status' => $color
            ];
            
            // Adicionar alerta para cada usuário
            foreach ($users as $user) {
                $alertasPorUsuario[$user['email']][] = $routeData;
            }
            
            logMessage("Alerta crítico: {$route['name']} - Velocidade: $currentSpeed km/h (Média: $avgSpeed km/h)");
        }
    }
    
    logMessage("$rotasCriticas rotas com status crítico detectadas");
    
    // Enviar alertas por e-mail
    if (!empty($alertasPorUsuario)) {
        [$totalEmails, $totalAlerts] = sendAlertEmails($alertasPorUsuario);
        logMessage("$totalEmails e-mails enviados com $totalAlerts alertas no total");
    } else {
        logMessage("Nenhum alerta crítico para enviar");
    }
    
} catch (Exception $e) {
    handleError($e);
} finally {
    // Monitoramento de desempenho
    $executionTime = microtime(true) - $startTime;
    $memoryUsed = (memory_get_peak_usage() - $startMemory) / 1024 / 1024;
    
    logMessage(sprintf(
        "Processo concluído. Tempo: %.2fs | Memória: %.2fMB | Rotas: %d | Alertas: %d",
        $executionTime,
        $memoryUsed,
        $totalRotas,
        $rotasCriticas
    ));
    
    releaseExecutionLock();
}