<?php

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/debug.log');

// Verifica se o arquivo .env existe no caminho especificado
$envPath = __DIR__ . '/.env';  // Corrigido o caminho

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
    // Em caso de erro, logar o erro no arquivo de log
    error_log("Erro ao carregar o .env: " . $e->getMessage()); // Usando error_log para garantir que o erro seja registrado4
    logEmail("error", "Erro ao carregar o .env: " . $e->getMessage());
    die("Erro ao carregar o .env: " . $e->getMessage());
}

// Configura o ambiente de debug com base na variável DEBUG do .env
if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] == 'true') {
    // Configura as opções de log para ambiente de debug

    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/debug.log');

    // Cria o diretório de logs se não existir
    if (!is_dir(__DIR__ . '/../logs')) {
        mkdir(__DIR__ . '/../logs', 0777, true);
    }
}


set_time_limit(1200);

require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';
require_once __DIR__ . '/config/configs.php';

// Função para buscar as URLs e os respectivos id_parceiro do banco de dados
function getUrlsFromDb(PDO $pdo)
{
    $stmt = $pdo->query("SELECT url, id_parceiro FROM urls_alerts");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar dados da API usando cURL
function fetchAlertsFromApi($url)
{
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
    // Configuração do fuso horário (remova se global)
    date_default_timezone_set('America/Sao_Paulo');

    // Obter a data/hora atual uma vez
    $currentDateTime = date('Y-m-d H:i:s');

    // Inicia uma transação para consistência
    $pdo->beginTransaction();

    try {
        // Busca todos os alertas existentes no banco para a URL
        $stmt = $pdo->prepare("SELECT uuid FROM alerts WHERE source_url = ? AND status = 1");
        $stmt->execute([$url]);
        $dbUuids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Lista de UUIDs recebidos no JSON
        $incomingUuids = array_column($alerts, 'uuid');

        // Prepara a query para inserção/atualização
        $stmtInsertUpdate = $pdo->prepare("
            INSERT INTO alerts (
                uuid, country, city, reportRating, reportByMunicipalityUser, confidence,
                reliability, type, roadType, magvar, subtype, street, location_x, location_y, pubMillis, status, source_url, date_received, date_updated, km, id_parceiro
            ) VALUES (
                :uuid, :country, :city, :reportRating, :reportByMunicipalityUser, :confidence,
                :reliability, :type, :roadType, :magvar, :subtype, :street, :location_x, :location_y, :pubMillis, :status, :source_url, :date_received, :date_updated, :km, :id_parceiro
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
            // Valida campos necessários
            if (!isset($alert['location']['x'], $alert['location']['y'])) {
                echo "Alerta inválido, ignorado: " . json_encode($alert) . PHP_EOL;
                continue;
            }

            // Calcula o KM
            $km = null;
            /*try {
                $km = consultarLocalizacaoKm($alert['location']['x'], $alert['location']['y']);
            } catch (Exception $e) {
                echo "Erro ao calcular KM para alerta {$alert['uuid']}: " . $e->getMessage() . PHP_EOL;
            }*/

            // Insere ou atualiza o alerta no banco
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

        // Desativa alertas não recebidos
        $stmtDeactivate = $pdo->prepare("
            UPDATE alerts SET status = 0, date_updated = NOW()
            WHERE uuid = ? AND source_url = ?
        ");
        foreach ($dbUuids as $uuid) {
            if (!in_array($uuid, $incomingUuids)) {
                $stmtDeactivate->execute([$uuid, $url]);
                echo "Alerta desativado: {$uuid} da URL: {$url}" . PHP_EOL;
            }
        }

        // Confirma a transação
        $pdo->commit();
    } catch (Exception $e) {
        // Reverte a transação em caso de erro
        $pdo->rollBack();
        throw $e;
    }
}

function saveJamsToDb(PDO $pdo, array $jams, $url, $id_parceiro)
{
    $currentDateTime = date('Y-m-d H:i:s');

    $pdo->beginTransaction();

    try {
        // 1. Busca jams existentes para esta URL
        $stmt = $pdo->prepare("SELECT uuid FROM jams WHERE source_url = ?");
        $stmt->execute([$url]);
        $existingUuids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 2. Processa cada jam recebido
        $processedUuids = [];

        // Query para inserir/atualizar jams
        $stmtJam = $pdo->prepare("
            INSERT INTO jams (
                uuid, country, city, level, speedKMH, length, turnType, endNode, speed,
                roadType, delay, street, pubMillis, id_parceiro, source_url, status,
                date_received, date_updated
            ) VALUES (
                :uuid, :country, :city, :level, :speedKMH, :length, :turnType, :endNode, :speed,
                :roadType, :delay, :street, :pubMillis, :id_parceiro, :source_url, 1,
                :date_received, :date_updated
            )
            ON DUPLICATE KEY UPDATE
                country = VALUES(country),
                city = VALUES(city),
                level = VALUES(level),
                speedKMH = VALUES(speedKMH),
                length = VALUES(length),
                turnType = VALUES(turnType),
                endNode = VALUES(endNode),
                speed = VALUES(speed),
                roadType = VALUES(roadType),
                delay = VALUES(delay),
                street = VALUES(street),
                pubMillis = VALUES(pubMillis),
                status = 1,
                date_updated = NOW()
        ");

        // Query para limpar e inserir linhas (coordenadas)
        $stmtDeleteLines = $pdo->prepare("DELETE FROM jam_lines WHERE jam_uuid = ?");
        $stmtInsertLine = $pdo->prepare("
            INSERT INTO jam_lines (jam_uuid, sequence, x, y)
            VALUES (:jam_uuid, :sequence, :x, :y)
        ");

        // Query para limpar e inserir segmentos
        $stmtDeleteSegments = $pdo->prepare("DELETE FROM jam_segments WHERE jam_uuid = ?");
        $stmtInsertSegment = $pdo->prepare("
            INSERT INTO jam_segments (jam_uuid, fromNode, ID_segment, toNode, isForward)
            VALUES (:jam_uuid, :fromNode, :ID_segment, :toNode, :isForward)
        ");

        foreach ($jams as $jam) {
            $uuid = $jam['uuid'];
            $processedUuids[] = $uuid;

            // Insere/Atualiza o jam principal
            $stmtJam->execute([
                ':uuid' => $uuid,
                ':country' => $jam['country'] ?? null,
                ':city' => $jam['city'] ?? null,
                ':level' => $jam['level'] ?? null,
                ':speedKMH' => $jam['speedKMH'] ?? null,
                ':length' => $jam['length'] ?? null,
                ':turnType' => $jam['turnType'] ?? null,
                ':endNode' => $jam['endNode'] ?? null,
                ':speed' => $jam['speed'] ?? null,
                ':roadType' => $jam['roadType'] ?? null,
                ':delay' => $jam['delay'] ?? null,
                ':street' => $jam['street'] ?? null,
                ':pubMillis' => $jam['pubMillis'] ?? null,
                ':id_parceiro' => $id_parceiro,
                ':source_url' => $url,
                ':date_received' => $currentDateTime,
                ':date_updated' => $currentDateTime
            ]);

            // Processa linhas (coordenadas)
            if (!empty($jam['line'])) {
                // Remove linhas antigas
                $stmtDeleteLines->execute([$uuid]);

                // Insere novas linhas com sequência
                $sequence = 0;
                foreach ($jam['line'] as $point) {
                    $stmtInsertLine->execute([
                        ':jam_uuid' => $uuid,
                        ':sequence' => $sequence++,
                        ':x' => $point['x'],
                        ':y' => $point['y']
                    ]);
                }
            }

            // Processa segmentos
            if (!empty($jam['segments'])) {
                // Remove segmentos antigos
                $stmtDeleteSegments->execute([$uuid]);

                // Insere novos segmentos
                foreach ($jam['segments'] as $segment) {
                    $stmtInsertSegment->execute([
                        ':jam_uuid' => $uuid,
                        ':fromNode' => $segment['fromNode'] ?? null,
                        ':ID_segment' => $segment['ID'] ?? null,
                        ':toNode' => $segment['toNode'] ?? null,
                        ':isForward' => $segment['isForward'] ?? null
                    ]);
                }
            }
        }

        // 3. Desativa jams que não foram recebidos (mas existiam antes)
        $uuidsToDeactivate = array_diff($existingUuids, $processedUuids);
        if (!empty($uuidsToDeactivate)) {
            $placeholders = implode(',', array_fill(0, count($uuidsToDeactivate), '?'));
            $stmtDeactivate = $pdo->prepare("
                UPDATE jams 
                SET status = 0, date_updated = NOW()
                WHERE uuid IN ($placeholders) AND source_url = ?
            ");

            $params = array_merge($uuidsToDeactivate, [$url]);
            $stmtDeactivate->execute($params);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception("Erro ao salvar jams: " . $e->getMessage());
    }
}

function saveJamsToDbEmpty(PDO $pdo, $id_parceiro)
{
    $currentDateTime = date('Y-m-d H:i:s');

    $pdo->beginTransaction();

    try {
        // Desativa todos os jams para o parceiro
        $stmtDeactivate = $pdo->prepare("
            UPDATE jams 
            SET status = 0, date_updated = NOW()
            WHERE id_parceiro = ?
        ");
        $stmtDeactivate->execute([$id_parceiro]);

        // Confirma a transação
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception("Erro ao desativar jams: " . $e->getMessage());
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
        $jsonData = fetchAlertsFromApi($url);

        if ($jsonData) {
            try {
                // Processa Alertas
                if (!empty($jsonData['alerts'])) {
                    saveAlertsToDb($pdo, $jsonData['alerts'], $url, $id_parceiro);
                } else {
                    // Se não veio a chave 'alerts', considera como array vazio para desativar os existentes
                    echo "Desativando alertas para o parceiro: $id_parceiro" . PHP_EOL;
                    saveJamsToDbEmpty($pdo,$id_parceiro);
                }

                // Processa Jams
                if (array_key_exists('jams', $jsonData)) {
                    saveJamsToDb($pdo, $jsonData['jams'], $url, $id_parceiro);
                } else {
                    // Se não veio a chave 'jams', considera como array vazio para desativar os existentes
                    echo "Desativando alertas para o parceiro: $id_parceiro" . PHP_EOL;
                    saveJamsToDbEmpty($pdo, $id_parceiro);
                }
            } catch (Exception $e) {
                echo "Erro ao processar dados: " . $e->getMessage() . PHP_EOL;
            }
        }
    }
}
// As urls sao recuperadas do banco de dados
/* Configurações iniciais
$urls = [
    "https://www.waze.com/row-partnerhub-api/partners/11682863520/waze-feeds/9bb3e551-76f2-4fc6-a32e-ad078a285f2e?format=1",
    "https://www.waze.com/row-partnerhub-api/partners/17547077845/waze-feeds/ab44a258-5e48-444c-9ca2-31cdccb3b5cb?format=1",
];
*/
// Executa o processamento
echo "Iniciando o processo de atualização dos alertas..." . PHP_EOL;
processAlerts();
echo "Processamento concluído!" . PHP_EOL;

?>