<?php
declare(strict_types=1);

/**
 * Script: dadoscemadem.php
 * Responsabilidade: Coletar dados da API CEMADEN (estação hidrológica)
 *
 * Pré-requisitos (fornecidos pelo wazejob.php):
 *   - $logger: Logger
 *   - $pdo: PDO
 */

if (!isset($logger) || !($logger instanceof Logger)) {
    throw new RuntimeException('Logger não disponível em dadoscemadem.php');
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('PDO não disponível em dadoscemadem.php');
}

$startTime = microtime(true);
$currentDateTime = date('Y-m-d H:i:s');

$logger->info('dadoscemadem iniciado', ['datetime' => $currentDateTime]);

set_time_limit(120);

/**
 * Obtém dados da API CEMADEN
 */
function obterDadosCemaden(string $url, Logger $logger): ?array
{
    try {
        $response = file_get_contents($url);

        if ($response === false) {
            throw new Exception("Erro ao obter dados da URL");
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Erro ao decodificar JSON: " . json_last_error_msg());
        }

        $dadosFormatados = [];
        foreach ($data as $item) {
            $nivel_atual = floatval($item['offset']) - floatval($item['valor']);

            $dadosFormatados[] = [
                'codigo' => $item['codigo'],
                'estacao' => $item['estacao'],
                'cidade' => $item['cidade'],
                'uf' => $item['uf'],
                'datahora' => $item['datahora'],
                'valor' => $item['valor'],
                'qualificacao' => $item['qualificacao'],
                'offset' => $item['offset'],
                'cota_atencao' => $item['cota_atencao'],
                'cota_alerta' => $item['cota_alerta'],
                'cota_transbordamento' => $item['cota_transbordamento'],
                'nivel_atual' => $nivel_atual
            ];
        }

        return $dadosFormatados;
    } catch (Exception $e) {
        $logger->error('Erro ao obter dados CEMADEN', [
            'url' => $url,
            'mensagem' => $e->getMessage()
        ]);
        return null;
    }
}

/**
 * Processa e salva dados CEMADEN
 */
function processCemadenData(PDO $pdo, Logger $logger): void
{
    $url = "https://resources.cemaden.gov.br/graficos/cemaden/hidro/resources/json/MedidaResource.php?est=6622&sen=20&pag=36";

    $logger->info('Buscando dados CEMADEN', ['url' => $url]);

    $dados = obterDadosCemaden($url, $logger);

    if (!$dados) {
        $logger->warning('Nenhum dado retornado da API CEMADEN');
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO leituras_cemaden 
        (data_leitura, hora_leitura, valor, `offset`, cota_atencao, cota_alerta, 
         cota_transbordamento, nivel_atual, estacao_nome, cidade_nome, uf_estado, 
         codigo_estacao, created_at)
        VALUES 
        (:data_leitura, :hora_leitura, :valor, :offset, :cota_atencao, :cota_alerta, 
         :cota_transbordamento, :nivel_atual, :estacao_nome, :cidade_nome, :uf_estado, 
         :codigo_estacao, NOW())
    ");

    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) FROM leituras_cemaden
        WHERE data_leitura = :data_leitura AND hora_leitura = :hora_leitura
    ");

    $stmtUpdateInterditar = $pdo->prepare("
        UPDATE coordenadas_interditar
        SET ativar_interditar = :ativar
        WHERE id_estacao = :id_estacao
    ");

    $totalInseridos = 0;
    $totalDuplicados = 0;
    $totalAlertas = 0;

    foreach ($dados as $item) {
        try {
            // Converte data/hora de UTC para São Paulo
            $utcDateTime = new DateTime($item['datahora'], new DateTimeZone('UTC'));
            $utcDateTime->setTimezone(new DateTimeZone('America/Sao_Paulo'));

            $data_leitura = $utcDateTime->format('Y-m-d');
            $hora_leitura = $utcDateTime->format('H:i');

            // Verifica duplicidade
            $stmtCheck->execute([
                ':data_leitura' => $data_leitura,
                ':hora_leitura' => $hora_leitura
            ]);

            if ($stmtCheck->fetchColumn() > 0) {
                $totalDuplicados++;
                continue;
            }

            // Insere dados
            $stmt->execute([
                ':data_leitura' => $data_leitura,
                ':hora_leitura' => $hora_leitura,
                ':valor' => $item['valor'],
                ':offset' => $item['offset'],
                ':cota_atencao' => $item['cota_atencao'],
                ':cota_alerta' => $item['cota_alerta'],
                ':cota_transbordamento' => $item['cota_transbordamento'],
                ':nivel_atual' => $item['nivel_atual'],
                ':estacao_nome' => $item['estacao'],
                ':cidade_nome' => $item['cidade'],
                ':uf_estado' => $item['uf'],
                ':codigo_estacao' => $item['codigo']
            ]);

            $totalInseridos++;

            $logger->debug('Dados CEMADEN inseridos', [
                'estacao' => $item['estacao'],
                'data' => $data_leitura,
                'hora' => $hora_leitura
            ]);

            // Verifica alertas
            $nivel_atual = $item['nivel_atual'];
            $cota_transbordamento = $item['cota_transbordamento'];

            if ($nivel_atual >= $cota_transbordamento) {
                $logger->warning('Alerta de transbordamento', [
                    'estacao' => $item['estacao'],
                    'nivel_atual' => $nivel_atual,
                    'cota_transbordamento' => $cota_transbordamento
                ]);

                // Envia alerta por email
                if (function_exists('sendEmail')) {
                    $subject = "Alerta de Cheia na Estação {$item['estacao']}";
                    $message = "Atenção!\n\n" .
                        "Estação: {$item['estacao']}\n" .
                        "Cidade: {$item['cidade']}/{$item['uf']}\n" .
                        "Data/Hora: {$data_leitura} {$hora_leitura}\n" .
                        "Nível Atual: {$nivel_atual}\n" .
                        "Cota de Alerta: {$item['cota_alerta']}\n" .
                        "Cota de Transbordamento: {$cota_transbordamento}\n\n" .
                        "Tome as medidas necessárias!";

                    sendEmail('andresoaresdiniz201218@gmail.com', $message, $subject);
                    $totalAlertas++;
                }

                // Ativa interdição
                $stmtUpdateInterditar->execute([
                    ':ativar' => 1,
                    ':id_estacao' => $item['codigo']
                ]);

                $logger->info('Interdição ativada', [
                    'estacao' => $item['estacao'],
                    'codigo' => $item['codigo']
                ]);
            } else {
                // Desativa interdição
                $stmtUpdateInterditar->execute([
                    ':ativar' => 0,
                    ':id_estacao' => $item['codigo']
                ]);
            }
        } catch (Exception $e) {
            $logger->error('Erro ao processar item CEMADEN', [
                'estacao' => $item['estacao'] ?? 'desconhecida',
                'mensagem' => $e->getMessage()
            ]);
        }
    }

    $logger->info('Processamento CEMADEN concluído', [
        'total_inseridos' => $totalInseridos,
        'total_duplicados' => $totalDuplicados,
        'total_alertas' => $totalAlertas
    ]);
}

// Execução principal
processCemadenData($pdo, $logger);

$endTime = microtime(true);
$totalTime = round($endTime - $startTime, 2);

$logger->info('dadoscemadem concluído', [
    'tempo_total_s' => $totalTime,
    'memoria_pico_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
]);

return true;