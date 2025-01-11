<?php
// Configurações iniciais
ini_set('display_errors', 1);    // Exibir erros na tela
error_reporting(E_ALL);          // Relatar todos os tipos de erro

ini_set('log_errors', 1);
ini_set('error_log', '/cron_error.log');

// Requisitos e configurações
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

// URLs das estações
$urls = [
    "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/3121/18",
    "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/4146/18",
    "https://resources.cemaden.gov.br/graficos/cemaden/hidro/resources/json/AcumuladoResource.php?est=6622&pag=18"
];

// Define o timezone
date_default_timezone_set('America/Sao_Paulo');

try {
    // Conexão com o banco de dados usando PDO
    $pdo = Database::getConnection();

    // Timezone para conversões de data/hora
    $timezone = new DateTimeZone("America/Sao_Paulo");

    foreach ($urls as $url) {
        error_log("Processando URL: $url");

        // Inicializa o cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            error_log("Erro ao acessar a URL $url: " . curl_error($ch));
            curl_close($ch);
            continue;
        }

        curl_close($ch);

        // Decodifica o JSON em um array associativo
        $data = json_decode($response, true);

        if ($data === null) {
            error_log("Erro ao decodificar o JSON da URL $url.");
            continue;
        }

        // Processamento do JSON
        if (isset($data['estacao'])) {
            // Primeiro formato de JSON
            processarEstacao($pdo, $timezone, $data);
        } elseif (is_array($data) && isset($data[0]['codigo'])) {
            // Segundo formato de JSON
            foreach ($data as $record) {
                processarRegistro($pdo, $timezone, $record);
            }
        } else {
            error_log("Formato de JSON desconhecido para a URL $url");
        }
    }

    error_log("Processamento concluído com sucesso.");

} catch (PDOException $e) {
    error_log("Erro ao conectar ou salvar no banco de dados: " . $e->getMessage());
}

// Função para processar dados do primeiro formato de JSON
function processarEstacao($pdo, $timezone, $data) {
    $stationId = $data['estacao']['idEstacao'];
    $stationName = $data['estacao']['nome'];
    $city = $data['estacao']['idMunicipio']['cidade'];
    $state = $data['estacao']['idMunicipio']['uf'];
    $latitude = $data['estacao']['latitude'];
    $longitude = $data['estacao']['longitude'];
    $type = $data['estacao']['idTipoestacao']['descricao'];

    // Consulta o valor de cota_maxima
    $stmtCota = $pdo->prepare("SELECT cota_maxima FROM estacoes WHERE id_estacao = ?");
    $stmtCota->execute([$stationId]);
    $cotaMaxima = $stmtCota->fetchColumn() ?: null;

    // Insere ou atualiza os dados da estação
    $stmt = $pdo->prepare("
        INSERT INTO estacoes (id_estacao, nome, cidade, uf, latitude, longitude, tipo) 
        VALUES (?, ?, ?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
            nome = VALUES(nome), cidade = VALUES(cidade), uf = VALUES(uf),
            latitude = VALUES(latitude), longitude = VALUES(longitude), tipo = VALUES(tipo)
    ");
    $stmt->execute([$stationId, $stationName, $city, $state, $latitude, $longitude, $type]);

    // Processa acumulados
    foreach ($data['datas'] as $dataIndex => $dataItem) {
        foreach ($data['horarios'] as $horaIndex => $horario) {
            $valor = $data['acumulados'][$dataIndex][$horaIndex] ?? null;

            if ($valor !== null) {
                inserirAcumulado($pdo, $timezone, $stationId, $stationName, $dataItem, $horario, $valor, $cotaMaxima);
            }
        }
    }
}

// Função para processar dados do segundo formato de JSON
function processarRegistro($pdo, $timezone, $record) {
    $stationId = $record['codigo'];
    $stationName = $record['estacao'];
    $city = $record['cidade'];
    $state = $record['uf'];
    $dataHora = $record['datahora'];
    $valor = $record['valor'] !== null ? (float)$record['valor'] : null;

    if ($valor !== null) {
        // Conversão de data/hora
        $utcDatetime = new DateTime($dataHora, new DateTimeZone('UTC'));
        $utcDatetime->setTimezone($timezone);
        $dataItem = $utcDatetime->format('Y-m-d');
        $horario = $utcDatetime->format('H:i');

        // Consulta e insere dados
        $stmtCota = $pdo->prepare("SELECT cota_maxima FROM estacoes WHERE id_estacao = ?");
        $stmtCota->execute([$stationId]);
        $cotaMaxima = $stmtCota->fetchColumn() ?: null;

        $stmt = $pdo->prepare("
            INSERT INTO estacoes (id_estacao, nome, cidade, uf) 
            VALUES (?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                nome = VALUES(nome), cidade = VALUES(cidade), uf = VALUES(uf)
        ");
        $stmt->execute([$stationId, $stationName, $city, $state]);

        inserirAcumulado($pdo, $timezone, $stationId, $stationName, $dataItem, $horario, $valor, $cotaMaxima);
    }
}

// Função para inserir acumulados
function inserirAcumulado($pdo, $timezone, $stationId, $stationName, $dataItem, $horario, $valor, $cotaMaxima) {
    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) FROM acumulados WHERE id_estacao = ? AND data = ? AND horario = ?
    ");
    $stmtCheck->execute([$stationId, $dataItem, $horario]);
    $exists = $stmtCheck->fetchColumn() > 0;

    if (!$exists) {
        $stmt = $pdo->prepare("
            INSERT INTO acumulados (id_estacao, data, horario, valor) 
            VALUES (?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE valor = VALUES(valor)
        ");
        $stmt->execute([$stationId, $dataItem, $horario, $valor]);

        if ($cotaMaxima !== null && $valor > $cotaMaxima) {
            error_log("Alerta: Valor acumulado ($valor) excedeu a cota máxima ($cotaMaxima) para a estação $stationName");
            $message = "Alerta: A estação $stationName excedeu a cota máxima definida.\n\nValor acumulado: $valor\nCota máxima: $cotaMaxima\n\nPor favor, tome as devidas providências.";
            sendEmail("andresoaresdiniz201218@gmail.com", $message);
        }
    }
}
?>
