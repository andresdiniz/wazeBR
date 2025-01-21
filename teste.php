<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// URL base e parâmetros
$urlBase = "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm";
$params = [
    'lng' => -43.79274845123291,
    'lat' => -20.701415185608553,
    'r' => 250,
    'data' => '2025-01-21',
];

// Construção da URL com parâmetros codificados
$url = $urlBase . '?' . http_build_query($params);

$logFile = 'curl_debug.log';

// Inicializar cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Ignorar verificação SSL para testes
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_VERBOSE, true);

// Registrar log detalhado
$verboseLog = fopen('curl_verbose.log', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $verboseLog);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    $error = curl_error($ch);
    echo "Erro cURL: $error\n";
    file_put_contents($logFile, "Erro cURL: $error\n", FILE_APPEND);
} else {
    echo "HTTP Code: $httpCode\n";
    echo "Resposta: $response\n";
    file_put_contents($logFile, "HTTP Code: $httpCode\nResposta: $response\n", FILE_APPEND);
}

curl_close($ch);
fclose($verboseLog);
?>
