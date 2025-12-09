<?php
require __DIR__ . '/classes/Logger.php';
require __DIR__ . '/classes/CronErrorHandler.php';

date_default_timezone_set('America/Sao_Paulo');

/**
 * Diretório de logs (public_html/logs/cron)
 */
$logDir = __DIR__ . '/logs/cron';

/**
 * Logger único do CRON
 */
$logger = Logger::getInstance($logDir, false);

/**
 * ErrorHandler exclusivo para CRON
 */
$errorHandler = new CronErrorHandler($logger);
$errorHandler->register();

/**
 * Log de início (marco de execução)
 */
$logger->info('Wazejob iniciado');
$startTime = microtime(true);
