<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Carregando o autoload do Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Teste: verificando se o autoload está funcionando
use Dotenv\Dotenv;

echo "Autoload carregado com sucesso!\n";

// Verificar se o arquivo .env existe
if (!file_exists(__DIR__ . '.env')) {
    die('Arquivo .env não encontrado!');
}

// Carregar variáveis de ambiente
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

echo "Arquivo .env carregado com sucesso!\n";
echo "EMAIL_USERNAME: " . getenv('EMAIL_USERNAME') . "\n";
