<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$url = "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm?lng=-43.79274845123291&lat=-20.701415185608553&r=250&data=2025-01-21";

$options = [
    "ssl" => [
        "verify_peer" => false,
        "verify_peer_name" => false,
    ],
];

$context = stream_context_create([
    "http" => [
        "timeout" => 120 // Aumente o tempo limite para 2 minutos
    ]
] + $options);

$response = file_get_contents($url, false, $context);

if ($response === false) {
    echo "Erro ao obter a resposta da URL.";
} else {
    echo "Resposta: $response";
}
?>
