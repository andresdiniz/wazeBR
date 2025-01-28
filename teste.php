<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

var_dump(file_exists(__DIR__ . '/../.env')); // Deve retornar true
var_dump(realpath(__DIR__ . '/../.env'));   // Exibe o caminho absoluto do .env


use Dotenv\Dotenv;

var_dump(realpath(__DIR__ . '/../.env')); // Deve exibir o caminho absoluto do arquivo .env

var_dump(getenv('EMAIL_USERNAME'));


if (!file_exists(__DIR__ . '/../.env')) {
    die('Arquivo .env não encontrado!');
}

$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
$dotenv->load();
