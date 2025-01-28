<?php
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

if (class_exists(Dotenv::class)) {
    echo "Dotenv está funcionando!";
} else {
    echo "Dotenv não foi carregado!";
}
