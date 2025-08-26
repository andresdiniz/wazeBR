<?php
$startTimeTotal = microtime(true);

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/debug.log');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';
require_once __DIR__ . '/config/configs.php';

use Dotenv\Dotenv;

$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    die("Arquivo .env não encontrado no caminho: $envPath");
}

try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    error_log("Erro ao carregar o .env: " . $e->getMessage());
    logEmail("error", "Erro ao carregar o .env: " . $e->getMessage());
    die("Erro ao carregar o .env: " . $e->getMessage());
}

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();

    // Consulta inicial para pegar alerts com km null e id_parceiro = 2
    $stmt = $pdo->prepare("
        SELECT uuid, location_x, location_y 
        FROM alerts 
        WHERE id_parceiro = 2 AND km IS NULL 
        ORDER BY uuid ASC
    ");
    $stmt->execute();

    $batchSize = 1000;
    $alerts = [];
    $totalAtualizados = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $alerts[] = $row;

        if (count($alerts) >= $batchSize) {
            $atualizados = atualizarKm($alerts, $pdo);
            $totalAtualizados += $atualizados;
            $alerts = [];
        }
    }

    if (!empty($alerts)) {
        $atualizados = atualizarKm($alerts, $pdo);
        $totalAtualizados += $atualizados;
    }

    $pdo->commit();

    $tempoTotal = microtime(true) - $startTimeTotal;
    echo "Processo finalizado com sucesso.\n";
    echo "Total de alertas atualizados: $totalAtualizados\n";
    echo "Tempo total de execução: " . round($tempoTotal, 2) . " segundos\n";

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Erro no processamento: " . $e->getMessage());
    die("Erro: " . $e->getMessage());
}

/**
 * Função para atualizar km no banco
 * Retorna quantidade de alertas atualizados
 */
function atualizarKm(array $alerts, PDO $pdo) {
    $updateStmt = $pdo->prepare("UPDATE alerts SET km = :km WHERE uuid = :uuid");
    $atualizados = 0;

    foreach ($alerts as $alert) {
        $startTime = microtime(true); // tempo individual do alerta

        $limiteKm = 2; // limite em km
        $km = encontrarKmPorCoordenadasEPRatualiza($alert['location_x'], $alert['location_y'], $limiteKm);

        if ($km !== null) {
            try {
                $updateStmt->execute([
                    ':km' => $km,
                    ':uuid' => $alert['uuid']
                ]);
                $atualizados++;
            } catch (Exception $e) {
                error_log("Erro ao atualizar uuid {$alert['uuid']}: " . $e->getMessage());
            }
        }

        $tempoAlerta = microtime(true) - $startTime;
        echo "UUID {$alert['uuid']} atualizado em " . round($tempoAlerta, 4) . " segundos\n";
    }

    return $atualizados;
}

function encontrarKmPorCoordenadasEPRatualiza($latitude, $longitude, $limiteKm = null) {
    $kmlPath = __DIR__ . '/kmls/eprviamineira/doc.kml';
    echo "Caminho do KML: " . realpath($kmlPath) . PHP_EOL;

    if (!file_exists($kmlPath)) {
        throw new Exception("Arquivo KML não encontrado: $kmlPath");
    }

    $xml = simplexml_load_file($kmlPath);
    if (!$xml) {
        throw new Exception("Erro ao carregar o KML: $kmlPath");
    }

    // Captura namespaces do KML
    $ns = $xml->getNamespaces(true);

    // Se houver namespace, registra para poder acessar os elementos
    $xml->registerXPathNamespace('k', $ns[''] ?? '');

    $placemarks = $xml->xpath('//k:Placemark');

    if (!$placemarks) {
        throw new Exception("Nenhum Placemark encontrado no KML.");
    }

    $menorDistancia = PHP_FLOAT_MAX;
    $kmEncontrado = null;

    foreach ($placemarks as $placemark) {
        // Obtém as coordenadas
        $coords = trim((string)$placemark->Point->coordinates);
        if (!$coords) continue;

        list($lon, $lat, $alt) = explode(',', $coords);

        // Calcula distância Haversine
        $theta = $longitude - (float)$lon;
        $dist = sin(deg2rad($latitude)) * sin(deg2rad((float)$lat)) +
                cos(deg2rad($latitude)) * cos(deg2rad((float)$lat)) *
                cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $km = $dist * 60 * 1.853159616;

        if ($km < $menorDistancia) {
            $menorDistancia = $km;
            $kmEncontrado = (string)$placemark->name;
        }
    }

    // Só retorna se estiver dentro do limite (se definido)
    if ($limiteKm !== null && $menorDistancia > $limiteKm) {
        return null;
    }

    return $kmEncontrado;
}