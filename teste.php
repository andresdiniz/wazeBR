<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

echo "Autoload carregado com sucesso!\n";

// Verificar se o arquivo .env existe
$envPath = __DIR__ . '/.env';
echo "Caminho para .env: $envPath\n";

if (!file_exists($envPath)) {
    die("Arquivo .env não encontrado no caminho: $envPath");
}

// Carregar variáveis de ambiente
try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    echo "Arquivo .env carregado com sucesso!\n";
    echo "EMAIL_USERNAME: " . getenv('EMAIL_USERNAME') . "\n";
} catch (Exception $e) {
    die("Erro ao carregar o .env: " . $e->getMessage());
}
