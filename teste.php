<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$url = "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm?lng=-43.79944324493409&lat=-20.734276310454153&r=250&data=2025-1-21";

// Inicializa cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Ignora verificação SSL
curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Aumenta o tempo limite para 2 minutos
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30); // Aumenta o tempo para esperar pela conexão

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    echo "Erro cURL: " . curl_error($ch) . "\n";
} else {
    echo "HTTP Code: $httpCode\n";
    echo "Resposta: $response\n";
}

curl_close($ch);
?>
