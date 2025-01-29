<?php

/*ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/debug.log');
*/
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

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

/**
 * Executa um script e registra logs antes e depois da execução
 */
function executeScriptWithLogging(string $scriptName, string $path, PDO $pdo)
{
    try {
        logToFile('info', "Iniciando script: $scriptName", ['path' => $path]);
        executeScript($scriptName, $path, $pdo);
        logToFile('info', "Finalizando script: $scriptName", ['path' => $path]);
    } catch (Exception $e) {
        logToFile('error', "Erro ao executar $scriptName", [
            'message' => $e->getMessage(),
            'path' => $path
        ]);
        error_log("Erro em $scriptName: " . $e->getMessage());
    }
}

// Lista de scripts a serem executados
$scripts = [
    'wazealerts.php'        => '/wazealerts.php',
    'wazejobtraficc.php'    => '/wazejobtraficc.php',
    'dadoscemadem.php'      => '/dadoscemadem.php',
    'hidrologicocemadem.php'=> '/hidrologicocemadem.php',
    'gerar_xml.php'         => '/gerar_xml.php'
];

// Executa cada script da lista
foreach ($scripts as $scriptName => $path) {
    executeScriptWithLogging($scriptName, $path, $pdo);
}
?>cemadem.php', $pdo);
executeScriptWithLogging('gerar_xml.php', '/gerar_xml.php', $pdo);

?>
