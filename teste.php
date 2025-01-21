<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
echo $undefined_variable; // Deve gerar um erro Notice


$logFile = 'debug.log';

try {
    file_put_contents($logFile, "Script iniciado\n", FILE_APPEND);

    $urlBase = "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm";
    $url = sprintf(
        "%s?lng=%s&lat=%s&r=%d&data=%s",
        $urlBase,
        urlencode(-43.79274845123291),
        urlencode(-20.701415185608553),
        250,
        urlencode(date('Y-m-d'))
    );

    file_put_contents($logFile, "URL: $url\n", FILE_APPEND);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        $error = curl_error($ch);
        file_put_contents($logFile, "Erro cURL: $error\n", FILE_APPEND);
        throw new Exception('Erro cURL: ' . $error);
    }

    file_put_contents($logFile, "HTTP Code: $httpCode\nResposta: $response\n", FILE_APPEND);

    if ($httpCode !== 200) {
        throw new Exception("HTTP Code inválido: $httpCode\nResposta: $response");
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $jsonError = json_last_error_msg();
        file_put_contents($logFile, "Erro JSON: $jsonError\n", FILE_APPEND);
        throw new Exception("Erro JSON: $jsonError");
    }

    if (isset($data[0]['km'])) {
        file_put_contents($logFile, "KM encontrado: " . $data[0]['km'] . "\n", FILE_APPEND);
        echo "KM encontrado: " . $data[0]['km'];
    } else {
        file_put_contents($logFile, "Nenhum KM encontrado.\n", FILE_APPEND);
        echo "Nenhum KM encontrado.";
    }
} catch (Exception $e) {
    file_put_contents($logFile, "Erro capturado: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "Erro capturado: " . $e->getMessage();
}
