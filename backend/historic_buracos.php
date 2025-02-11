<?php
// Configuração de erros detalhada
ini_set('display_errors', 0); // Desliga em produção
ini_set('display_startup_errors', 0);
ini_set('error_reporting', E_ALL);
error_reporting(E_ALL);
session_start();

// Configuração de logs
define('LOG_FILE', __DIR__ . '/../logs/app.log');
set_error_handler('customErrorHandler');
set_exception_handler('customExceptionHandler');
register_shutdown_function('shutdownHandler');

// Função de log centralizada
function logError(string $message, array $context = [], string $level = 'ERROR') {
    $timestamp = date('[Y-m-d H:i:s]');
    $contextString = !empty($context) ? json_encode($context) : '';
    $logMessage = "$timestamp $level: $message $contextString" . PHP_EOL;
    
    // Garante a existência do diretório de logs
    if (!file_exists(dirname(LOG_FILE))) {
        mkdir(dirname(LOG_FILE), 0755, true);
    }
    
    error_log($logMessage, 3, LOG_FILE);
}

// Handlers de erros
function customErrorHandler($code, $message, $file, $line) {
    logError($message, [
        'code' => $code,
        'file' => $file,
        'line' => $line,
        'stack' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
    ], 'ERROR');
}

function customExceptionHandler(Throwable $e) {
    logError($e->getMessage(), [
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'stack' => $e->getTrace()
    ], 'EXCEPTION');
}

function shutdownHandler() {
    $error = error_get_last();
    if ($error !== null) {
        $level = match($error['type']) {
            E_ERROR, E_PARSE, E_CORE_ERROR => 'CRITICAL',
            E_WARNING, E_CORE_WARNING => 'WARNING',
            default => 'ERROR'
        };
        logError($error['message'], [...] , $level);
    }
}

require_once './config/configbd.php';
require_once './vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

try {
    // Verificação de autenticação com log
    if (!isset($_SESSION['usuario_id_parceiro'])) {
        logError('Tentativa de acesso não autenticada', [
            'ip' => $_SERVER['REMOTE_ADDR'],
            'script' => $_SERVER['PHP_SELF']
        ], 'AUTH');
        header('Location: login.php');
        exit;
    }

    $id_parceiro = filter_var($_SESSION['usuario_id_parceiro'], FILTER_SANITIZE_NUMBER_INT);
    logError('Acesso autorizado', ['user_id' => $id_parceiro], 'INFO');

    // Configuração do Twig com tratamento de erros
    $loader = new FilesystemLoader(__DIR__ . '/../frontend');
    $twig = new Environment($loader, [
        'cache' => __DIR__ . '/../cache',
        'debug' => false,
        'auto_reload' => true
    ]);

    $pdo = Database::getConnection();
    logError('Conexão com o banco estabelecida', [], 'INFO');

    $data = [
        'bburacos' => getBuracoAlerts($pdo, $id_parceiro)
    ];

} catch (Throwable $e) {
    logError('Erro fatal no aplicativo', [
        'exception' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], 'CRITICAL');
    die("Ocorreu um erro inesperado. Log registrado: " . $e->getMessage());
}

function getBuracoAlerts(PDO $pdo, int $id_parceiro): array
{
    try {
        $query = "SELECT uuid, country, city, reportRating as confidence, 
                         type, subtype, street, pubMillis 
                  FROM alerts 
                  WHERE type = 'HAZARD' 
                    AND subtype = 'HAZARD_ON_ROAD_POT_HOLE' 
                    AND status = 1";

        $params = [];
        if ($id_parceiro !== 99) {
            $query .= " AND id_parceiro = :id_parceiro";
            $params[':id_parceiro'] = $id_parceiro;
        }

        $query .= " ORDER BY confidence DESC";

        logError('Executando query de alertas', [
            'query' => $query,
            'params' => $params
        ], 'DEBUG');

        $stmt = $pdo->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }

        $stmt->execute();
        
        logError('Query executada com sucesso', [
            'rowCount' => $stmt->rowCount()
        ], 'INFO');

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        logError('Erro na consulta de alertas', [
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
            'query' => $query ?? 'N/A'
        ], 'ERROR');
        return [];
    }
}