<?php
// Configurações iniciais
ini_set('display_errors', 1);    // Exibir erros na tela
error_reporting(E_ALL);          // Relatar todos os tipos de erro

ini_set('log_errors', 1);
ini_set('error_log', '/cron_error.log');

// URLs das estações
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

sendEmail('andresoaresdiniz201218@gmail.com', 'Testando envio de e-mail.');


$urls = [
    "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/3121/1",
    "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/4146/1",
    "https://resources.cemaden.gov.br/graficos/cemaden/hidro/resources/json/AcumuladoResource.php?est=6622&pag=1"
];


date_default_timezone_set('America/Sao_Paulo');

try {
    // Conexão com o banco de dados usando PDO
    $pdo = Database::getConnection();

    // Define o timezone para São Paulo
    $timezone = new DateTimeZone("America/Sao_Paulo");

    foreach ($urls as $url) {
        error_log("Processando URL: $url");

        // Inicializa o cURL
        $ch = curl_init();

        // Configurações do cURL
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Executa a requisição e obtém a resposta
        $response = curl_exec($ch);

        // Verifica se ocorreu algum erro
        if (curl_errno($ch)) {
            error_log("Erro ao acessar a URL $url: " . curl_error($ch));
            continue;
        }

        // Fecha a conexão cURL
        curl_close($ch);

        // Decodifica o JSON em um array associativo
        $data = json_decode($response, true);

        // Verifica se a decodificação foi bem-sucedida
        if ($data === NULL) {
            error_log("Erro ao decodificar o JSON da URL $url.");
            continue;
        }

        // Identifica o formato do JSON
        if (isset($data['estacao'])) {
            // Primeiro formato de JSON
            $stationId = $data['estacao']['idEstacao'];
            $stationName = $data['estacao']['nome'];
            $city = $data['estacao']['idMunicipio']['cidade'];
            $state = $data['estacao']['idMunicipio']['uf'];
            $latitude = $data['estacao']['latitude'];
            $longitude = $data['estacao']['longitude'];
            $type = $data['estacao']['idTipoestacao']['descricao'];

            // Consulta o valor de cota_maxima da estação
            $stmtCota = $pdo->prepare("SELECT cota_maxima FROM estacoes WHERE id_estacao = ?");
            $stmtCota->execute([$stationId]);
            $cotaMaxima = $stmtCota->fetchColumn();

            if ($cotaMaxima === false) {
                error_log("Aviso: cota_maxima não definida para a estação: $stationName");
                $cotaMaxima = null;
            }

            // Insere os dados da estação
            $stmt = $pdo->prepare("INSERT INTO estacoes (id_estacao, nome, cidade, uf, latitude, longitude, tipo) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nome = VALUES(nome), cidade = VALUES(cidade), uf = VALUES(uf), latitude = VALUES(latitude), longitude = VALUES(longitude), tipo = VALUES(tipo)");
            $stmt->execute([
                $stationId,
                $stationName,
                $city,
                $state,
                $latitude,
                $longitude,
                $type
            ]);

            error_log("Dados da estação inseridos/atualizados: $stationName");

            // Processa os horários e acumulados
            // Processa os horários e acumulados
            foreach ($data['datas'] as $dataIndex => $dataItem) {
                foreach ($data['horarios'] as $horaIndex => $horario) {
                    $valor = $data['acumulados'][$dataIndex][$horaIndex] ?? null;
            
                    // Ignora registros com valor nulo
                    if ($valor === null) {
                        continue;
                    }
            
                    // Remove o sufixo "h" e adiciona ":00" para formar o horário completo
                    $horario = str_replace('h', ':00', $horario);
            
                    // Converte data e horário para o timezone de São Paulo
                    $utcDatetime = DateTime::createFromFormat('d/m/Y H:i', "$dataItem $horario", new DateTimeZone('UTC'));
                    if ($utcDatetime) {
                        $utcDatetime->setTimezone($timezone); // Ajusta para o timezone de São Paulo
                        $dataItem = $utcDatetime->format('Y-m-d'); // Converte a data para o formato ISO
                        $horario = $utcDatetime->format('H:i');   // Converte o horário para o formato 24 horas
                    } else {
                        error_log("Erro ao processar data/horário: $dataItem $horario");
                        continue;
                    }
            
                    // Verifica se já existe um registro para esta estação, data e horário
                    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM acumulados WHERE id_estacao = ? AND data = ? AND horario = ?");
                    $stmtCheck->execute([
                        $stationId,
                        $dataItem,
                        $horario
                    ]);
                    $exists = $stmtCheck->fetchColumn() > 0;
            
                    if (!$exists) {
                        $stmt = $pdo->prepare("INSERT INTO acumulados (id_estacao, data, horario, valor) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
                        $stmt->execute([
                            $stationId,
                            $dataItem,
                            $horario,
                            $valor
                        ]);
                        error_log("Registro inserido: Estação $stationName, Data: $dataItem, Horário: $horario, Valor: $valor");
            
                        // Verifica se o valor excede a cota_maxima
                        if ($cotaMaxima !== null && $valor > $cotaMaxima) {
                            error_log("Alerta: Valor acumulado ($valor) excedeu a cota máxima ($cotaMaxima) para a estação $stationName");
                            $message = "
                            Alerta: A estação $stationName excedeu a cota máxima definida.\n\n
                            Valor acumulado: $valor\n
                            Cota máxima: $cotaMaxima\n\n
                            Por favor, tome as devidas providências.
                            ";
                            sendEmail("andresoaresdiniz201218@gmail.com", $message)
                        }
                    } else {
                        error_log("Registro já existe: Estação $stationName, Data: $dataItem, Horário: $horario");
                    }
                }
            }


        } elseif (is_array($data) && isset($data[0]['codigo'])) {
            // Segundo formato de JSON
            foreach ($data as $record) {
                $stationId = $record['codigo'];
                $stationName = $record['estacao'];
                $city = $record['cidade'];
                $state = $record['uf'];
                $dataHora = $record['datahora'];
                $valor = $record['valor'] !== null ? (float)$record['valor'] : null;

                // Ignora registros com valor nulo
                if ($valor === null) {
                    continue;
                }

                // Converte data e horário para o timezone de São Paulo
                $utcDatetime = new DateTime($dataHora, new DateTimeZone('UTC'));
                $utcDatetime->setTimezone($timezone);
                $dataItem = $utcDatetime->format('Y-m-d');
                $horario = $utcDatetime->format('H:i');

                // Consulta o valor de cota_maxima da estação
                $stmtCota = $pdo->prepare("SELECT cota_maxima FROM estacoes WHERE id_estacao = ?");
                $stmtCota->execute([$stationId]);
                $cotaMaxima = $stmtCota->fetchColumn();

                if ($cotaMaxima === false) {
                    error_log("Aviso: cota_maxima não definida para a estação: $stationName");
                    $cotaMaxima = null;
                }

                // Insere os dados da estação
                $stmt = $pdo->prepare("INSERT INTO estacoes (id_estacao, nome, cidade, uf) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE nome = VALUES(nome), cidade = VALUES(cidade), uf = VALUES(uf)");
                $stmt->execute([
                    $stationId,
                    $stationName,
                    $city,
                    $state
                ]);

                error_log("Dados da estação inseridos/atualizados: $stationName");

                // Verifica se já existe um registro para esta estação, data e horário
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM acumulados WHERE id_estacao = ? AND data = ? AND horario = ?");
                $stmtCheck->execute([
                    $stationId,
                    $dataItem,
                    $horario
                ]);
                $exists = $stmtCheck->fetchColumn() > 0;

                if (!$exists) {
                    $stmt = $pdo->prepare("INSERT INTO acumulados (id_estacao, data, horario, valor) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
                    $stmt->execute([
                        $stationId,
                        $dataItem,
                        $horario,
                        $valor
                    ]);
                    error_log("Registro inserido: Estação $stationName, Data: $dataItem, Horário: $horario, Valor: $valor");

                    // Verifica se o valor excede a cota_maxima
                    if ($cotaMaxima !== null && $valor > $cotaMaxima) {
                        error_log("Alerta: Valor acumulado ($valor) excedeu a cota máxima ($cotaMaxima) para a estação $stationName");
                    }
                } else {
                    error_log("Registro já existe: Estação $stationName, Data: $dataItem, Horário: $horario");
                }
            }
        } else {
            error_log("Formato de JSON desconhecido para a URL $url");
        }
    }

    error_log("Processamento concluído com sucesso.");

} catch (PDOException $e) {
    error_log("Erro ao conectar ou salvar no banco de dados: " . $e->getMessage());
}
?>
