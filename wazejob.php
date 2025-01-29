<?php

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/debug.log'); // Direciona logs para um arquivo
require_once './vendor/autoload.php';
require_once __DIR__ . '/config/configbd.php';

// Função de logging centralizada
function logToFile($level, $message, $context = []) {
    // Define o caminho do log
    $logFile = __DIR__ . '/../logs/debug.log';

    // Se o diretório de logs não existir, cria-o
    if (!is_dir(__DIR__ . '/../logs')) {
        mkdir(__DIR__ . '/../logs', 0777, true);
    }

    // Formata a mensagem de log com data, nível e contexto
    $logMessage = sprintf(
        "[%s] [%s] %s %s\n", 
        date('Y-m-d H:i:s'), 
        strtoupper($level), 
        $message, 
        json_encode($context)
    );

    // Registra a mensagem de log no arquivo
    error_log($logMessage, 3, $logFile);
}

// Configurações de ambiente
$envPath = __DIR__ . '/.env';

use Dotenv\Dotenv;

if (!file_exists($envPath)) {
    logToFile('error', "Arquivo .env não encontrado no caminho: $envPath");
    die("Arquivo .env não encontrado.");
}

try {
    // Carrega o .env
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    logToFile('info', '.env carregado com sucesso');
} catch (Exception $e) {
    logToFile('error', "Erro ao carregar o .env: " . $e->getMessage());
    die("Erro ao carregar o .env: " . $e->getMessage());
}

// Configuração de debug
if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] == 'true') {
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/debug.log');
}

// Função de execução de scripts com log
function executeScriptWithLogging($scriptName, $path) {
    try {
        logToFile('info', "Iniciando script: $scriptName", ['path' => $path]);
        executeScript($scriptName, $path,$pdo);
        logExecution($scriptName, $status, "Finalizando script: $scriptName", ['path' => $path],$pdo);
        logToFile('info', "Finalizando script: $scriptName", ['path' => $path]);
    } catch (Exception $e) {
        logToFile('error', "Erro em $scriptName", ['message' => $e->getMessage(), 'path' => $path]);
        logExecution($scriptName, 'error', "Erro em $scriptName", ['message' => $e->getMessage(), 'path' => $path]);
    }
}

// Executando os scripts com verificação de erros
executeScriptWithLogging('wazealerts.php', '/wazealerts.php');
executeScriptWithLogging('wazejobtraficc.php', '/wazejobtraficc.php');
executeScriptWithLogging('dadoscemadem.php', '/dadoscemadem.php');
executeScriptWithLogging('hidrologicocemadem.php', '/hidrologicocemadem.php');
executeScriptWithLogging('gerar_xml.php', '/gerar_xml.php');

?>
