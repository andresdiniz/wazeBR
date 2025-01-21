<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$url = "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm?lng=-43.79944324493409&lat=-20.734276310454153&r=250&data=2025-1-21";

// Inicializa cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Tempo limite de 2 minutos
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30); // Tempo limite de conexão de 30 segundos

// Adiciona cabeçalhos HTTP conforme o navegador
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: */*',
    'Accept-Encoding: gzip, deflate, br, zstd',
    'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7,tr;q=0.6',
    'Connection: keep-alive',
    'Host: servicos.dnit.gov.br',
    'Referer: https://servicos.dnit.gov.br/vgeo/',
    'Sec-Fetch-Dest: empty',
    'Sec-Fetch-Mode: cors',
    'Sec-Fetch-Site: same-origin',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36',
    'sec-ch-ua: "Not A(Brand";v="8", "Chromium";v="132", "Google Chrome";v="132"',
    'sec-ch-ua-mobile: ?0',
    'sec-ch-ua-platform: "Windows"'
]);

// Log detalhado
curl_setopt($ch, CURLOPT_VERBOSE, true);
$logFile = fopen('curl_debug.log', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $logFile);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    echo "Erro cURL: " . curl_error($ch) . "\n";
} else {
    echo "HTTP Code: $httpCode\n";
    echo "Resposta: $response\n";
}

curl_close($ch);
fclose($logFile);
?>
