<?php
/**
 * Index.php - Sistema Principal com Logs Avançados
 * Sistema de logging robusto com níveis, rotação de arquivos e notificações
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

// =============================================================================
// SISTEMA DE LOGGING AVANÇADO
// =============================================================================

class Logger {
    private static $instance = null;
    private $logDir;
    private $maxFileSize = 5242880; // 5MB
    private $maxFiles = 10;
    private $isDebug;
    
    // Níveis de log
    const EMERGENCY = 'EMERGENCY'; // Sistema inutilizável
    const ALERT     = 'ALERT';     // Ação imediata necessária
    const CRITICAL  = 'CRITICAL';  // Condições críticas
    const ERROR     = 'ERROR';     // Erros que não param o sistema
    const WARNING   = 'WARNING';   // Avisos
    const NOTICE    = 'NOTICE';    // Eventos normais mas importantes
    const INFO      = 'INFO';      // Informações gerais
    const DEBUG     = 'DEBUG';     // Informações de debug
    
    private function __construct($logDir, $isDebug = false) {
        $this->logDir = $logDir;
        $this->isDebug = $isDebug;
        $this->ensureLogDirectory();
    }
    
    public static function getInstance($logDir = null, $isDebug = false) {
        if (self::$instance === null) {
            if ($logDir === null) {
                $logDir = __DIR__ . '/../logs';
            }
            self::$instance = new self($logDir, $isDebug);
        }
        return self::$instance;
    }
    
    private function ensureLogDirectory() {
        if (!is_dir($this->logDir)) {
            if (!mkdir($this->logDir, 0775, true)) {
                error_log("CRÍTICO: Não foi possível criar diretório de logs: {$this->logDir}");
                return false;
            }
        }
        
        // Verifica permissões de escrita
        if (!is_writable($this->logDir)) {
            error_log("AVISO: Diretório de logs não tem permissão de escrita: {$this->logDir}");
            return false;
        }
        
        return true;
    }
    
    /**
     * Registra uma mensagem de log
     */
    public function log($level, $message, $context = []) {
        // Determina o arquivo de log baseado no nível
        $logFile = $this->getLogFilePath($level);
        
        // Formata a mensagem
        $formattedMessage = $this->formatMessage($level, $message, $context);
        
        // Rotaciona o arquivo se necessário
        $this->rotateLogIfNeeded($logFile);
        
        // Escreve no arquivo
        $result = file_put_contents(
            $logFile,
            $formattedMessage . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        
        if ($result === false) {
            error_log("Falha ao escrever no log: {$logFile}");
        }
        
        // Em debug, também exibe no error_log do PHP
        if ($this->isDebug) {
            error_log("[{$level}] {$message}");
        }
        
        // Para erros críticos, envia email (se configurado)
        if (in_array($level, [self::EMERGENCY, self::ALERT, self::CRITICAL])) {
            $this->notifyAdmin($level, $message, $context);
        }
        
        return $result !== false;
    }
    
    private function formatMessage($level, $message, $context) {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | Context: ' . json_encode($context) : '';
        
        // Adiciona informações de rastreamento
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = isset($trace[2]) ? $trace[2] : $trace[1];
        $file = isset($caller['file']) ? basename($caller['file']) : 'unknown';
        $line = isset($caller['line']) ? $caller['line'] : '0';
        
        return sprintf(
            "[%s] [%s] [%s:%s] %s%s",
            $timestamp,
            $level,
            $file,
            $line,
            $message,
            $contextStr
        );
    }
    
    private function getLogFilePath($level) {
        $date = date('Y-m-d');
        
        // Logs críticos vão para arquivo separado
        if (in_array($level, [self::EMERGENCY, self::ALERT, self::CRITICAL])) {
            return "{$this->logDir}/critical_{$date}.log";
        }
        
        // Logs de erro
        if ($level === self::ERROR) {
            return "{$this->logDir}/error_{$date}.log";
        }
        
        // Debug em arquivo separado
        if ($level === self::DEBUG) {
            return "{$this->logDir}/debug_{$date}.log";
        }
        
        // Outros logs
        return "{$this->logDir}/application_{$date}.log";
    }
    
    private function rotateLogIfNeeded($logFile) {
        if (!file_exists($logFile)) {
            return;
        }
        
        $fileSize = filesize($logFile);
        
        if ($fileSize >= $this->maxFileSize) {
            $this->rotateLog($logFile);
        }
    }
    
    private function rotateLog($logFile) {
        // Move o arquivo atual
        $timestamp = date('YmdHis');
        $backupFile = $logFile . '.' . $timestamp;
        
        if (rename($logFile, $backupFile)) {
            // Compacta o arquivo antigo
            if (function_exists('gzencode')) {
                $content = file_get_contents($backupFile);
                file_put_contents($backupFile . '.gz', gzencode($content));
                unlink($backupFile);
            }
            
            // Remove arquivos antigos
            $this->cleanOldLogs($logFile);
        }
    }
    
    private function cleanOldLogs($logFile) {
        $pattern = $logFile . '.*';
        $files = glob($pattern);
        
        if (count($files) > $this->maxFiles) {
            // Ordena por data de modificação
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Remove os mais antigos
            $toRemove = count($files) - $this->maxFiles;
            for ($i = 0; $i < $toRemove; $i++) {
                unlink($files[$i]);
            }
        }
    }
    
    private function notifyAdmin($level, $message, $context) {
        if (function_exists('logEmail')) {
            $subject = "[{$level}] Alerta do Sistema";
            $body = "Nível: {$level}\n";
            $body .= "Mensagem: {$message}\n";
            $body .= "Hora: " . date('Y-m-d H:i:s') . "\n";
            $body .= "Contexto: " . json_encode($context, JSON_PRETTY_PRINT);
            
            logEmail($level, $body);
        }
    }
    
    // Métodos de conveniência
    public function emergency($message, $context = []) { return $this->log(self::EMERGENCY, $message, $context); }
    public function alert($message, $context = [])     { return $this->log(self::ALERT, $message, $context); }
    public function critical($message, $context = [])  { return $this->log(self::CRITICAL, $message, $context); }
    public function error($message, $context = [])     { return $this->log(self::ERROR, $message, $context); }
    public function warning($message, $context = [])   { return $this->log(self::WARNING, $message, $context); }
    public function notice($message, $context = [])    { return $this->log(self::NOTICE, $message, $context); }
    public function info($message, $context = [])      { return $this->log(self::INFO, $message, $context); }
    public function debug($message, $context = [])     { return $this->log(self::DEBUG, $message, $context); }
    
    /**
     * Retorna estatísticas dos logs
     */
    public function getStats() {
        $stats = [
            'total_size' => 0,
            'files' => [],
            'last_errors' => []
        ];
        
        $files = glob($this->logDir . '/*.log');
        
        foreach ($files as $file) {
            $size = filesize($file);
            $stats['total_size'] += $size;
            $stats['files'][] = [
                'name' => basename($file),
                'size' => $size,
                'modified' => filemtime($file)
            ];
        }
        
        // Pega últimos erros
        $errorFile = $this->logDir . '/error_' . date('Y-m-d') . '.log';
        if (file_exists($errorFile)) {
            $lines = file($errorFile);
            $stats['last_errors'] = array_slice(array_reverse($lines), 0, 10);
        }
        
        return $stats;
    }
}

// =============================================================================
// MANIPULADOR DE ERROS CUSTOMIZADO
// =============================================================================

class ErrorHandler {
    private $logger;
    private $isDebug;
    
    public function __construct(Logger $logger, $isDebug = false) {
        $this->logger = $logger;
        $this->isDebug = $isDebug;
    }
    
    public function register() {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }
    
    public function handleError($errno, $errstr, $errfile, $errline) {
        // Ignora erros suprimidos com @
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $errorType = $this->getErrorType($errno);
        
        $context = [
            'file' => $errfile,
            'line' => $errline,
            'type' => $errorType
        ];
        
        // Determina o nível do log
        if ($errno & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) {
            $this->logger->error($errstr, $context);
        } elseif ($errno & (E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING)) {
            $this->logger->warning($errstr, $context);
        } else {
            $this->logger->notice($errstr, $context);
        }
        
        // Não impede o manipulador padrão do PHP
        return false;
    }
    
    public function handleException($exception) {
        $context = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'code' => $exception->getCode()
        ];
        
        $this->logger->critical($exception->getMessage(), $context);
        
        // Em produção, mostra página de erro amigável
        if (!$this->isDebug) {
            http_response_code(500);
            include __DIR__ . '/error_pages/500.html';
            exit;
        }
    }
    
    public function handleShutdown() {
        $error = error_get_last();
        
        if ($error !== null && $error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
            $this->logger->emergency('Fatal Error: ' . $error['message'], [
                'file' => $error['file'],
                'line' => $error['line']
            ]);
        }
    }
    
    private function getErrorType($errno) {
        $types = [
            E_ERROR             => 'E_ERROR',
            E_WARNING           => 'E_WARNING',
            E_PARSE             => 'E_PARSE',
            E_NOTICE            => 'E_NOTICE',
            E_CORE_ERROR        => 'E_CORE_ERROR',
            E_CORE_WARNING      => 'E_CORE_WARNING',
            E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
            E_USER_ERROR        => 'E_USER_ERROR',
            E_USER_WARNING      => 'E_USER_WARNING',
            E_USER_NOTICE       => 'E_USER_NOTICE',
            E_STRICT            => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED        => 'E_DEPRECATED',
            E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
        ];
        
        return $types[$errno] ?? 'UNKNOWN';
    }
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
    die("Erro crítico ao inicializar o sistema. Por favor, contate o administrador.");
}

// =============================================================================
// CONFIGURAÇÃO DO TWIG
// =============================================================================

try {
    $loader = new FilesystemLoader(__DIR__ . '/frontend');
    
    $twigConfig = [
        'debug' => $isDebug,
        'cache' => $isDebug ? false : __DIR__ . '/../cache/twig',
        'auto_reload' => $isDebug,
        'strict_variables' => $isDebug
    ];
    
    $twig = new Environment($loader, $twigConfig);
    
    // Extensões de debug
    if ($isDebug) {
        $twig->addExtension(new DebugExtension());
        $profile = new Profile();
        $twig->addExtension(new ProfilerExtension($profile));
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
    $logger->critical('Erro ao inicializar Twig', ['error' => $e->getMessage()]);
    http_response_code(500);
    die("Erro ao inicializar o sistema de templates.");
}

// =============================================================================
// LÓGICA DA APLICAÇÃO
// =============================================================================

try {
    // Verifica autenticação
    if (empty($_SESSION['usuario_id'])) {
        $logger->info('Usuário não autenticado, redirecionando para login', [
            'url_requested' => $_SERVER['REQUEST_URI'] ?? '/'
        ]);
        header("Location: /login_page.php");
        exit();
    }
    
    // Conexão com banco de dados
    try {
        $pdo = Database::getConnection();
        $logger->debug('Conexão com banco de dados estabelecida');
    } catch (PDOException $e) {
        $logger->critical('Erro de conexão com banco de dados', [
            'error' => $e->getMessage(),
            'code' => $e->getCode()
        ]);
        throw new Exception('Erro ao conectar ao banco de dados', 500);
    }
    
    // Carrega configurações
    $settings = getSiteSettings($pdo);
    
    // Verifica manutenção
    if (($settings['manutencao'] ?? false) && $_SERVER['REQUEST_URI'] !== '/manutencao.html') {
        $logger->info('Site em manutenção, redirecionando');
        header('Location: /manutencao.html');
        exit();
    }
    
    // Determina página atual
    $uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $page = $uri ?: 'home';
    
    $logger->info('Página solicitada', ['page' => $page, 'user_id' => $_SESSION['usuario_id']]);
    
    // Carrega dados da página
    $pages = getSitepages($pdo, $page);
    $pageData = $pages['pageData'] ?? null;
    $atualizacao = getLatestExecutionLogByStatus($pdo, 'success');
    
    // Prepara dados para templates
    $dados = [
        'user' => getSiteUsers($pdo, $_SESSION['usuario_id']),
        'settings' => $settings,
        'session' => $_SESSION,
        'pagedata' => $pageData,
        'atualizacao' => $atualizacao,
        'is_debug' => $isDebug
    ];
    
    // Renderiza componentes fixos
    echo $twig->render('header.twig', $dados);
    echo $twig->render('sidebar.twig', $dados);
    
    // Carrega controlador da página
    $controllerPath = __DIR__ . "/backend/{$page}.php";
    $templatePath = "{$page}.twig";
    $data = [];
    
    if (file_exists($controllerPath)) {
        $logger->debug('Carregando controlador', ['controller' => $controllerPath]);
        require_once $controllerPath;
    }
    
    // Combina dados
    $combinedData = array_merge($dados, $data);
    
    // Renderiza página
    try {
        echo $twig->render($templatePath, $combinedData);
        $logger->debug('Página renderizada com sucesso', ['template' => $templatePath]);
    } catch (\Twig\Error\LoaderError $e) {
        $logger->warning('Template não encontrado', ['template' => $templatePath]);
        throw new Exception('Página não encontrada', 404);
    }
    
    // Renderiza footer
    echo $twig->render('footer.twig', $dados);
    
} catch (Exception $e) {
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
    
    // Mapa de títulos de erro
    $errorTitles = [
        400 => 'Requisição Inválida',
        401 => 'Não Autorizado',
        403 => 'Acesso Proibido',
        404 => 'Página Não Encontrada',
        500 => 'Erro Interno do Servidor',
        503 => 'Serviço Indisponível'
    ];
    
    $errorData = $dados ?? [];
    $errorData['errorCode'] = $code;
    $errorData['errorTitle'] = $errorTitles[$code] ?? 'Erro';
    
    // Mensagem baseada no código e modo debug
    if ($code >= 400 && $code < 500) {
        $errorData['errorMessage'] = $e->getMessage() ?: 'Verifique as informações e tente novamente.';
        $errorData['errorDescription'] = 'Há um problema com a requisição.';
    } else {
        $errorData['errorMessage'] = $isDebug ? $e->getMessage() : 'Ocorreu um erro inesperado. Tente novamente mais tarde.';
        $errorData['errorDescription'] = 'O erro foi registrado e será analisado.';
    }
    
    $errorData['pagina_retorno'] = '/';
    $errorData['error_id'] = uniqid('err_'); // ID único para rastrear o erro
    
    // Renderiza página de erro
    try {
        if ($code == 404 && $loader->exists('404.twig')) {
            echo $twig->render('404.twig', $errorData);
        } elseif ($loader->exists('error.twig')) {
            echo $twig->render('error.twig', $errorData);
        } else {
            // Fallback HTML
            echo "<!DOCTYPE html><html><head><title>Erro {$code}</title></head><body>";
            echo "<h1>{$code} - {$errorData['errorTitle']}</h1>";
            echo "<p>{$errorData['errorMessage']}</p>";
            echo "<p><a href='/'>Voltar para página inicial</a></p>";
            if ($isDebug) {
                echo "<pre>" . $e->getTraceAsString() . "</pre>";
            }
            echo "</body></html>";
        }
    } catch (Exception $renderError) {
        $logger->critical('Erro ao renderizar página de erro', ['error' => $renderError->getMessage()]);
        echo "<h1>Erro crítico</h1><p>Contate o administrador.</p>";
    }
}

// Exibição do Profiler (apenas em debug)
if (isset($profile) && $isDebug) {
    if (class_exists(\Twig\Profiler\Dumper\Text::class)) {
        $dumper = new \Twig\Profiler\Dumper\Text();
        echo '<hr><h2>Twig Profiler</h2><pre>';
        echo $dumper->dump($profile);
        echo '</pre>';
    }
}

$logger->info('Requisição finalizada', [
    'page' => $page ?? 'unknown',
    'execution_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms'
]);
?>