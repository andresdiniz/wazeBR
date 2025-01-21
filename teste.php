<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function consultarLocalizacaoKm($longitude, $latitude, $raio = 250, $data = null) {
    $logFile = 'debug.log';

    file_put_contents($logFile, "Iniciando consulta\n", FILE_APPEND);

    $urlBase = "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm";
    if (!$data) {
        $data = date('Y-m-d');
    }

    $url = sprintf(
        "%s?lng=%s&lat=%s&r=%d&data=%s",
        $urlBase,
        urlencode($longitude),
        urlencode($latitude),
        $raio,
        urlencode($data)
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
        file_put_contents($logFile, "cURL Error: $error\n", FILE_APPEND);
        throw new Exception('Erro ao executar a requisição: ' . $error);
    }

    file_put_contents($logFile, "HTTP Code: $httpCode\n", FILE_APPEND);
    file_put_contents($logFile, "Resposta: $response\n", FILE_APPEND);

    if ($httpCode !== 200) {
        throw new Exception("Erro na requisição, código HTTP: $httpCode, resposta: $response");
    }

    curl_close($ch);

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $jsonError = json_last_error_msg();
        file_put_contents($logFile, "Erro no JSON: $jsonError\n", FILE_APPEND);
        throw new Exception("Erro ao decodificar JSON: $jsonError");
    }

    if (is_array($data) && isset($data[0]['km'])) {
        return $data[0]['km'];
    }

    return null;
}

// Exemplo de uso
try {
    $km = consultarLocalizacaoKm(-43.79274845123291, -20.701415185608553);
    if ($km !== null) {
        echo "KM encontrado: $km";
    } else {
        echo "Nenhum KM encontrado na resposta.";
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}
?>
