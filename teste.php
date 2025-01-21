<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function consultarLocalizacaoKm($longitude, $latitude, $raio = 250, $data = null) {
    // Define a URL base da API
    $urlBase = "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm";

    // Usa a data atual se nenhuma data for fornecida
    if (!$data) {
        $data = date('Y-m-d');
    }

    // Constrói a URL com os parâmetros
    $url = sprintf(
        "%s?lng=%s&lat=%s&r=%d&data=%s",
        $urlBase,
        urlencode($longitude),
        urlencode($latitude),
        $raio,
        urlencode($data)
    );

    // Inicializa o cURL
    $ch = curl_init();

    // Configurações do cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Se necessário para evitar problemas com SSL

    // Executa a requisição
    $response = curl_exec($ch);

	if ($response === false) {
		throw new Exception('Erro ao executar a requisição: ' . curl_error($ch));
	}

	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

	if ($httpCode !== 200) {
		throw new Exception("Erro na requisição, código HTTP: $httpCode, resposta: $response");
	}

    // Verifica erros
    if (curl_errno($ch)) {
        throw new Exception('Erro ao executar a requisição: ' . curl_error($ch));
    }

    // Fecha a conexão cURL
    curl_close($ch);

    // Decodifica a resposta JSON
    $data = json_decode($response, true);

    // Verifica se a resposta contém o campo "km"
    if (is_array($data) && isset($data[0]['km'])) {
        return $data[0]['km'];
    }

    // Retorna null caso não haja "km" na resposta
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
