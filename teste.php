<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
echo $undefined_variable; // Deve gerar um erro Notice


$logFile = 'debug.log';


<?php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm?lng=-43.79274845123291&lat=-20.701415185608553&r=250&data=2025-01-21");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_VERBOSE, true);

$verboseLog = fopen('curl_verbose.log', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $verboseLog);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    echo "Erro cURL: " . curl_error($ch) . "\n";
} else {
    echo "HTTP Code: $httpCode\n";
    echo "Resposta: $response";
}

curl_close($ch);
fclose($verboseLog);
