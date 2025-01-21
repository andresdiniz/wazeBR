$url = "https://189.9.19.9/sgplan/apigeo/rotas/localizarkm?lng=-43.79944324493409&lat=-20.734276310454153&r=250&data=2025-1-21";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

// Cabeçalhos
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Host: servicos.dnit.gov.br',
    'Accept: */*',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36'
]);

$response = curl_exec($ch);
if ($response === false) {
    echo "Erro: " . curl_error($ch);
} else {
    echo "Resposta: $response";
}

curl_close($ch);
