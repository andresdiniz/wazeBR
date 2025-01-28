<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

echo "Iniciando script...\n";

if (!file_exists(__DIR__ . '/../.env')) {
    die('Arquivo .env não encontrado!');
}
echo "Arquivo .env encontrado.\n";

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../');
$dotenv->load();
echo "Variáveis carregadas do .env.\n";

// Testando a variável do arquivo .env
$envVar = getenv('EMAIL_USERNAME');
if ($envVar === false) {
    echo "Variável EMAIL_USERNAME não encontrada no .env.\n";
} else {
    echo "EMAIL_USERNAME: $envVar\n";
}
