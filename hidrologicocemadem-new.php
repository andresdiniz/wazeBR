<?php
declare(strict_types=1);

/**
 * Script: hidrologicocemadem-new.php
 * Responsabilidade: Sistema de Coleta e Processamento de Dados Hidrológicos CEMADEN
 *
 * Pré-requisitos (fornecidos pelo wazejob.php):
 *   - $logger: Logger
 *   - $pdo: PDO
 */

if (!isset($logger) || !($logger instanceof Logger)) {
    throw new RuntimeException('Logger não disponível em hidrologicocemadem-new.php');
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('PDO não disponível em hidrologicocemadem-new.php');
}

$startTime = microtime(true);
$currentDateTime = date('Y-m-d H:i:s');

$logger->info('hidrologicocemadem iniciado', ['datetime' => $currentDateTime]);

set_time_limit(300);

// ============================================================================
// CONSTANTES
// ============================================================================

define('CEMADEN_TIMEZONE', 'America/Sao_Paulo');
define('CEMADEN_CURL_TIMEOUT', 30);
define('CEMADEN_CURL_CONNECT_TIMEOUT', 10);
define('CEMADEN_MAX_RETRIES', 3);
define('CEMADEN_RETRY_DELAY', 2);
define('CEMADEN_BATCH_SIZE', 100);

define('CEMADEN_DEFAULT_COTA_ATENCAO', 50);
define('CEMADEN_DEFAULT_COTA_ALERTA', 70);
define('CEMADEN_DEFAULT_COTA_TRANSBORDAMENTO', 100);
define('CEMADEN_DEFAULT_OFFSET', 0);

// ============================================================================
// CLASSE CONFIG
// ============================================================================

class CemadenConfig
{
    private static $urls = [
        "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/3121/96",
        "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/4146/96",
        "https://resources.cemaden.gov.br/graficos/cemaden/hidro/resources/json/AcumuladoResource.php?est=6622&pag=96"
    ];

    public static function getUrls(): array
    {
        return self::$urls;
    }
}

// ============================================================================
// CLASSE CLIENTE API
// ============================================================================

class CemadenAPIClient
{
    private $logger;
    private $maxRetries;
    private $retryDelay;

    public function __construct(Logger $logger, int $maxRetries = CEMADEN_MAX_RETRIES, int $retryDelay = CEMADEN_RETRY_DELAY)
    {
        $this->logger = $logger;
        $this->maxRetries = $maxRetries;
        $this->retryDelay = $retryDelay;
    }

    public function fetchData(string $url): ?array
    {
        $attempts = 0;

        while ($attempts < $this->maxRetries) {
            try {
                $this->logger->debug("Tentativa " . ($attempts + 1), ['url' => $url]);

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => CEMADEN_CURL_TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => CEMADEN_CURL_CONNECT_TIMEOUT,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if (curl_errno($ch)) {
                    throw new Exception("Erro cURL: " . curl_error($ch));
                }

                curl_close($ch);

                if ($httpCode !== 200) {
                    throw new Exception("HTTP Code: $httpCode");
                }

                $data = json_decode($response, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("JSON inválido: " . json_last_error_msg());
                }

                if (empty($data)) {
                    throw new Exception("Resposta vazia");
                }

                $this->logger->info('Dados obtidos com sucesso', ['url' => $url]);
                return $data;
            } catch (Exception $e) {
                $attempts++;
                $this->logger->warning("Falha na tentativa $attempts", [
                    'mensagem' => $e->getMessage()
                ]);

                if ($attempts < $this->maxRetries) {
                    sleep($this->retryDelay);
                } else {
                    $this->logger->error("Falha após $attempts tentativas", ['url' => $url]);
                    return null;
                }
            }
        }

        return null;
    }
}

// ============================================================================
// CLASSE VALIDADOR
// ============================================================================

class CemadenDataValidator
{
    public static function validateFormat1(array $data): bool
    {
        return isset($data['estacao'], $data['datas'], $data['horarios'], $data['acumulados']) &&
            isset($data['estacao']['idEstacao'], $data['estacao']['nome']);
    }

    public static function validateFormat2(array $data): bool
    {
        return is_array($data) &&
            isset($data[0]['codigo'], $data[0]['datahora'], $data[0]['valor']);
    }

    public static function validateValue(float $value): bool
    {
        return $value >= 0 && $value <= 10000;
    }

    public static function sanitizeString(?string $value): string
    {
        return trim(strip_tags($value ?? ''));
    }
}

// ============================================================================
// CLASSE REPOSITORY
// ============================================================================

class CemadenRepository
{
    private $pdo;
    private $logger;
    private $checkStmt;
    private $insertStmt;
    private $cotasCache = [];

    public function __construct(PDO $pdo, Logger $logger)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->prepareStatements();
    }

    private function prepareStatements(): void
    {
        $this->checkStmt = $this->pdo->prepare(
            "SELECT 1 FROM leituras_cemaden 
             WHERE codigo_estacao = ? 
             AND data_leitura = ? 
             AND hora_leitura = ?"
        );

        $this->insertStmt = $this->pdo->prepare(
            "INSERT INTO leituras_cemaden (
                data_leitura, hora_leitura, valor, valor_offset, 
                cota_atencao, cota_alerta, cota_transbordamento,
                nivel_atual, estacao_nome, cidade_nome, uf_estado, codigo_estacao
            ) VALUES (
                :data_leitura, :hora_leitura, :valor, :valor_offset,
                :cota_atencao, :cota_alerta, :cota_transbordamento,
                :nivel_atual, :estacao_nome, :cidade_nome, :uf_estado, :codigo_estacao
            )"
        );
    }

    public function getCotasEstacao(string $codigoEstacao): array
    {
        if (isset($this->cotasCache[$codigoEstacao])) {
            return $this->cotasCache[$codigoEstacao];
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT cota_atencao, cota_alerta, cota_transbordamento, valor_offset 
                 FROM estacoes_config 
                 WHERE codigo_estacao = ?"
            );
            $stmt->execute([$codigoEstacao]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                $this->cotasCache[$codigoEstacao] = $result;
                return $result;
            }
        } catch (PDOException $e) {
            $this->logger->warning("Erro ao buscar cotas", [
                'codigo_estacao' => $codigoEstacao,
                'mensagem' => $e->getMessage()
            ]);
        }

        $default = [
            'cota_atencao' => CEMADEN_DEFAULT_COTA_ATENCAO,
            'cota_alerta' => CEMADEN_DEFAULT_COTA_ALERTA,
            'cota_transbordamento' => CEMADEN_DEFAULT_COTA_TRANSBORDAMENTO,
            'valor_offset' => CEMADEN_DEFAULT_OFFSET
        ];

        $this->cotasCache[$codigoEstacao] = $default;
        return $default;
    }

    public function exists(string $codigoEstacao, string $dataLeitura, string $horaLeitura): bool
    {
        $this->checkStmt->execute([$codigoEstacao, $dataLeitura, $horaLeitura]);
        return (bool)$this->checkStmt->fetchColumn();
    }

    public function insertBatch(array $registros): int
    {
        if (empty($registros)) {
            return 0;
        }

        $inserted = 0;
        $this->pdo->beginTransaction();

        try {
            foreach ($registros as $dados) {
                if ($this->insertStmt->execute($dados)) {
                    $inserted++;
                }
            }

            $this->pdo->commit();
            $this->logger->info("Registros inseridos em lote", ['total' => $inserted]);
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->logger->error('Erro no batch insert', ['mensagem' => $e->getMessage()]);
            throw $e;
        }

        return $inserted;
    }
}

// ============================================================================
// CLASSE SERVIÇO DE ALERTAS
// ============================================================================

class CemadenAlertService
{
    private $logger;
    private $sentAlerts = [];

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function checkAlerts(array $dados): void
    {
        $valor = $dados['valor'];
        $codigoEstacao = $dados['codigo_estacao'];

        $alertKey = "{$codigoEstacao}|{$dados['data_leitura']}";
        if (isset($this->sentAlerts[$alertKey])) {
            return;
        }

        if ($valor >= $dados['cota_transbordamento']) {
            $this->sendAlert('transbordamento', $dados);
            $this->sentAlerts[$alertKey] = true;
        } elseif ($valor >= $dados['cota_alerta']) {
            $this->sendAlert('alerta', $dados);
            $this->sentAlerts[$alertKey] = true;
        } elseif ($valor >= $dados['cota_atencao']) {
            $this->sendAlert('atencao', $dados);
            $this->sentAlerts[$alertKey] = true;
        }
    }

    private function sendAlert(string $tipo, array $dados): void
    {
        $tipoMaiusculo = strtoupper($tipo);
        $cotaAtual = $dados["cota_$tipo"] ?? $dados['cota_transbordamento'];

        $mensagem = "Atenção!\n\n" .
            "Estação: {$dados['estacao_nome']}\n" .
            "Cidade: {$dados['cidade_nome']}/{$dados['uf_estado']}\n" .
            "Data/Hora: {$dados['data_leitura']} {$dados['hora_leitura']}\n" .
            "Nível Atual: {$dados['nivel_atual']}\n" .
            "Cota de {$tipoMaiusculo}: {$cotaAtual}\n\n" .
            "Tome as medidas necessárias!";

        if (function_exists('sendEmail')) {
            try {
                sendEmail(
                    'andresoaresdiniz201218@gmail.com',
                    $mensagem,
                    "[$tipoMaiusculo] Alerta - {$dados['estacao_nome']}"
                );

                $this->logger->info("Alerta de $tipo enviado", [
                    'estacao' => $dados['estacao_nome']
                ]);
            } catch (Exception $e) {
                $this->logger->error('Erro ao enviar alerta', [
                    'mensagem' => $e->getMessage()
                ]);
            }
        }
    }
}

// ============================================================================
// CLASSE PROCESSADOR
// ============================================================================

class CemadenDataProcessor
{
    private $timezone;
    private $logger;
    private $repository;
    private $alertService;
    private $batchBuffer = [];
    private $stats = [
        'processados' => 0,
        'inseridos' => 0,
        'duplicados' => 0,
        'erros' => []
    ];

    public function __construct(CemadenRepository $repository, CemadenAlertService $alertService, Logger $logger)
    {
        $this->timezone = new DateTimeZone(CEMADEN_TIMEZONE);
        $this->logger = $logger;
        $this->repository = $repository;
        $this->alertService = $alertService;
    }

    public function processFormat1(array $data): void
    {
        if (!CemadenDataValidator::validateFormat1($data)) {
            throw new Exception("Estrutura de dados inválida - Formato 1");
        }

        $estacao = $data['estacao'];
        $codigoEstacao = CemadenDataValidator::sanitizeString($estacao['idEstacao']);
        $cotas = $this->repository->getCotasEstacao($codigoEstacao);

        foreach ($data['datas'] as $dataIndex => $dataItem) {
            foreach ($data['horarios'] as $horaIndex => $horario) {
                $valor = $data['acumulados'][$dataIndex][$horaIndex] ?? null;

                if ($valor === null || !CemadenDataValidator::validateValue((float)$valor)) {
                    continue;
                }

                try {
                    $datetime = $this->parseDateTime($dataItem, $horario);

                    $registro = $this->prepareRecord(
                        $datetime,
                        (float)$valor,
                        $codigoEstacao,
                        CemadenDataValidator::sanitizeString($estacao['nome']),
                        CemadenDataValidator::sanitizeString($estacao['idMunicipio']['cidade']),
                        CemadenDataValidator::sanitizeString($estacao['idMunicipio']['uf']),
                        $cotas
                    );

                    $this->addToBatch($registro);
                    $this->stats['processados']++;
                } catch (Exception $e) {
                    $this->stats['erros'][] = $e->getMessage();
                    $this->logger->warning('Erro ao processar registro', [
                        'mensagem' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->flushBatch();
    }

    public function processFormat2(array $data): void
    {
        if (!CemadenDataValidator::validateFormat2($data)) {
            throw new Exception("Estrutura de dados inválida - Formato 2");
        }

        foreach ($data as $registro) {
            try {
                $datetime = new DateTime($registro['datahora'], new DateTimeZone('UTC'));
                $datetime->setTimezone($this->timezone);

                $codigoEstacao = CemadenDataValidator::sanitizeString($registro['codigo']);
                $cotas = $this->repository->getCotasEstacao($codigoEstacao);
                $valor = (float)$registro['valor'];

                if (!CemadenDataValidator::validateValue($valor)) {
                    continue;
                }

                $record = $this->prepareRecord(
                    $datetime,
                    $valor,
                    $codigoEstacao,
                    CemadenDataValidator::sanitizeString($registro['estacao']),
                    CemadenDataValidator::sanitizeString($registro['cidade']),
                    CemadenDataValidator::sanitizeString($registro['uf']),
                    $cotas
                );

                $this->addToBatch($record);
                $this->stats['processados']++;
            } catch (Exception $e) {
                $this->stats['erros'][] = $e->getMessage();
                $this->logger->warning('Erro ao processar registro', [
                    'mensagem' => $e->getMessage()
                ]);
            }
        }

        $this->flushBatch();
    }

    private function parseDateTime(string $data, string $hora): DateTime
    {
        $horarioFormatado = str_replace('h', ':00', $hora);
        $datetime = DateTime::createFromFormat(
            'd/m/Y H:i',
            "$data $horarioFormatado",
            $this->timezone
        );

        if (!$datetime) {
            throw new Exception("Formato de data inválido: $data $hora");
        }

        return $datetime;
    }

    private function prepareRecord(
        DateTime $datetime,
        float $valor,
        string $codigoEstacao,
        string $estacaoNome,
        string $cidadeNome,
        string $ufEstado,
        array $cotas
    ): array {
        return [
            'data_leitura' => $datetime->format('Y-m-d'),
            'hora_leitura' => $datetime->format('H:i'),
            'valor' => $valor,
            'codigo_estacao' => $codigoEstacao,
            'estacao_nome' => $estacaoNome,
            'cidade_nome' => $cidadeNome,
            'uf_estado' => $ufEstado,
            'valor_offset' => $cotas['valor_offset'],
            'cota_atencao' => $cotas['cota_atencao'],
            'cota_alerta' => $cotas['cota_alerta'],
            'cota_transbordamento' => $cotas['cota_transbordamento'],
            'nivel_atual' => $valor
        ];
    }

    private function addToBatch(array $registro): void
    {
        $key = "{$registro['codigo_estacao']}|{$registro['data_leitura']}|{$registro['hora_leitura']}";

        if (isset($this->batchBuffer[$key])) {
            $this->stats['duplicados']++;
            return;
        }

        if ($this->repository->exists(
            $registro['codigo_estacao'],
            $registro['data_leitura'],
            $registro['hora_leitura']
        )) {
            $this->stats['duplicados']++;
            return;
        }

        $this->batchBuffer[$key] = $registro;

        if (count($this->batchBuffer) >= CEMADEN_BATCH_SIZE) {
            $this->flushBatch();
        }

        $this->alertService->checkAlerts($registro);
    }

    private function flushBatch(): void
    {
        if (empty($this->batchBuffer)) {
            return;
        }

        try {
            $inserted = $this->repository->insertBatch(array_values($this->batchBuffer));
            $this->stats['inseridos'] += $inserted;
            $this->batchBuffer = [];
        } catch (Exception $e) {
            $this->logger->error('Erro ao inserir lote', ['mensagem' => $e->getMessage()]);
            $this->stats['erros'][] = "Batch insert failed: " . $e->getMessage();
        }
    }

    public function getStats(): array
    {
        return $this->stats;
    }
}

// ============================================================================
// FUNÇÃO PRINCIPAL
// ============================================================================

function mainHidrologico(PDO $pdo, Logger $logger): void
{
    try {
        $repository = new CemadenRepository($pdo, $logger);
        $alertService = new CemadenAlertService($logger);
        $processor = new CemadenDataProcessor($repository, $alertService, $logger);
        $apiClient = new CemadenAPIClient($logger);

        $urls = CemadenConfig::getUrls();
        $totalUrls = count($urls);
        $processedUrls = 0;

        foreach ($urls as $index => $url) {
            $logger->info("Processando URL " . ($index + 1) . "/$totalUrls", ['url' => $url]);

            $data = $apiClient->fetchData($url);

            if ($data === null) {
                $logger->warning('Falha ao obter dados', ['url' => $url]);
                continue;
            }

            try {
                if (CemadenDataValidator::validateFormat1($data)) {
                    $logger->info('Formato detectado: Tipo 1');
                    $processor->processFormat1($data);
                } elseif (CemadenDataValidator::validateFormat2($data)) {
                    $logger->info('Formato detectado: Tipo 2');
                    $processor->processFormat2($data);
                } else {
                    throw new Exception("Formato de dados não reconhecido");
                }

                $processedUrls++;
            } catch (Exception $e) {
                $logger->error('Erro ao processar dados', [
                    'url' => $url,
                    'mensagem' => $e->getMessage()
                ]);
            }
        }

        $stats = $processor->getStats();

        $logger->info('Processamento concluído', [
            'urls_processadas' => "$processedUrls/$totalUrls",
            'registros_processados' => $stats['processados'],
            'registros_inseridos' => $stats['inseridos'],
            'duplicados_ignorados' => $stats['duplicados'],
            'erros' => count($stats['erros'])
        ]);
    } catch (Exception $e) {
        $logger->error('Erro crítico', [
            'mensagem' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        throw $e;
    }
}

// Execução
mainHidrologico($pdo, $logger);

$endTime = microtime(true);
$totalTime = round($endTime - $startTime, 2);

$logger->info('hidrologicocemadem concluído', [
    'tempo_total_s' => $totalTime,
    'memoria_pico_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
]);

return true;