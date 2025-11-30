<?php
/**
 * Index.php - Ponto de Entrada da Aplicação Otimizada e Segura
 * Inclui: Logging robusto, Twig, Manipulação de Exceções, Segurança Avançada, 
 * Rastreabilidade (Activity Logging) e Performance Timing.
 */

// --- Configuração e Autoload ---
require_once __DIR__ . '/vendor/autoload.php';
require_once 'classes/Logger.php';
require_once 'classes/ErrorHandler.php';

// --- Inicialização do Sistema (Bootstrap) ---
// O bootstrap.php deve carregar classes, Dotenv, Logger, ErrorHandler e funções (scripts.php)
$bootstrap = require_once __DIR__ . '/bootstrap.php';

// Extrai serviços e configurações
$isDebug = $bootstrap['isDebug'];
$logger = $bootstrap['logger'];
$twig = $bootstrap['twig'];
$profile = $bootstrap['profile'] ?? null;

// --- SEGURANÇA AVANÇADA: Bloqueio de Header Injection (EARLY EXIT) ---
// Verifica se há caracteres de nova linha/retorno de carro seguidos de headers maliciosos
if (preg_match("/(%0A|%0D|\\n|\\r)(content-type:|content-length:|xhr-session-id:)/i", json_encode($_SERVER, JSON_UNESCAPED_SLASHES))) {
    $logger->alert('Tentativa de HTTP Header Injection detectada!', [
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A'
    ]);
    header('Location: /403.html', true, 403);
    exit();
}

// --- CSRF Token (Mantido no index para início da sessão) ---
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =============================================================================
// LÓGICA DA APLICAÇÃO PRINCIPAL
// =============================================================================

// Flag para controlar a renderização de layout (evita renderizar layout no erro)
$renderLayout = true;

try {
    // --- 1. Autenticação e Conexão ---
    if (empty($_SESSION['usuario_id'])) {
        $logger->info('Usuário não autenticado, redirecionando para login', [
            'url_requested' => $_SERVER['REQUEST_URI'] ?? '/'
        ]);
        header("Location: /login_page.php");
        exit();
    }
    
    // Conexão com banco de dados
    timeEvent($logger, 'DB_Connection'); // ⏱️ INÍCIO DO TIMING: Conexão com DB
    $pdo = Database::getConnection();
    timeEvent($logger, 'DB_Connection', true); // ⏱️ FIM DO TIMING
    $logger->debug('Conexão com banco de dados estabelecida');
    
    // --- RASTREABILIDADE 1: Registro de Login/Sessão (Executado apenas 1x por sessão) ---
    if (empty($_SESSION['activity_logged'])) {
        logUserActivity($pdo, $logger, 'LOGIN', 'Usuário autenticado no sistema', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'N/A'
        ]);
        $_SESSION['activity_logged'] = true;
    }

    // --- 2. Configurações e Manutenção ---
    timeEvent($logger, 'Load_Settings'); // ⏱️ INÍCIO DO TIMING: Configurações
    $settings = getSiteSettings($pdo); 
    timeEvent($logger, 'Load_Settings', true); // ⏱️ FIM DO TIMING
    
    if (($settings['manutencao'] ?? false) && $_SERVER['REQUEST_URI'] !== '/manutencao.html') {
        $logger->info('Site em manutenção, redirecionando');
        header('Location: /manutencao.html');
        exit();
    }
    
    // --- 3. Roteamento e Preparação de Dados Globais ---
    $uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    // SEGURANÇA: Path Traversal Prevention (permite apenas alfanumérico e hífens/underscores)
    $uri = preg_replace('/[^a-zA-Z0-9_-]/', '', $uri);
    $page = $uri ?: 'home';
    
    $logger->info('Página solicitada', ['page' => $page, 'user_id' => $_SESSION['usuario_id']]);

    // RASTREABILIDADE 2: Registro de Visualização de Página
    logUserActivity($pdo, $logger, 'VIEW_PAGE', "Visualizou página '{$page}'", [
        'url' => $_SERVER['REQUEST_URI']
    ]);
    
    // Carregamento de dados da página
    timeEvent($logger, 'Load_PageData'); // ⏱️ INÍCIO DO TIMING: Dados da página
    $pages = getSitepages($pdo, $page);
    timeEvent($logger, 'Load_PageData', true); // ⏱️ FIM DO TIMING
    
    $dadosGlobais = [
        'user' => getSiteUsers($pdo, $_SESSION['usuario_id']),
        'settings' => $settings,
        'session' => $_SESSION,
        'pagedata' => $pages['pageData'] ?? null,
        'atualizacao' => getLatestExecutionLogByStatus($pdo, 'success'),
        'is_debug' => $isDebug
    ];
    
    // --- 4. Renderização do Layout (Início) ---
    echo $twig->render('header.twig', $dadosGlobais);
    echo $twig->render('sidebar.twig', $dadosGlobais);
    
    // --- 5. Execução do Controlador e Template ---
    $controllerPath = __DIR__ . "/backend/{$page}.php";
    $templatePath = "{$page}.twig";
    $data = [];
    
    if (file_exists($controllerPath)) {
        $logger->debug('Carregando controlador', ['controller' => $controllerPath]);
        
        timeEvent($logger, 'Controller_Execution_' . $page); // ⏱️ INÍCIO DO TIMING: Controlador
        require_once $controllerPath;
        $controllerDuration = timeEvent($logger, 'Controller_Execution_' . $page, true); // ⏱️ FIM DO TIMING
        
        // RASTREABILIDADE 3: Registro de Execução do Controlador
        logUserActivity($pdo, $logger, 'EXECUTE_CONTROLLER', "Controlador {$page} executado", [
            'duration_ms' => $controllerDuration
        ]);
    }
    
    $combinedData = array_merge($dadosGlobais, $data);
    
    // Renderização do template principal
    timeEvent($logger, 'Twig_Render_' . $page); // ⏱️ INÍCIO DO TIMING: Renderização
    try {
        echo $twig->render($templatePath, $combinedData);
        timeEvent($logger, 'Twig_Render_' . $page, true); // ⏱️ FIM DO TIMING
        $logger->debug('Página renderizada com sucesso', ['template' => $templatePath]);
    } catch (\Twig\Error\LoaderError $e) {
        timeEvent($logger, 'Twig_Render_' . $page, true); // Parada de segurança
        $logger->warning('Template não encontrado (404)', ['template' => $templatePath]);
        throw new Exception('Página não encontrada', 404);
    }
    
    // --- 6. Renderização do Layout (Fim) ---
    echo $twig->render('footer.twig', $dadosGlobais);
    
} catch (Exception $e) {
    // --- Tratamento de Erros da Aplicação ---
    $renderLayout = false;
    
    $code = $e->getCode() ?: 500;
    if (!is_int($code) || $code < 400 || $code > 599) {
        $code = 500;
    }
    
    http_response_code($code);
    
    // Log do erro
    $logger->error('Erro na aplicação', [
        'page' => $page ?? 'unknown',
        'code' => $code,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // RASTREABILIDADE 4: Registro de Erro de Sistema
    if (isset($pdo) && function_exists('logUserActivity')) {
        logUserActivity($pdo, $logger, 'SYSTEM_ERROR', "Erro de código {$code} na página '{$page}'", [
            'error_message' => $e->getMessage(),
            'code' => $code,
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }

    // Otimização: Renderiza página de erro sem carregar o layout principal
    $errorTitles = [
        400 => 'Requisição Inválida', 401 => 'Não Autorizado', 403 => 'Acesso Proibido',
        404 => 'Página Não Encontrada', 500 => 'Erro Interno do Servidor', 503 => 'Serviço Indisponível'
    ];
    
    $errorTitle = $errorTitles[$code] ?? 'Erro';
    $errorMessage = ($code >= 400 && $code < 500) 
        ? ($e->getMessage() ?: 'Verifique as informações e tente novamente.')
        : ($isDebug ? $e->getMessage() : 'Ocorreu um erro inesperado. Tente novamente mais tarde.');
    $errorDescription = $isDebug ? $e->getTraceAsString() : 'O erro foi registrado e será analisado.';
    $errorId = uniqid('err_');

    // Usa a função auxiliar para renderizar o erro standalone
    renderErrorPage(
        $code, $errorTitle, $errorMessage, $errorDescription, $errorId, 
        $isDebug ? $e->getTraceAsString() : '', $isDebug
    );
}

// --- Finalização e Debug ---
if (isset($profile) && $isDebug && $renderLayout) {
    if (class_exists(\Twig\Profiler\Dumper\Text::class)) {
        $dumper = new \Twig\Profiler\Dumper\Text();
        echo '<hr><h2>Twig Profiler</h2><pre>';
        echo $dumper->dump($profile);
        echo '</pre>';
    }
}

if ($renderLayout) {
    $logger->info('Requisição finalizada', [
        'page' => $page ?? 'unknown',
        // Otimização: Cálculo preciso do tempo de execução
        'execution_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms'
    ]);
}