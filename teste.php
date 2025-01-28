<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

// Exibir as funções de autoload
var_dump(spl_autoload_functions());

// Teste para verificar o autoload
if (class_exists(Dotenv\Dotenv::class)) {
    echo "Dotenv carregado com sucesso!\n";
} else {
    echo "Dotenv não foi carregado.\n";
}
