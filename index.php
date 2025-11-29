<?php
/**
 * Index.php - Ponto de Entrada da Aplicação Otimizada e Segura
 * Inclui: Logging robusto, Twig, Manipulação de Exceções, Segurança Avançada, 
 * Rastreabilidade (Activity Logging) e Performance Timing.
 */

// --- Configuração Inicial ---
session_start();

// CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Define o caminho para o arquivo .env
$envPath = __DIR__ . '/.env';

// Autoloader do Composer
require_once __DIR__ . '/vendor/autoload.php';

// Configurações e funções
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

use Dotenv\Dotenv;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\DebugExtension;
use Twig\Extension\ProfilerExtension;
use Twig\Profiler\Profile;

// ... (Logger, ErrorHandler, renderErrorPage Classes and Functions Remain Unchanged) ...
// Atenção: As classes Logger e ErrorHandler devem estar definidas neste arquivo ou carregadas via autoload.
// ... (continuação do código das classes Logger e ErrorHandler) ...

// =============================================================================
// FUNÇÃO PARA RENDERIZAR ERRO STANDALONE
// =============================================================================

function renderErrorPage($code, $title, $message, $description = '', $errorId = '', $trace = '', $isDebug = false) {
    // Define as variáveis para o template
    $errorCode = $code;
    $errorTitle = $title;
    $errorMessage = $message;
    $errorDescription = $description;
    $error_id = $errorId;
    $errorTrace = $trace;
    $pagina_retorno = '/';
    
    // Inclui o template de erro standalone
    // Assumindo que este arquivo existe: /frontend/error_standalone.php
    include __DIR__ . '/frontend/error_standalone.php';
    exit;
}

// =============================================================================
// INICIALIZAÇÃO DO SISTEMA
// =============================================================================

try {
    // Verifica .env
    if (!file_exists($envPath)) {
        throw new Exception("Arquivo .env não encontrado no caminho: {$envPath}");
    }
    
    // Carrega variáveis de ambiente
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    
    // Determina modo debug
    $isDebug = isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true';
    
    // Inicializa Logger
    $logDir = __DIR__ . '/../logs';
    $logger = Logger::getInstance($logDir, $isDebug);
    
    // OTIMIZAÇÃO: Define o logger como global para as funções de utilidade (scripts.php)
    $GLOBALS['logger'] = $logger; 
    
    $logger->info('Sistema inicializado', [
        'session_id' => session_id(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // Registra manipulador de erros
    $errorHandler = new ErrorHandler($logger, $isDebug);
    $errorHandler->register();
    
    // Configurações de erro PHP
    if ($isDebug) {
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);
        $logger->debug('Modo DEBUG ativado');
    } else {
        ini_set('display_errors', 0);
        ini_set('display_startup_errors', 0);
        // OTIMIZAÇÃO: Suprime notices, strict e deprecated em produção
        error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED); 
    }
    
    ini_set('log_errors', 1);
    ini_set('error_log', $logDir . '/php_errors.log');
    
    // Fuso horário
    $timezone = $_ENV['TIMEZONE'] ?? 'America/Sao_Paulo';
    date_default_timezone_set($timezone);
    
} catch (Exception $e) {
    // Erro crítico na inicialização
    error_log("ERRO CRÍTICO NA INICIALIZAÇÃO: " . $e->getMessage());
    http_response_code(500);
    renderErrorPage(500, 'Erro de Inicialização', 
        'Erro crítico ao inicializar o sistema.', 
        $e->getMessage(), 
        uniqid('err_'));
}

// ... (Twig Configuration Block Remains Unchanged) ...
// =============================================================================
// LÓGICA DA APLICAÇÃO
// =============================================================================

// Flag para controlar se devemos renderizar header/sidebar/footer
$renderLayout = true;

try {
    // --- SEGURANÇA AVANÇADA: Bloqueio de Header Injection (EARLY EXIT) ---
    // OTIMIZAÇÃO/SEGURANÇA: Reintrodução de verificação robusta
    if (preg_match("/(%0A|%0D|\\n|\\r)(content-type:|content-length:|xhr-session-id:)/i", json_encode($_SERVER, JSON_UNESCAPED_SLASHES))) {
        $logger->alert('Tentativa de HTTP Header Injection detectada!', [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A'
        ]);
        renderErrorPage(403, 'Acesso Proibido', 'Tentativa de requisição maliciosa bloqueada.');
    }

    // Verifica autenticação
    if (empty($_SESSION['usuario_id'])) {
        $logger->info('Usuário não autenticado, redirecionando para login', [
            'url_requested' => $_SERVER['REQUEST_URI'] ?? '/'
        ]);
        header("Location: /login_page.php");
        exit();
    }
    
    // Conexão com banco de dados
    timeEvent($logger, 'DB_Connection'); // ⏱️ INÍCIO DO TIMING: Conexão com DB
    try {
        $pdo = Database::getConnection();
        $logger->debug('Conexão com banco de dados estabelecida');
        timeEvent($logger, 'DB_Connection', true); // ⏱️ FIM DO TIMING
    } catch (PDOException $e) {
        timeEvent($logger, 'DB_Connection', true); // FIM DO TIMING com erro
        $logger->critical('Erro de conexão com banco de dados', [
            'error' => $e->getMessage(),
            'code' => $e->getCode()
        ]);
        throw new Exception('Erro ao conectar ao banco de dados', 500);
    }
    
    // Rastreabilidade de Login/Sessão (Executado apenas 1x por sessão)
    if (!isset($_SESSION['activity_logged_in_db']) && function_exists('logUserActivity')) {
        logUserActivity($pdo, $logger, 'LOGIN', 'Usuário autenticado no sistema', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'N/A'
        ]);
        $_SESSION['activity_logged_in_db'] = true;
    }
    
    // Carrega configurações (OTIMIZADA COM CACHE ESTÁTICO)
    timeEvent($logger, 'Load_Settings'); // ⏱️ INÍCIO DO TIMING: Configurações
    $settings = getSiteSettings($pdo);
    timeEvent($logger, 'Load_Settings', true); // ⏱️ FIM DO TIMING
    
    // Verifica manutenção
    if (($settings['manutencao'] ?? false) && $_SERVER['REQUEST_URI'] !== '/manutencao.html') {
        $logger->info('Site em manutenção, redirecionando');
        header('Location: /manutencao.html');
        exit();
    }
    
    // Determina página atual
    $uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    // OTIMIZAÇÃO/SEGURANÇA: Path Traversal Prevention
    $uri = preg_replace('/[^a-zA-Z0-9_-]/', '', $uri);
    $page = $uri ?: 'home';
    
    $logger->info('Página solicitada', ['page' => $page, 'user_id' => $_SESSION['usuario_id']]);
    
    // Rastreabilidade 2: Registro de Visualização de Página
    if (function_exists('logUserActivity')) {
        logUserActivity($pdo, $logger, 'VIEW_PAGE', "Visualizou página '{$page}'", [
            'url' => $_SERVER['REQUEST_URI']
        ]);
    }

    // Carrega dados da página
    timeEvent($logger, 'Load_PageData'); // ⏱️ INÍCIO DO TIMING: Dados da página
    $pages = getSitepages($pdo, $page);
    $pageData = $pages['pageData'] ?? null;
    $atualizacao = getLatestExecutionLogByStatus($pdo, 'success');
    timeEvent($logger, 'Load_PageData', true); // ⏱️ FIM DO TIMING
    
    // Prepara dados do usuário (OTIMIZADA COM CACHE ESTÁTICO)
    timeEvent($logger, 'Load_UserData'); // ⏱️ INÍCIO DO TIMING: Dados do usuário
    $userData = getSiteUsers($pdo, $_SESSION['usuario_id']);
    timeEvent($logger, 'Load_UserData', true); // ⏱️ FIM DO TIMING
    
    // Prepara dados para templates
    $dados = [
        'user' => $userData,
        'settings' => $settings,
        'session' => $_SESSION,
        'pagedata' => $pageData,
        'atualizacao' => $atualizacao,
        'is_debug' => $isDebug
    ];
    
    // Renderiza componentes fixos
    timeEvent($logger, 'Render_Layout_Start'); // ⏱️ INÍCIO DO TIMING: Render Header/Sidebar
    echo $twig->render('header.twig', $dados);
    echo $twig->render('sidebar.twig', $dados);
    timeEvent($logger, 'Render_Layout_Start', true); // ⏱️ FIM DO TIMING
    
    // Carrega controlador da página
    $controllerPath = __DIR__ . "/backend/{$page}.php";
    $templatePath = "{$page}.twig";
    $data = [];
    
    if (file_exists($controllerPath)) {
        $logger->debug('Carregando controlador', ['controller' => $controllerPath]);
        timeEvent($logger, 'Controller_Execution_' . $page); // ⏱️ INÍCIO DO TIMING: Controlador
        
        require_once $controllerPath;
        
        $controllerDuration = timeEvent($logger, 'Controller_Execution_' . $page, true); // ⏱️ FIM DO TIMING
        
        // Rastreabilidade 3: Registro de Execução do Controlador
        if (function_exists('logUserActivity')) {
             logUserActivity($pdo, $logger, 'EXECUTE_CONTROLLER', "Controlador {$page} executado", [
                'duration_ms' => $controllerDuration
            ]);
        }
    }
    
    // Combina dados
    $combinedData = array_merge($dados, $data);
    
    // Renderiza página
    timeEvent($logger, 'Twig_Render_Page_' . $page); // ⏱️ INÍCIO DO TIMING: Renderização
    try {
        echo $twig->render($templatePath, $combinedData);
        $renderDuration = timeEvent($logger, 'Twig_Render_Page_' . $page, true); // ⏱️ FIM DO TIMING
        $logger->debug('Página renderizada com sucesso', ['template' => $templatePath, 'duration_ms' => $renderDuration]);
    } catch (\Twig\Error\LoaderError $e) {
        timeEvent($logger, 'Twig_Render_Page_' . $page, true); // FIM DO TIMING com erro
        $logger->warning('Template não encontrado', ['template' => $templatePath]);
        throw new Exception('Página não encontrada', 404);
    }
    
    // Renderiza footer
    timeEvent($logger, 'Render_Layout_End'); // ⏱️ INÍCIO DO TIMING: Render Footer
    echo $twig->render('footer.twig', $dados);
    timeEvent($logger, 'Render_Layout_End', true); // ⏱️ FIM DO TIMING
    
} catch (Exception $e) {
    // NÃO renderiza header/sidebar/footer em caso de erro
    $renderLayout = false;
    
    // Tratamento de erros da aplicação
    $code = $e->getCode() ?: 500;
    
    if (!is_int($code) || $code < 400 || $code > 599) {
        $code = 500;
    }
    
    http_response_code($code);
    
    $logger->error('Erro na aplicação', [
        'page' => $page ?? 'unknown',
        'code' => $code,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    // RASTREABILIDADE: Registro de Erro de Sistema
    if (isset($pdo) && function_exists('logUserActivity')) {
        logUserActivity($pdo, $logger, 'SYSTEM_ERROR', "Erro de código {$code} na página '{$page}'", [
            'error_message' => $e->getMessage(),
            'code' => $code,
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }

    // Mapa de títulos de erro
    $errorTitles = [
        400 => 'Requisição Inválida',
        401 => 'Não Autorizado',
        403 => 'Acesso Proibido',
        404 => 'Página Não Encontrada',
        500 => 'Erro Interno do Servidor',
        503 => 'Serviço Indisponível'
    ];
    
    $errorTitle = $errorTitles[$code] ?? 'Erro';
    
    // Mensagem baseada no código e modo debug
    if ($code >= 400 && $code < 500) {
        $errorMessage = $e->getMessage() ?: 'Verifique as informações e tente novamente.';
        $errorDescription = 'Há um problema com a requisição.';
    } else {
        $errorMessage = $isDebug ? $e->getMessage() : 'Ocorreu um erro inesperado. Tente novamente mais tarde.';
        $errorDescription = $isDebug ? $e->getTraceAsString() : 'O erro foi registrado e será analisado.';
    }
    
    $errorId = uniqid('err_');
    
    // Renderiza página de erro STANDALONE (sem layout)
    renderErrorPage(
        $code, 
        $errorTitle, 
        $errorMessage, 
        $errorDescription, 
        $errorId, 
        $isDebug ? $e->getTraceAsString() : '', 
        $isDebug
    );
}

// Exibição do Profiler (apenas em debug)
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
        'execution_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms'
    ]);
}
?>