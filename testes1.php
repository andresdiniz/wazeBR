<?php

require_once './class/class.php'; // Certifique-se de apontar para o caminho certo

// Dados de autenticação e destino
$deviceToken = 'fec20e76-c481-4316-966d-c09798ae0d95';
$authToken   = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczovL3BsYXRhZm9ybWEuYXBpYnJhc2lsLmNvbS5ici9hdXRoL2NhbGxiYWNrIiwiaWF0IjoxNzUzMTczMzE4LCJleHAiOjE3ODQ3MDkzMTgsIm5iZiI6MTc1MzE3MzMxOCwianRpIjoia1pUMFBrWEJoRHA1Q0NPbSIsInN1YiI6Ijg1MiIsInBydiI6IjIzYmQ1Yzg5NDlmNjAwYWRiMzllNzAxYzQwMDg3MmRiN2E1OTc2ZjcifQ.opUGRf8f1unfjS_oJtChpoUv8Q0yYGNJChyQ8xoD5Bs';
$numero      = '5531971408208'; // Número com DDI + DDD
$mensagem    = 'Olá! Esta é uma mensagem automática.';

// Instancia a classe
$api = new EvolutionAPI();

// Envia a mensagem de texto
$resposta = $api->enviarTexto($deviceToken, $authToken, $numero, $mensagem);

// Exibe a resposta
echo '<pre>';
print_r(json_decode($resposta, true));
echo '</pre>';
