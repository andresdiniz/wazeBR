<?php
// bootstrap.php - Inicialização dos Serviços Essenciais

use Dotenv\Dotenv;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\DebugExtension;
use Twig\Extension\ProfilerExtension;
use Twig\Profiler\Profile;

ini_set('session.cookie_httonly', 1);      // Impede acesso via JavaScript (XSS)
ini_set('session.cookie_secure', 1);       // Envia cookies APENAS via HTTPS
ini_set('session.use_strict_mode', 1);     // Impede que a sessão aceite IDs não inicializados
session_set_cookie_params([
    'lifetime' => 86400, // 24 horas
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps, // Variável a ser definida
    'httponly' => true,
    'samesite' => 'Lax'
]);
// --- Configuração e Autoload ---
session_start();

// Inclusão das novas classes (ou via autoloader)
require_once __DIR__ . '/classes/Logger.php';
require_once __DIR__ . '/classes/ErrorHandler.php';

// Inclusões de Funções e Configurações
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

$results = [];

try {
    // 1. Variáveis de Ambiente e Debug
    $envPath = __DIR__ . '/.env';
    if (!file_exists($envPath)) {
        throw new Exception("Arquivo .env não encontrado: {$envPath}");
    }
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    $isDebug = ($_ENV['DEBUG'] ?? 'false') === 'true';
    $results['isDebug'] = $isDebug;
    
    // 2. Logger e Gerenciamento de Erros
    $logDir = __DIR__ . '/../logs';
    $logger = Logger::getInstance($logDir, $isDebug);
    $results['logger'] = $logger;
    
    $errorHandler = new ErrorHandler($logger, $isDebug);
    $errorHandler->register();
    
    $logger->info('Sistema inicializando (Bootstrap)', [
        'session_id' => session_id(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // 3. Configurações de erro PHP
    if ($isDebug) {
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);
        $logger->debug('Modo DEBUG ativado');
    } else {
        ini_set('display_errors', 0);
        ini_set('display_startup_errors', 0);
        // Otimização: Ignora NOTICES, STRICT e DEPRECATED em produção
        error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
    }
    
    ini_set('log_errors', 1);
    ini_set('error_log', $logDir . '/php_errors.log'); // Erros internos do PHP vão para cá
    
    // 4. Fuso horário
    $timezone = $_ENV['TIMEZONE'] ?? 'America/Sao_Paulo';
    date_default_timezone_set($timezone);

    // 5. Configuração do TWIG (Motor de templates)
    $loader = new FilesystemLoader(__DIR__ . '/frontend');
    $twigConfig = [
        'debug' => $isDebug,
        // Otimização: Cache de templates em produção
        'cache' => $isDebug ? false : __DIR__ . '/../cache/twig', 
        'auto_reload' => $isDebug,
        'strict_variables' => $isDebug
    ];
    
    $twig = new Environment($loader, $twigConfig);
    $results['twig'] = $twig;
    
    // Extensões de debug/profiler
    if ($isDebug) {
        $twig->addExtension(new DebugExtension());
        $profile = new Profile();
        $twig->addExtension(new ProfilerExtension($profile));
        $results['profile'] = $profile;
    }
    
    // Cria diretório de cache
    if (!$isDebug) {
        $cacheDir = __DIR__ . '/../cache/twig';
        if (!is_dir($cacheDir)) {
            // Tenta criar com 0775
            if (!mkdir($cacheDir, 0775, true)) {
                $logger->warning('Não foi possível criar diretório de cache do Twig', ['dir' => $cacheDir]);
            }
        }
    }
    
    $logger->debug('Twig inicializado com sucesso');
    
} catch (Exception $e) {
    // Erro crítico na inicialização
    error_log("ERRO CRÍTICO NA INICIALIZAÇÃO: " . $e->getMessage());
    http_response_code(500);
    
    // Função de contingência (deve ser definida no escopo global ou em scripts.php)
    function renderErrorPage($code, $title, $message, $description = '', $errorId = '', $trace = '', $isDebug = false) {
        $errorCode = $code;
        $errorTitle = $title;
        $errorMessage = $message;
        $errorDescription = $description;
        $error_id = $errorId;
        $errorTrace = $trace;
        $pagina_retorno = '/';
        include __DIR__ . '/frontend/error_standalone.php';
        exit;
    }
    
    renderErrorPage(500, 'Erro de Inicialização', 
        'Erro crítico ao inicializar o sistema.', 
        $e->getMessage(), 
        uniqid('err_'));
}

return $results;