<?php

$startScriptTime = microtime(true);

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/debug.log');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';
require_once __DIR__ . '/config/configs.php';

use Dotenv\Dotenv;

$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    die("Arquivo .env não encontrado no caminho: $envPath");
}

try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    error_log("Erro ao carregar o .env: " . $e->getMessage());
    logEmail("error", "Erro ao carregar o .env: " . $e->getMessage());
    die("Erro ao carregar o .env: " . $e->getMessage());
}

date_default_timezone_set('America/Sao_Paulo');

$deviceToken = 'fec20e76-c481-4316-966d-c09798ae0d95';
$authToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczovL3BsYXRhZm9ybWEuYXBpYnJhc2lsLmNvbS5ici9hdXRoL2NhbGxiYWNrIiwiaWF0IjoxNzUzMTczMzE4LCJleHAiOjE3ODQ3MDkzMTgsIm5iZiI6MTc1MzE3MzMxOCwianRpIjoia1pUMFBrWEJoRHA1Q0NPbSIsInN1YiI6Ijg1MiIsInBydiI6IjIzYmQ1Yzg5NDlmNjAwYWRiMzllNzAxYzQwMDg3MmRiN2E1OTc2ZjcifQ.opUGRf8f1unfjS_oJtChpoUv8Q0yYGNJChyQ8xoD5Bs';
                // Lista de números para enviar


$apiWhatsApp = new ApiBrasilWhatsApp($deviceToken, $authToken);
$statusInstancia = json_decode($apiWhatsApp->getQueueStatus(), true);

var_dump(statusInstancia);

