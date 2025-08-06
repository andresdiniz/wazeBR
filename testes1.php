<?php

ini_set('log_errors', 1);
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/../logs/debug.log');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';
require_once './class/class.php'; // Aqui deve estar a ApiBrasilWhatsApp

// Dados de autenticação e destino
$deviceToken = 'fec20e76-c481-4316-966d-c09798ae0d95';
$authToken   = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczovL3BsYXRhZm9ybWEuYXBpYnJhc2lsLmNvbS5ici9hdXRoL2NhbGxiYWNrIiwiaWF0IjoxNzUzMTczMzE4LCJleHAiOjE3ODQ3MDkzMTgsIm5iZiI6MTc1MzE3MzMxOCwianRpIjoia1pUMFBrWEJoRHA1Q0NPbSIsInN1YiI6Ijg1MiIsInBydiI6IjIzYmQ1Yzg5NDlmNjAwYWRiMzllNzAxYzQwMDg3MmRiN2E1OTc2ZjcifQ.opUGRf8f1unfjS_oJtChpoUv8Q0yYGNJChyQ8xoD5Bs';
$numero      = '5531971408208'; // Número com DDI + DDD
$mensagem    = 'Olá! Esta é uma mensagem automática.';

// Instancia a classe corretamente com os tokens
$api = new ApiBrasilWhatsApp($deviceToken, $authToken);

// Envia a mensagem de texto
$resposta = $api->enviarTexto($numero, $mensagem);

// Exibe a resposta
echo '<pre>';
print_r(json_decode($resposta, true));
echo '</pre>';
