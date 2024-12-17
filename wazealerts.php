<?php

require_once __DIR__ . '/config/configbd.php';

// Função para criar a tabela alerts se não existir
function createAlertsTable(PDO $pdo) {
    $query = "
        CREATE TABLE IF NOT EXISTS alerts (
            uuid VARCHAR(255) PRIMARY KEY,
            country VARCHAR(255),
            city VARCHAR(255),
            reportRating INT,
            reportByMunicipalityUser VARCHAR(255),
            confidence INT,
            reliability INT,
            type VARCHAR(255),
            roadType INT,
            magvar INT,
            subtype VARCHAR(255),
            street VARCHAR(255),
            location_x DOUBLE,
            location_y DOUBLE,
            pubMillis BIGINT,
            status INT DEFAULT 1,
            source_url VARCHAR(255),
            date_received DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($query);
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
function saveAlertsToDb(PDO $pdo, array $alerts, $url) {
    // Configura o fuso horário para São Paulo
    date_default_timezone_set('America/Sao_Paulo');

    // Busca todos os alertas existentes no banco para a URL
    $stmt = $pdo->prepare("SELECT uuid FROM alerts WHERE source_url = ? AND status = 1");
    $stmt->execute([$url]);
    $dbUuids = $stmt->fetchAll(PDO::FETCH_COLUMN); // Lista de UUIDs no banco

    // Lista de UUIDs recebidos no JSON
    $incomingUuids = array_column($alerts, 'uuid');

    // Atualizar ou inserir alertas recebidos
    $stmtInsertUpdate = $pdo->prepare("
        INSERT INTO alerts (
            uuid, country, city, reportRating, reportByMunicipalityUser, confidence,
            reliability, type, roadType, magvar, subtype, street, location_x, location_y, pubMillis, status, source_url, date_received, date_updated
        ) VALUES (
            :uuid, :country, :city, :reportRating, :reportByMunicipalityUser, :confidence,
            :reliability, :type, :roadType, :magvar, :subtype, :street, :location_x, :location_y, :pubMillis, :status, :source_url, :date_received, :date_updated
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
            date_updated = NOW()
    ");

    foreach ($alerts as $alert) {
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
            ':source_url' => $url,
            ':date_received' => date('Y-m-d H:i:s'),
            ':date_updated' => date('Y-m-d H:i:s'),
        ]);
        echo "Alerta processado: {$alert['uuid']} da URL: {$url}" . PHP_EOL;
    }

    // Verifica cada UUID no banco de dados para desativação, um por um
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
}

// Função principal para processar os alertas
function processAlerts(array $urls) {
    $pdo = Database::getConnection();
    createAlertsTable($pdo);

    foreach ($urls as $url) {
        $jsonData = fetchAlertsFromApi($url);

        if ($jsonData && isset($jsonData['alerts'])) {
            saveAlertsToDb($pdo, $jsonData['alerts'], $url);
        } else {
            echo "Nenhum dado de alerta processado para a URL: $url" . PHP_EOL;
        }
    }
}

// Configurações iniciais
$urls = [
    "https://www.waze.com/row-partnerhub-api/partners/11682863520/waze-feeds/9bb3e551-76f2-4fc6-a32e-ad078a285f2e?format=1",
    "https://www.waze.com/row-partnerhub-api/partners/17547077845/waze-feeds/ab44a258-5e48-444c-9ca2-31cdccb3b5cb?format=1",
];

// Executa o processamento
echo "Iniciando o processo de atualização dos alertas..." . PHP_EOL;
processAlerts($urls);
echo "Processamento concluído!" . PHP_EOL;

?>
