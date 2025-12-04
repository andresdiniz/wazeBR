<?php

$startTime = microtime(true);

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/debug.log');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

use Dotenv\Dotenv;

// Carrega .env
$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    logToFile('error', "Arquivo .env não encontrado no caminho: $envPath");
    die("Arquivo .env não encontrado.");
}
try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    logToFile('info', '.env carregado com sucesso');
} catch (Exception $e) {
    logToFile('error', "Erro ao carregar o .env: " . $e->getMessage());
    die("Erro ao carregar o .env: " . $e->getMessage());
}

// Configuração de timezone e debug
date_default_timezone_set('America/Sao_Paulo');
$currentDateTime = date('Y-m-d H:i:s');
echo "Horário de referência: $currentDateTime\n";

if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true') {
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
}

// Função para executar script e medir tempo
function executeScriptWithLogging(string $scriptName, string $path, PDO $pdo)
{
    $start = microtime(true);
    try {
        logToFile('info', "Iniciando script: $scriptName", ['path' => $path]);

        $scriptStart = microtime(true);
        executeScript($scriptName, $path, $pdo);
        $scriptEnd = microtime(true);

        $duration = round($scriptEnd - $scriptStart, 2);
        echo "✅ Script finalizado: $scriptName em $duration segundos\n";

        logToFile('info', "Finalizando script: $scriptName", [
            'path' => $path,
            'tempo_execucao' => $duration
        ]);
    } catch (Exception $e) {
        logToFile('error', "Erro ao executar $scriptName", [
            'message' => $e->getMessage(),
            'path' => $path
        ]);
        error_log("Erro em $scriptName: " . $e->getMessage());
    }
}

// Conexão
$pdo = Database::getConnection();

// Scripts a executar
$scripts = [
    'wazealerts.php'        => '/wazealerts.php',
    'notifications.php'     => '/notifications.php',
    'worker_notifications.php' => '/worker_notifications.php',
    'wazejobtraficc.php'    => '/wazejobtraficc.php',
    'dadoscemadem.php'      => '/dadoscemadem.php',
    'hidrologicocemadem-new.php'=> '/hidrologicocemadem-new.php',
    'gerar_xml.php'         => '/gerar_xml.php',
    'alerts_por_email.php'  => '/alerts_por_email.php' 
];

echo "🟡 Iniciando execução de scripts...\n";

// Executa scripts e mede tempo individual
foreach ($scripts as $scriptName => $path) {
    echo "\n🔹 Executando: $scriptName\n";
    executeScriptWithLogging($scriptName, $path, $pdo);
}

$endTime = microtime(true);
$totalTime = round($endTime - $startTime, 2);

require "gerar_json.php";
echo "Arquivos JSON atualizados.\n";

echo "\n✅ Todos os scripts concluídos.\n";
echo "⏱️ Tempo total de execução: $totalTime segundos\n";
logToFile('info', 'Tempo total de execução do master script', ['totalTime' => $totalTime]);

?>
