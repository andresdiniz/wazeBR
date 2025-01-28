<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

if (!file_exists(__DIR__ . '/../.env')) {
    die('Arquivo .env não encontrado!');
}

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../');
$dotenv->load();

// Testando a variável do arquivo .env
echo getenv('EMAIL_USERNAME');
