<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

echo "Iniciando...\n";

if (!file_exists(__DIR__ . '/.env')) {
    die('Arquivo .env não encontrado!');
}

use Dotenv\Dotenv;

try {
    $dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
    $dotenv->load();
    echo "Arquivo .env carregado com sucesso.\n";
    echo "EMAIL_USERNAME: " . getenv('EMAIL_USERNAME') . "\n";
    echo "SMTP_HOST: " . getenv('SMTP_HOST') . "\n";
} catch (Exception $e) {
    die("Erro ao carregar o .env: " . $e->getMessage());
}
