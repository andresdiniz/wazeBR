<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();


echo getenv('EMAIL_USERNAME'); // Deve exibir: o EMAIL_USERNAME do arquivo .env


if (!file_exists(__DIR__ . '/../.env')) {
    die('Arquivo .env não encontrado!');
}