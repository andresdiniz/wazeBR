<?php

$globalStartTime = microtime(true);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

use Dotenv\Dotenv;

date_default_timezone_set('America/Sao_Paulo');
$currentDateTime = date('Y-m-d H:i:s');

// Carrega variáveis de ambiente
$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    logToFile('error', "Arquivo .env não encontrado: $envPath");
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

// Debug
if ($_ENV['DEBUG'] === 'true') {
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/debug.log');
    if (!is_dir(__DIR__ . '/../logs')) {
        mkdir(__DIR__ . '/../logs', 0777, true);
    }
}

set_time_limit(1200); // 20 minutos

echo "Horário de início: $currentDateTime\n";
echo "Iniciando execução dos scripts...\n";

// Lista de scripts
$scripts = [
    'wazealerts.php',
    'wazejobtraficc.php',
    'dadoscemadem.php',
    'hidrologicocemadem.php',
    'gerar_xml.php',
    'alerts_por_email.php'
];

// Contadores
$totalScripts = count($scripts);
$successCount = 0;
$errorCount = 0;

foreach ($scripts as $scriptName) {
    $start = microtime(true);
    $scriptPath = __DIR__ . $scriptName;

    echo "\n🔄 Iniciando: $scriptName\n";

    try {
        executeScript($scriptName, $scriptPath, $pdo);
        $successCount++;
        echo "✅ Finalizado com sucesso: $scriptName\n";
    } catch (Exception $e) {
        $errorCount++;
        logToFile('error', "Erro ao executar $scriptName: " . $e->getMessage());
        echo "❌ Erro ao executar $scriptName: " . $e->getMessage() . "\n";
    }

    $end = microtime(true);
    echo "⏱️ Tempo de execução: " . round($end - $start, 2) . " segundos\n";
}

// Tempo total
$globalEndTime = microtime(true);
$totalTime = round($globalEndTime - $globalStartTime, 2);

echo "\n📊 Resumo da execução:\n";
echo "Total de scripts: $totalScripts\n";
echo "Sucesso: $successCount\n";
echo "Erros: $errorCount\n";
echo "⏱️ Tempo total: $totalTime segundos\n";
echo "Horário de término: " . date('Y-m-d H:i:s') . "\n";

?>
