<?php
declare(strict_types=1);

require __DIR__ . '/classes/Logger.php';
require __DIR__ . '/classes/CronErrorHandler.php';

date_default_timezone_set('America/Sao_Paulo');

// Diretório de logs DENTRO do public_html
$logDir = __DIR__ . '/logs/cron';

$logger = Logger::getInstance($logDir, false);
$errorHandler = new CronErrorHandler($logger);
$errorHandler->register();

$logger->info('Wazejob iniciado');

$startTime = microtime(true);

// Autoload e dependências
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

use Dotenv\Dotenv;

/**
 * 1) Carrega .env (MANTENDO a lógica que você já tinha,
 *    só que agora logando com Logger em vez de logToFile + die)
 */
$envPath = __DIR__ . '/.env';

if (!file_exists($envPath)) {
    $logger->critical('Arquivo .env não encontrado', ['path' => $envPath]);

    // Mensagem no console só para quem rodar manual
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Arquivo .env não encontrado no caminho: {$envPath}\n");
    }

    exit(1);
}

try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    $logger->info('.env carregado com sucesso');
} catch (Throwable $e) {
    $logger->critical('Erro ao carregar o .env', [
        'mensagem' => $e->getMessage(),
        'file'     => $e->getFile(),
        'line'     => $e->getLine()
    ]);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Erro ao carregar o .env: " . $e->getMessage() . "\n");
    }

    exit(1);
}

/**
 * 2) Configuração de DEBUG
 *    – Mantém o conceito do $_ENV['DEBUG'], mas sem bagunçar display_errors do CRON
 */
$debug = (($_ENV['DEBUG'] ?? $_ENV['debug'] ?? 'false') === 'true');

if ($debug && PHP_SAPI === 'cli') {
    // Apenas para execução manual, não afeta CRON redirecionado
    ini_set('display_errors', '1');
}

// Horário de referência (MANTIDO: echo + log)
$currentDateTime = date('Y-m-d H:i:s');
$logger->info('Horário de referência', ['datetime' => $currentDateTime]);

if ($debug && PHP_SAPI === 'cli') {
    echo "Horário de referência: {$currentDateTime}\n";
}

/**
 * 3) Função para executar script e medir tempo
 *    – Mantém executeScript()
 *    – Mantém ideia do logToFile, mas usando Logger
 *    – Mantém os echos, mas só em modo debug
 */
function executeScriptWithLogging(
    string $scriptName,
    string $path,
    PDO $pdo,
    Logger $logger,
    bool $debug = false
): bool {
    $start = microtime(true);

    try {
        $logger->info('Iniciando script', [
            'script' => $scriptName,
            'path'   => $path
        ]);

        $scriptStart = microtime(true);
        // A função executeScript continua vindo do seu functions/scripts.php
        executeScript($scriptName, $path, $pdo);
        $scriptEnd = microtime(true);

        $duration = round($scriptEnd - $scriptStart, 2);

        if ($debug && PHP_SAPI === 'cli') {
            echo "✅ Script finalizado: {$scriptName} em {$duration} segundos\n";
        }

        $logger->info('Finalizando script', [
            'script'         => $scriptName,
            'path'           => $path,
            'tempo_execucao' => $duration,
            'memoria_mb'     => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ]);

        return true;
    } catch (Throwable $e) {
        // Mantém a ideia de logToFile + error_log, mas com Logger
        $logger->error("Erro ao executar {$scriptName}", [
            'script'   => $scriptName,
            'path'     => $path,
            'mensagem' => $e->getMessage(),
            'file'     => $e->getFile(),
            'line'     => $e->getLine()
        ]);

        if ($debug && PHP_SAPI === 'cli') {
            fwrite(STDERR, "Erro em {$scriptName}: " . $e->getMessage() . "\n");
        }

        // Não joga a exceção para cima, para não matar o restante dos scripts
        return false;
    }
}

/**
 * 4) Conexão com o banco (MANTIDO)
 */
$pdo = Database::getConnection();

/**
 * 5) Scripts a executar (MANTIDOS exatamente como você mandou)
 */
$scripts = [
    'wazealerts.php'           => '/wazealerts.php',
    'notifications.php'        => '/notifications.php',
    'worker_notifications.php' => '/worker_notifications.php',
    'wazejobtraficc.php'       => '/wazejobtraficc.php',
    'dadoscemadem.php'         => '/dadoscemadem.php',
    'hidrologicocemadem-new.php'=> '/hidrologicocemadem-new.php',
    'gerar_xml.php'            => '/gerar_xml.php',
    'alerts_por_email.php'     => '/alerts_por_email.php'
];

if ($debug && PHP_SAPI === 'cli') {
    echo "🟡 Iniciando execução de scripts...\n";
}

$falhas = [];

/**
 * 6) Loop de execução dos scripts (MANTIDO, mas com log estruturado por script)
 */
foreach ($scripts as $scriptName => $path) {
    if ($debug && PHP_SAPI === 'cli') {
        echo "\n🔹 Executando: {$scriptName}\n";
    }

    $ok = executeScriptWithLogging($scriptName, $path, $pdo, $logger, $debug);

    if (!$ok) {
        $falhas[] = $scriptName;
    }
}

/**
 * 7) Execução do gerar_json.php (MANTIDO, só com logs melhores)
 */
try {
    require __DIR__ . '/gerar_json.php';

    $logger->info('gerar_json.php executado com sucesso');

    if ($debug && PHP_SAPI === 'cli') {
        echo "Arquivos JSON atualizados.\n";
    }
} catch (Throwable $e) {
    $logger->error('Erro ao executar gerar_json.php', [
        'mensagem' => $e->getMessage(),
        'file'     => $e->getFile(),
        'line'     => $e->getLine()
    ]);

    if ($debug && PHP_SAPI === 'cli') {
        fwrite(STDERR, "Erro em gerar_json.php: " . $e->getMessage() . "\n");
    }

    $falhas[] = 'gerar_json.php';
}

/**
 * 8) Métricas finais (MANTÉM seu total de tempo, mas com mais informação em log)
 */
$endTime   = microtime(true);
$totalTime = round($endTime - $startTime, 2);

$logger->info('Tempo total de execução do master script', [
    'totalTime'    => $totalTime,
    'falhas'       => $falhas,
    'memoria_pico' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
]);

if ($debug && PHP_SAPI === 'cli') {
    echo "\n✅ Todos os scripts concluídos.\n";
    echo "⏱️ Tempo total de execução: {$totalTime} segundos\n";

    if (!empty($falhas)) {
        echo "⚠️ Scripts com falha: " . implode(', ', $falhas) . "\n";
    }
}

/**
 * 9) Log final e exit code
 *    – 0 se tudo ok, 1 se houve falhas em algum script
 */
$logger->info('Wazejob finalizado', [
    'falhas'       => $falhas,
    'tempo_total'  => $totalTime
]);

exit(empty($falhas) ? 0 : 1);
