<?php

// Configurações iniciais
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/cron_error.log');

require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

$urls = [
    "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/3121/8",
    "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/4146/8",
    "https://resources.cemaden.gov.br/graficos/cemaden/hidro/resources/json/AcumuladoResource.php?est=6622&pag=8"
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
            CURLOPT_TIMEOUT => 30,
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
            $estacao = $data['estacao'];
            $codigoEstacao = $estacao['idEstacao'];

            foreach ($data['datas'] as $dataIndex => $dataItem) {
                foreach ($data['horarios'] as $horaIndex => $horario) {
                    $valor = $data['acumulados'][$dataIndex][$horaIndex] ?? null;
                    
                    if ($valor === null) continue;

                    // Formata data e hora
                    $horarioFormatado = str_replace('h', ':00', $horario);
                    $datetime = DateTime::createFromFormat(
                        'd/m/Y H:i', 
                        "$dataItem $horarioFormatado", 
                        $timezone
                    );

                    if (!$datetime) {
                        error_log("Formato de data inválido: $dataItem $horario");
                        continue;
                    }

                    // Prepara dados para inserção
                    $dados = [
                        'data_leitura' => $datetime->format('Y-m-d'),
                        'hora_leitura' => $datetime->format('H:i'),
                        'valor' => $valor,
                        'codigo_estacao' => $codigoEstacao,
                        'estacao_nome' => $estacao['nome'],
                        'cidade_nome' => $estacao['idMunicipio']['cidade'],
                        'uf_estado' => $estacao['idMunicipio']['uf'],
                        
                        // Valores padrão para campos obrigatórios
                        'offset' => 0,
                        'cota_atencao' => 50,
                        'cota_alerta' => 70,
                        'cota_transbordamento' => 100,
                        'nivel_atual' => $valor
                    ];

                    // Verifica duplicidade
                    $stmtCheck = $pdo->prepare("SELECT 1 FROM acumulados 
                                                WHERE codigo_estacao = ? 
                                                AND data_leitura = ? 
                                                AND hora_leitura = ?");
                    $stmtCheck->execute([
                        $codigoEstacao,
                        $dados['data_leitura'],
                        $dados['hora_leitura']
                    ]);

                    if (!$stmtCheck->fetchColumn()) {
                        $stmt = $pdo->prepare("INSERT INTO acumulados (
                            data_leitura, hora_leitura, valor, offset, 
                            cota_atencao, cota_alerta, cota_transbordamento,
                            nivel_atual, estacao_nome, cidade_nome, uf_estado, codigo_estacao
                        ) VALUES (
                            :data_leitura, :hora_leitura, :valor, :offset,
                            :cota_atencao, :cota_alerta, :cota_transbordamento,
                            :nivel_atual, :estacao_nome, :cidade_nome, :uf_estado, :codigo_estacao
                        )");

                        $stmt->execute($dados);
                        
                        // Verifica alertas
                        if ($valor > $dados['cota_transbordamento']) {
                            enviarAlertaTransbordamento($dados);
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

                $dados = [
                    'data_leitura' => $datetime->format('Y-m-d'),
                    'hora_leitura' => $datetime->format('H:i'),
                    'valor' => (float)$registro['valor'],
                    'codigo_estacao' => $registro['codigo'],
                    'estacao_nome' => $registro['estacao'],
                    'cidade_nome' => $registro['cidade'],
                    'uf_estado' => $registro['uf'],
                    
                    // Valores padrão
                    'offset' => 0,
                    'cota_atencao' => 50,
                    'cota_alerta' => 70,
                    'cota_transbordamento' => 100,
                    'nivel_atual' => (float)$registro['valor']
                ];

                $stmtCheck = $pdo->prepare("SELECT 1 FROM acumulados 
                                            WHERE codigo_estacao = ? 
                                            AND data_leitura = ? 
                                            AND hora_leitura = ?");
                $stmtCheck->execute([
                    $dados['codigo_estacao'],
                    $dados['data_leitura'],
                    $dados['hora_leitura']
                ]);

                if (!$stmtCheck->fetchColumn()) {
                    $stmt = $pdo->prepare("INSERT INTO acumulados (
                        data_leitura, hora_leitura, valor, offset, 
                        cota_atencao, cota_alerta, cota_transbordamento,
                        nivel_atual, estacao_nome, cidade_nome, uf_estado, codigo_estacao
                    ) VALUES (
                        :data_leitura, :hora_leitura, :valor, :offset,
                        :cota_atencao, :cota_alerta, :cota_transbordamento,
                        :nivel_atual, :estacao_nome, :cidade_nome, :uf_estado, :codigo_estacao
                    )");

                    $stmt->execute($dados);
                }
            }
        }
    }
    error_log("Processamento concluído");

} catch (PDOException $e) {
    error_log("Erro de banco: " . $e->getMessage());
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
}

function enviarAlertaTransbordamento($dados) {
    $mensagem = "
        ALERTA DE TRANSBORDAMENTO
        Estação: {$dados['estacao_nome']} ({$dados['codigo_estacao']})
        Data: {$dados['data_leitura']} {$dados['hora_leitura']}
        Valor atual: {$dados['valor']}mm
        Cota de transbordamento: {$dados['cota_transbordamento']}mm
        Local: {$dados['cidade_nome']}/{$dados['uf_estado']}
    ";
    
    sendEmail(
        "alerta@example.com", 
        "Alerta de Transbordamento - {$dados['estacao_nome']}", 
        $mensagem
    );
}