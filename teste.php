<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use Dotenv\Dotenv;

var_dump(realpath(__DIR__ . '/../.env')); // Deve exibir o caminho absoluto do arquivo .env

var_dump(getenv('EMAIL_USERNAME'));


if (!file_exists(__DIR__ . '/../.env')) {
    die('Arquivo .env não encontrado!');
}

$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
$dotenv->load();
