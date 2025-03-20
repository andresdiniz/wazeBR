<?php
ini_set('display_errors', 1);  // Exibe os erros diretamente no navegador
ini_set('display_startup_errors', 1);  // Exibe erros de inicialização

// Define o nível de erro a ser relatado
error_reporting(E_ALL);  // Reporta todos os tipos de erros, warnings e notices
// Inicia o servidor PHP
// Defina o cabeçalho de conteúdo como JSON
header('Content-Type: application/json');

// Carregar o autoloader do Composer (se você usar o Composer)
require 'vendor/autoload.php';

// Usando a biblioteca phpMQTT para conectar ao HiveMQ
use Bluerhinos\phpMQTT;

$server = 'fd8434428f3342ffa0a2a7a61de19b1c.s1.eu.hivemq.cloud'; // Broker HiveMQ
$port = 8883;  // Porta segura SSL
$username = 'hivemq.webclient.1742383865187';
$password = '3x2FTZybgU89>n$G*<Ee';
$client_id = 'php_mqtt_client';

// Tópico no qual estamos interessados
$topic = 'sistema/nivel';

// Variável para armazenar os dados recebidos
$jsonData = [];

function onConnect($mqtt) {
    echo "Conectado ao Broker MQTT!\n";
    // Inscrever-se no tópico desejado
    $mqtt->subscribe([$GLOBALS['topic'] => ['qos' => 0, 'function' => 'onMessage']]);
}

function onMessage($topic, $message) {
    global $jsonData;

    echo "Mensagem recebida: $message\n";

    // Aqui estamos assumindo que a mensagem é um JSON
    $jsonData = json_decode($message, true);

    // Para debugar, podemos salvar a mensagem recebida no log
    file_put_contents('log.txt', date("Y-m-d H:i:s") . " - $message\n", FILE_APPEND);
}

// Criar a instância do cliente MQTT
$mqtt = new phpMQTT($server, $port, $client_id);

if ($mqtt->connect(true, NULL, $username, $password)) {
    onConnect($mqtt);

    // Aguardar as mensagens
    while ($mqtt->loop()) {
        // O loop() continuará aguardando as mensagens do broker
        // Não podemos fazer mais nada aqui porque o loop é bloqueante
    }

    $mqtt->close();
} else {
    echo "Falha na conexão com o Broker MQTT\n";
}

// Acesse os dados via uma URL HTTP simples
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    // Retorna os dados como JSON
    if (!empty($jsonData)) {
        echo json_encode($jsonData);
    } else {
        echo json_encode(["status" => "erro", "mensagem" => "Sem dados disponíveis"]);
    }
}
