<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

var_dump(file_exists(__DIR__ . '/../.env')); // Deve retornar true
var_dump(realpath(__DIR__ . '/../.env'));   // Exibe o caminho absoluto do .env

if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Ignorar comentários
        }
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
} else {
    die('Arquivo .env não encontrado!');
}

// Testando a variável do .env
echo getenv('EMAIL_USERNAME');
