<?php
/**
 * bootstrap.php - Inicialização dos Serviços Essenciais
 * Carrega Dotenv, inicializa Logger, Twig e registra o ErrorHandler.
 */

use Dotenv\Dotenv;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\DebugExtension;
use Twig\Extension\ProfilerExtension;
use Twig\Profiler\Profile;

// --- Configuração e Autoload ---
session_start();

// CSRF Token (Mantido no index ou movido para middleware/session.php)
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$envPath = __DIR__ . '/.env';

// Assume que configbd.php e scripts.php estão carregados no index ou no composer.json
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

// O Logger e o ErrorHandler original (classes) são assumidos como incluídos aqui 
// ou via autoload.

$results = [];

try {
    // 1. Variáveis de Ambiente e Debug
    if (!file_exists($envPath)) {
        throw new Exception("Arquivo .env não encontrado: {$envPath}");
    }
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    $isDebug = isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true';
    $results['isDebug'] = $isDebug;
    
    // 2. Inicializa Logger
    $logDir = __DIR__ . '/../logs';
    $logger = Logger::getInstance($logDir, $isDebug);
    $results['logger'] = $logger;
    $logger->info('Sistema inicializando (Bootstrap)', [
        'session_id' => session_id(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // 3. Registra manipulador de erros
    $errorHandler = new ErrorHandler($logger, $isDebug);
    $errorHandler->register();
    $results['errorHandler'] = $errorHandler;
    
    // 4. Configurações de erro PHP
    if ($isDebug) {
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);
        $logger->debug('Modo DEBUG ativado');
    } else {
        ini_set('display_errors', 0);
        ini_set('display_startup_errors', 0);
        error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
    }
    
    ini_set('log_errors', 1);
    ini_set('error_log', $logDir . '/php_errors.log');
    
    // 5. Fuso horário
    $timezone = $_ENV['TIMEZONE'] ?? 'America/Sao_Paulo';
    date_default_timezone_set($timezone);

    // 6. Configuração do TWIG
    $loader = new FilesystemLoader(__DIR__ . '/frontend');
    $twigConfig = [
        'debug' => $isDebug,
        'cache' => $isDebug ? false : __DIR__ . '/../cache/twig',
        'auto_reload' => $isDebug,
        'strict_variables' => $isDebug
    ];
    
    $twig = new Environment($loader, $twigConfig);
    $results['twig'] = $twig;
    
    // Extensões de debug
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
            if (!mkdir($cacheDir, 0775, true)) {
                $logger->warning('Não foi possível criar diretório de cache do Twig', ['dir' => $cacheDir]);
            }
        }
    }
    
    $logger->debug('Twig inicializado com sucesso');
    
} catch (Exception $e) {
    // Erro crítico na inicialização (antes do Logger/Twig estarem totalmente prontos)
    error_log("ERRO CRÍTICO NA INICIALIZAÇÃO: " . $e->getMessage());
    http_response_code(500);
    // Usa a função de renderização de erro de contingência
    renderErrorPage(500, 'Erro de Inicialização', 
        'Erro crítico ao inicializar o sistema.', 
        $e->getMessage(), 
        uniqid('err_'));
}

return $results;