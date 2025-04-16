<?php

// Configurações iniciais
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/cron_error.log'); // Caminho corrigido

// URLs das estações
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

$urls = [
    "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/3121/96",
    "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/4146/96",
    "https://resources.cemaden.gov.br/graficos/cemaden/hidro/resources/json/AcumuladoResource.php?est=6622&pag=96"
];

date_default_timezone_set('America/Sao_Paulo');

try {
    $pdo = Database::getConnection();
    $timezone = new DateTimeZone("America/Sao_Paulo");

    foreach ($urls as $url) {
        error_log("Processando URL: $url");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30, // Timeout adicionado
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            error_log("Erro cURL: " . curl_error($ch));
            curl_close($ch);
            continue;
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if (!$data) {
            error_log("JSON inválido ou vazio: $url");
            continue;
        }

        // Primeiro formato de JSON
        if (isset($data['estacao'])) {
            $station = $data['estacao'];
            $stationId = $station['idEstacao'];
            
            // Atualiza informações da estação
            $stmt = $pdo->prepare("INSERT INTO estacoes (id_estacao, nome, cidade, uf, latitude, longitude, tipo) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?) 
                                  ON DUPLICATE KEY UPDATE 
                                    nome = VALUES(nome), 
                                    cidade = VALUES(cidade), 
                                    uf = VALUES(uf), 
                                    latitude = VALUES(latitude), 
                                    longitude = VALUES(longitude), 
                                    tipo = VALUES(tipo)");
            $stmt->execute([
                $stationId,
                $station['nome'],
                $station['idMunicipio']['cidade'],
                $station['idMunicipio']['uf'],
                $station['latitude'],
                $station['longitude'],
                $station['idTipoestacao']['descricao']
            ]);

            // Processa dados hidrológicos
            foreach ($data['datas'] as $dataIndex => $dataItem) {
                foreach ($data['horarios'] as $horaIndex => $horario) {
                    $valor = $data['acumulados'][$dataIndex][$horaIndex] ?? null;
                    
                    if ($valor === null) continue;

                    $horario = str_replace('h', ':00', $horario);
                    
                    // Assume que a data/hora original está no timezone local
                    $datetime = DateTime::createFromFormat(
                        'd/m/Y H:i', 
                        "$dataItem $horario", 
                        $timezone
                    );

                    if (!$datetime) {
                        error_log("Formato de data inválido: $dataItem $horario");
                        continue;
                    }

                    $dataInsert = $datetime->format('Y-m-d');
                    $horaInsert = $datetime->format('H:i');

                    // Verifica duplicatas
                    $stmtCheck = $pdo->prepare("SELECT 1 FROM acumulados 
                                               WHERE id_estacao = ? 
                                                 AND data = ? 
                                                 AND horario = ?");
                    $stmtCheck->execute([$stationId, $dataInsert, $horaInsert]);
                    
                    if (!$stmtCheck->fetchColumn()) {
                        $pdo->prepare("INSERT INTO acumulados (id_estacao, data, horario, valor)
                                      VALUES (?, ?, ?, ?)")
                            ->execute([$stationId, $dataInsert, $horaInsert, $valor]);
                        
                        // Verifica alertas
                        $stmtCota = $pdo->prepare("SELECT cota_maxima FROM estacoes WHERE id_estacao = ?");
                        $stmtCota->execute([$stationId]);
                        $cotaMaxima = $stmtCota->fetchColumn();

                        if ($cotaMaxima !== null && $valor > $cotaMaxima) {
                            enviarAlerta($station, $valor, $cotaMaxima);
                        }
                    }
                }
            }
        }
        // Segundo formato de JSON
        elseif (is_array($data) && isset($data[0]['codigo'])) {
            foreach ($data as $registro) {
                $datetime = new DateTime($registro['datahora'], new DateTimeZone('UTC'));
                $datetime->setTimezone($timezone);

                $stmt = $pdo->prepare("INSERT INTO estacoes (id_estacao, nome, cidade, uf) 
                                      VALUES (?, ?, ?, ?) 
                                      ON DUPLICATE KEY UPDATE 
                                        nome = VALUES(nome), 
                                        cidade = VALUES(cidade), 
                                        uf = VALUES(uf)");
                $stmt->execute([
                    $registro['codigo'],
                    $registro['estacao'],
                    $registro['cidade'],
                    $registro['uf']
                ]);

                // Insere dados hidrológicos
                $dataInsert = $datetime->format('Y-m-d');
                $horaInsert = $datetime->format('H:i');
                $valor = (float)$registro['valor'];

                $stmtCheck = $pdo->prepare("SELECT 1 FROM acumulados 
                                           WHERE id_estacao = ? 
                                             AND data = ? 
                                             AND horario = ?");
                $stmtCheck->execute([$registro['codigo'], $dataInsert, $horaInsert]);
                
                if (!$stmtCheck->fetchColumn()) {
                    $pdo->prepare("INSERT INTO acumulados (id_estacao, data, horario, valor)
                                  VALUES (?, ?, ?, ?)")
                        ->execute([$registro['codigo'], $dataInsert, $horaInsert, $valor]);
                }
            }
        } else {
            error_log("Formato desconhecido: $url");
        }
    }
    error_log("Processamento concluído");

} catch (PDOException $e) {
    error_log("Erro de banco: " . $e->getMessage());
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
}

function enviarAlerta($estacao, $valor, $cotaMaxima) {
    $mensagem = "
        Alerta: Estação {$estacao['nome']} ({$estacao['idEstacao']})
        Valor atual: $valor mm
        Cota máxima: $cotaMaxima mm
        Local: {$estacao['idMunicipio']['cidade']}/{$estacao['idMunicipio']['uf']}
    ";
    
    // Adapte a função de envio de email conforme sua implementação
    sendEmail(
        "alerta@example.com", 
        "Alerta Hidrológico - {$estacao['nome']}", 
        $mensagem
    );
}
?>