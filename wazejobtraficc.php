<?php

// Configurações principais
set_time_limit(1200);
header("Content-type: application/json; charset=utf-8");

require_once "../db/Database.php";

class JsonProcessor {
    private $database;
    private $logFile;

    // Construtor para inicializar a conexão com o banco de dados e configurar o arquivo de log
    public function __construct($logFile = 'process_log.txt') {
        $this->database = new Database();
        $this->logFile = $logFile;
    }

    // Função para registrar mensagens no arquivo de log
    private function logMessage($message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
    }

    // Função para buscar e decodificar JSON de uma URL
    private function fetchJsonFromUrl($url) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode !== 200) {
            throw new Exception("Falha ao obter JSON da URL: $url (HTTP $httpCode)");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Erro ao decodificar JSON: " . json_last_error_msg());
        }

        return $data;
    }

    // Função para inserir ou atualizar dados em uma tabela
    private function upsert($table, $data, $uniqueKey) {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ":$col", $columns);
        $onDuplicate = array_map(fn($col) => "$col = VALUES($col)", $columns);

        $sql = "INSERT INTO $table (" . implode(",", $columns) . ") VALUES (" . implode(",", $placeholders) . ") ";
        $sql .= "ON DUPLICATE KEY UPDATE " . implode(",", $onDuplicate);

        $this->database->query($sql, $data);
    }

    // Processar informações gerais de tráfego
    public function processTrafficData($urls) {
        $start = microtime(true);
        foreach ($urls as $urlData) {
            $urlStart = microtime(true);
            try {
                $url = $urlData['url'];
                $json = $this->fetchJsonFromUrl($url);

                $this->upsert('urls', ['url' => $url, 'description' => $urlData['description']], 'url');

                foreach ($json['main_routes'] as $route) {
                    $this->processRoute($route);
                }

                $urlEnd = microtime(true);
                $this->logMessage("Processamento da URL '{$url}' concluído em " . round($urlEnd - $urlStart, 2) . " segundos.");
            } catch (Exception $e) {
                $this->logMessage("Erro ao processar URL: {$urlData['url']}, erro: {$e->getMessage()}");
            }
        }
        $end = microtime(true);
        $this->logMessage("Processamento completo de todas as URLs em " . round($end - $start, 2) . " segundos.");
    }

    // Processar uma rota individual
    private function processRoute($route) {
        $start = microtime(true);

        $routeData = [
            'name' => $route['name'],
            'length' => $route['length'],
            'congestion_level' => $route['congestion_level'],
            'travel_time' => $route['travel_time'],
            'average_speed' => $route['length'] / max($route['travel_time'], 1),
        ];

        $this->upsert('routes', $routeData, 'name');
        $this->processRouteLines($route['name'], $route['coordinates']);

        foreach ($route['sub_routes'] as $subRoute) {
            $this->processSubRoute($route['name'], $subRoute);
        }

        foreach ($route['irregularities'] as $irregularity) {
            $this->processIrregularity($route['name'], $irregularity);
        }

        $end = microtime(true);
        $this->logMessage("Processamento da rota '{$route['name']}' concluído em " . round($end - $start, 2) . " segundos.");
    }

    // Processar linhas de rota (coordenadas geográficas)
    private function processRouteLines($routeName, $coordinates) {
        foreach ($coordinates as $index => $coord) {
            $data = [
                'route_name' => $routeName,
                'sequence' => $index,
                'latitude' => $coord['lat'],
                'longitude' => $coord['lng'],
            ];
            $this->upsert('route_lines', $data, 'sequence');
        }
    }

    // Processar sub-rota
    private function processSubRoute($routeName, $subRoute) {
        $data = [
            'route_name' => $routeName,
            'sub_route_name' => $subRoute['name'],
            'alert_type' => $subRoute['alert_type'],
            'alert_description' => $subRoute['alert_description'],
        ];
        $this->upsert('subroutes', $data, 'sub_route_name');
    }

    // Processar irregularidades
    private function processIrregularity($routeName, $irregularity) {
        $data = [
            'route_name' => $routeName,
            'type' => $irregularity['type'],
            'description' => $irregularity['description'],
            'severity' => $irregularity['severity'],
        ];
        $this->upsert('irregularities', $data, 'type');
    }
}

// Uso da classe
$processor = new JsonProcessor();
$urls = [
    ['url' => 'https://www.waze.com/row-partnerhub-api/feeds-tvt/?id=1725279881116', 'description' => 'Data 1'],
    ['url' => 'https://www.waze.com/row-partnerhub-api/feeds-tvt/?id=12699055487', 'description' => 'Data 2'],
];
$processor->processTrafficData($urls);