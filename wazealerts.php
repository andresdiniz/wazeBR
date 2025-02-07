<?php

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/debug.log');

// Verifica se o arquivo .env existe no caminho especificado
$envPath = __DIR__ . '/.env';

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

if (!file_exists($envPath)) {
    die("Arquivo .env não encontrado no caminho: $envPath");
}

try {
    // Certifique-se de que o caminho do .env está correto
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    error_log("Erro ao carregar o .env: " . $e->getMessage());
    die("Erro ao carregar o .env: " . $e->getMessage());
}

// Configura o ambiente de debug com base na variável DEBUG do .env
if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] == 'true') {
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/debug.log');

    if (!is_dir(__DIR__ . '/../logs')) {
        mkdir(__DIR__ . '/../logs', 0777, true);
    }
}

set_time_limit(1200);

require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

// Função para buscar as URLs e os respectivos id_parceiro do banco de dados
function getUrlsFromDb(PDO $pdo)
{
    $stmt = $pdo->query("SELECT url, id_parceiro FROM urls");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar dados da API usando cURL
function fetchAlertsFromApi($url) {
    try {
        // Inicializa a sessão cURL
        $ch = curl_init($url);
        
        // Configurações do cURL
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);   // Retorna a resposta em vez de exibi-la
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);   // Segue redirecionamentos
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  // Desativa a verificação SSL (não recomendado em produção)
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);  // Desativa a verificação do host SSL (não recomendado em produção)

        // Executa a requisição
        $response = curl_exec($ch);

        // Verifica se houve erro na execução do cURL
        if ($response === false) {
            throw new Exception("Erro cURL: " . curl_error($ch));
        }

        // Fecha a sessão cURL
        curl_close($ch);

        // Retorna os dados decodificados em formato de array associativo
        return json_decode($response, true);
    } catch (Exception $e) {
        echo "Erro ao buscar dados da API ($url): " . $e->getMessage() . PHP_EOL;
        return null;
    }
}

// Função para salvar os alertas no banco de dados
function saveAlertsToDb(PDO $pdo, array $alerts, $url, $id_parceiro)
{
    $currentDateTime = date('Y-m-d H:i:s');

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT uuid FROM alerts WHERE source_url = ? AND status = 1");
        $stmt->execute([$url]);
        $dbUuids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $incomingUuids = array_column($alerts, 'uuid');

        $stmtInsertUpdate = $pdo->prepare("
            INSERT INTO alerts (
                uuid, country, city, reportRating, reportByMunicipalityUser, confidence,
                reliability, type, roadType, magvar, subtype, street, location_x, location_y,
                pubMillis, status, id_parceiro, source_url, date_received, date_updated, km, id_parceiro
            ) VALUES (
                :uuid, :country, :city, :reportRating, :reportByMunicipalityUser, :confidence,
                :reliability, :type, :roadType, :magvar, :subtype, :street, :location_x, :location_y,
                :pubMillis, :status, :id_parceiro, :source_url, :date_received, :date_updated, :km, :id_parceiro
            )
            ON DUPLICATE KEY UPDATE
                country = VALUES(country),
                city = VALUES(city),
                reportRating = VALUES(reportRating),
                reportByMunicipalityUser = VALUES(reportByMunicipalityUser),
                confidence = VALUES(confidence),
                reliability = VALUES(reliability),
                type = VALUES(type),
                roadType = VALUES(roadType),
                magvar = VALUES(magvar),
                subtype = VALUES(subtype),
                street = VALUES(street),
                location_x = VALUES(location_x),
                location_y = VALUES(location_y),
                pubMillis = VALUES(pubMillis),
                status = 1,
                date_updated = NOW(),
                km = VALUES(km),
                id_parceiro = VALUES(id_parceiro)
        ");

        foreach ($alerts as $alert) {
            if (!isset($alert['location']['x'], $alert['location']['y'])) {
                echo "Alerta inválido, ignorado: " . json_encode($alert) . PHP_EOL;
                continue;
            }

            $km = null;

            $stmtInsertUpdate->execute([
                ':uuid' => $alert['uuid'] ?? null,
                ':country' => $alert['country'] ?? null,
                ':city' => $alert['city'] ?? null,
                ':reportRating' => $alert['reportRating'] ?? null,
                ':reportByMunicipalityUser' => $alert['reportByMunicipalityUser'] ?? null,
                ':confidence' => $alert['confidence'] ?? null,
                ':reliability' => $alert['reliability'] ?? null,
                ':type' => $alert['type'] ?? null,
                ':roadType' => $alert['roadType'] ?? null,
                ':magvar' => $alert['magvar'] ?? null,
                ':subtype' => $alert['subtype'] ?? null,
                ':street' => $alert['street'] ?? null,
                ':location_x' => $alert['location']['x'] ?? null,
                ':location_y' => $alert['location']['y'] ?? null,
                ':pubMillis' => $alert['pubMillis'] ?? null,
                ':status' => 1,
                ':id_parceiro' => $id_parceiro,
                ':source_url' => $url,
                ':date_received' => $currentDateTime,
                ':date_updated' => $currentDateTime,
                ':km' => $km,
            ]);

            echo "Alerta processado: {$alert['uuid']} da URL: {$url}" . PHP_EOL;
        }

        $stmtDeactivate = $pdo->prepare("UPDATE alerts SET status = 0, date_updated = NOW() WHERE uuid = ? AND source_url = ?");
        foreach ($dbUuids as $uuid) {
            if (!in_array($uuid, $incomingUuids)) {
                $stmtDeactivate->execute([$uuid, $url]);
                echo "Alerta desativado: {$uuid} da URL: {$url}" . PHP_EOL;
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Função principal para processar os alertas
function processAlerts()
{
    $pdo = Database::getConnection();
    $urls = getUrlsFromDb($pdo);

    foreach ($urls as $entry) {
        $url = $entry['url'];
        $id_parceiro = $entry['id_parceiro'];

        echo "Iniciando processamento para o parceiro: $id_parceiro" . PHP_EOL;
        echo "Iniciando busca de dados da API para a URL: $url" . PHP_EOL;

        $jsonData = fetchAlertsFromApi($url);
        if (is_array($jsonData)) {
            var_dump($jsonData['alerts']);  // Exibe a chave 'alerts' se existir
        } else {
            var_dump("Não é um array");
        }

        if ($jsonData && isset($jsonData['alerts'])) {
            echo "Processando dados de alerta para a URL: $url" . PHP_EOL;
            saveAlertsToDb($pdo, $jsonData['alerts'], $url, $id_parceiro);
        } else {
            echo "Nenhum dado de alerta processado para a URL: $url" . PHP_EOL;
        }
    }
}

// Executa o processamento
echo "Iniciando o processo de atualização dos alertas..." . PHP_EOL;
processAlerts();
echo "Processamento concluído!" . PHP_EOL;

?>