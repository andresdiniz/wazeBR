<?php

require_once './vendor/autoload.php';
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

// Função de logging centralizada
function logToFile($level, $message, $context = []) {
    // Define o caminho do log
    $logFile = __DIR__ . '/../logs/debug.log';

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
}else{
    ini_set('display_errors', 1);
}


// Conexão com o banco de dados
$pdo = Database::getConnection();

// Função de execução de scripts com log
function executeScriptWithLogging($scriptName, $path, $pdo) {
    try {
        logToFile('info', "Iniciando script: $scriptName", ['path' => $path]);
        executeScript($scriptName, $path, $pdo); // Passando $pdo aqui
        logToFile('info', "Finalizando script: $scriptName", ['path' => $path]);
    } catch (Exception $e) {
        echo'cheguei aqui';
        logToFile('error', "Erro em $scriptName", ['message' => $e->getMessage(), 'path' => $path]);
        error_log('error', "Erro em $scriptName", ['message' => $e->getMessage(), 'path' => $path]);
    }
}

// Executando os scripts com verificação de erros
executeScriptWithLogging('wazealerts.php', '/wazealerts.php', $pdo);
executeScriptWithLogging('wazejobtraficc.php', '/wazejobtraficc.php', $pdo);
executeScriptWithLogging('dadoscemadem.php', '/dadoscemadem.php', $pdo);
executeScriptWithLogging('hidrologicocemadem.php', '/hidrologicocemadem.php', $pdo);
executeScriptWithLogging('gerar_xml.php', '/gerar_xml.php', $pdo);

?>
