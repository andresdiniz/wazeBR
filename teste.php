<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$logFile = 'debug.log';

// Inicializar cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm?lng=-43.79274845123291&lat=-20.701415185608553&r=250&data=2025-01-21");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);          // Aumentado o tempo limite
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);   // Aumentado o tempo limite para conexão
curl_setopt($ch, CURLOPT_VERBOSE, true);

// Registrar logs detalhados
$verboseLog = fopen('curl_verbose.log', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $verboseLog);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    $curlError = curl_error($ch);
    echo "Erro cURL: $curlError\n";
    file_put_contents($logFile, "Erro cURL: $curlError\n", FILE_APPEND);
} else {
    echo "HTTP Code: $httpCode\n";
    echo "Resposta: $response";
    file_put_contents($logFile, "HTTP Code: $httpCode\nResposta: $response\n", FILE_APPEND);
}

curl_close($ch);
fclose($verboseLog);
?>
