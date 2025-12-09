<?php

$startTime = microtime(true);

$debug = ($_ENV['DEBUG'] ?? 'false') === 'true';

$scripts = [ ... ];

foreach ($scripts as $name => $path) {
    executeScriptWithLogging($name, $path, $pdo, $logger, $debug);
}

require 'gerar_json.php';

$logger->info('Wazejob concluído', [
    'tempo_total' => round(microtime(true) - $startTime, 2)
]);

exit(0);
