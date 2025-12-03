<?php
/**
 * Sistema de Coleta e Processamento de Dados Hidrológicos CEMADEN
 * 
 * @version 2.0
 * @author Sistema de Monitoramento
 */

// Configurações iniciais
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/cron_error.log');

require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';
require_once __DIR__ . '/vendor/autoload.php'; // Para Monolog se usar Composer

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

// ============================================================================
// CONSTANTES DE CONFIGURAÇÃO
// ============================================================================

define('TIMEZONE', 'America/Sao_Paulo');
define('CURL_TIMEOUT', 30);
define('CURL_CONNECT_TIMEOUT', 10);
define('MAX_RETRIES', 3);
define('RETRY_DELAY', 2); // segundos
define('BATCH_SIZE', 100);

// Cotas padrão (caso não existam na base)
define('DEFAULT_COTA_ATENCAO', 50);
define('DEFAULT_COTA_ALERTA', 70);
define('DEFAULT_COTA_TRANSBORDAMENTO', 100);
define('DEFAULT_OFFSET', 0);

// ============================================================================
// CLASSE DE CONFIGURAÇÃO
// ============================================================================

class Config {
    private static $urls = [
        "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/3121/8",
        "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/4146/8",
        "https://resources.cemaden.gov.br/graficos/cemaden/hidro/resources/json/AcumuladoResource.php?est=6622&pag=8"
    ];

    private static $alertRecipients = [
        'default' => ['alerta@example.com'],
        'critical' => ['alerta@example.com', 'emergencia@example.com']
    ];

    public static function getUrls(): array {
        return self::$urls;
    }

    public static function getAlertRecipients(string $level = 'default'): array {
        return self::$alertRecipients[$level] ?? self::$alertRecipients['default'];
    }
}

// ============================================================================
// CLASSE DE LOGGER
// ============================================================================

class AppLogger {
    private static $logger;

    public static function getInstance(): Logger {
        if (self::$logger === null) {
            self::$logger = new Logger('cemaden');
            
            // Log para arquivo rotativo
            self::$logger->pushHandler(
                new RotatingFileHandler(__DIR__ . '/logs/app.log', 30, Logger::INFO)
            );
            
            // Log de erros separado
            self::$logger->pushHandler(
                new StreamHandler(__DIR__ . '/logs/errors.log', Logger::ERROR)
            );
        }
        
        return self::$logger;
    }
}

// ============================================================================
// CLASSE CLIENTE API CEMADEN
// ============================================================================

class CemadenAPIClient {
    private $logger;
    private $maxRetries;
    private $retryDelay;

    public function __construct(int $maxRetries = MAX_RETRIES, int $retryDelay = RETRY_DELAY) {
        $this->logger = AppLogger::getInstance();
        $this->maxRetries = $maxRetries;
        $this->retryDelay = $retryDelay;
    }

    /**
     * Busca dados de uma URL com retry automático
     */
    public function fetchData(string $url): ?array {
        $attempts = 0;
        
        while ($attempts < $this->maxRetries) {
            try {
                $this->logger->info("Tentativa " . ($attempts + 1) . " - Buscando: $url");
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => CURL_TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
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

                $this->logger->info("Dados obtidos com sucesso de: $url");
                return $data;

            } catch (Exception $e) {
                $attempts++;
                $this->logger->warning("Falha na tentativa $attempts: " . $e->getMessage());
                
                if ($attempts < $this->maxRetries) {
                    sleep($this->retryDelay);
                } else {
                    $this->logger->error("Falha após $attempts tentativas: $url");
                    return null;
                }
            }
        }
        
        return null;
    }
}

// ============================================================================
// CLASSE DE VALIDAÇÃO
// ============================================================================

class DataValidator {
    /**
     * Valida estrutura do primeiro formato de JSON
     */
    public static function validateFormat1(array $data): bool {
        return isset($data['estacao'], $data['datas'], $data['horarios'], $data['acumulados']) &&
               isset($data['estacao']['idEstacao'], $data['estacao']['nome']);
    }

    /**
     * Valida estrutura do segundo formato de JSON
     */
    public static function validateFormat2(array $data): bool {
        return is_array($data) && 
               isset($data[0]['codigo'], $data[0]['datahora'], $data[0]['valor']);
    }

    /**
     * Valida valor numérico dentro de limites razoáveis
     */
    public static function validateValue(float $value): bool {
        return $value >= 0 && $value <= 10000; // mm de chuva
    }

    /**
     * Sanitiza string
     */
    public static function sanitizeString(?string $value): string {
        return trim(strip_tags($value ?? ''));
    }
}

// ============================================================================
// CLASSE REPOSITORY (Acesso ao Banco)
// ============================================================================

class CemadenRepository {
    private $pdo;
    private $logger;
    private $checkStmt;
    private $insertStmt;
    private $cotasCache = [];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->logger = AppLogger::getInstance();
        $this->prepareStatements();
    }

    private function prepareStatements(): void {
        // Prepared statement para verificação de duplicidade
        $this->checkStmt = $this->pdo->prepare(
            "SELECT 1 FROM leituras_cemaden 
             WHERE codigo_estacao = ? 
             AND data_leitura = ? 
             AND hora_leitura = ?"
        );

        // Prepared statement para inserção
        $this->insertStmt = $this->pdo->prepare(
            "INSERT INTO leituras_cemaden (
                data_leitura, hora_leitura, valor, offset, 
                cota_atencao, cota_alerta, cota_transbordamento,
                nivel_atual, estacao_nome, cidade_nome, uf_estado, codigo_estacao
            ) VALUES (
                :data_leitura, :hora_leitura, :valor, :offset,
                :cota_atencao, :cota_alerta, :cota_transbordamento,
                :nivel_atual, :estacao_nome, :cidade_nome, :uf_estado, :codigo_estacao
            )"
        );
    }

    /**
     * Busca cotas específicas da estação (cache em memória)
     */
    public function getCotasEstacao(string $codigoEstacao): array {
        if (isset($this->cotasCache[$codigoEstacao])) {
            return $this->cotasCache[$codigoEstacao];
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT cota_atencao, cota_alerta, cota_transbordamento, offset 
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
            $this->logger->warning("Erro ao buscar cotas da estação $codigoEstacao: " . $e->getMessage());
        }

        // Retorna valores padrão
        $default = [
            'cota_atencao' => DEFAULT_COTA_ATENCAO,
            'cota_alerta' => DEFAULT_COTA_ALERTA,
            'cota_transbordamento' => DEFAULT_COTA_TRANSBORDAMENTO,
            'offset' => DEFAULT_OFFSET
        ];
        
        $this->cotasCache[$codigoEstacao] = $default;
        return $default;
    }

    /**
     * Verifica se registro já existe
     */
    public function exists(string $codigoEstacao, string $dataLeitura, string $horaLeitura): bool {
        $this->checkStmt->execute([$codigoEstacao, $dataLeitura, $horaLeitura]);
        return (bool) $this->checkStmt->fetchColumn();
    }

    /**
     * Insere registros em lote
     */
    public function insertBatch(array $registros): int {
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
            $this->logger->info("Inseridos $inserted registros em lote");
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->logger->error("Erro no batch insert: " . $e->getMessage());
            throw $e;
        }

        return $inserted;
    }

    /**
     * Registra execução do script
     */
    public function logExecution(string $status, array $stats): void {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO execucoes_log (data_execucao, status, registros_processados, 
                 registros_inseridos, tempo_execucao, erros) 
                 VALUES (NOW(), :status, :processados, :inseridos, :tempo, :erros)"
            );
            
            $stmt->execute([
                'status' => $status,
                'processados' => $stats['processados'] ?? 0,
                'inseridos' => $stats['inseridos'] ?? 0,
                'tempo' => $stats['tempo'] ?? 0,
                'erros' => json_encode($stats['erros'] ?? [])
            ]);
        } catch (PDOException $e) {
            $this->logger->error("Erro ao registrar execução: " . $e->getMessage());
        }
    }
}

// ============================================================================
// CLASSE PROCESSADOR DE DADOS
// ============================================================================

class DataProcessor {
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

    public function __construct(CemadenRepository $repository, AlertService $alertService) {
        $this->timezone = new DateTimeZone(TIMEZONE);
        $this->logger = AppLogger::getInstance();
        $this->repository = $repository;
        $this->alertService = $alertService;
    }

    /**
     * Processa dados no formato 1
     */
    public function processFormat1(array $data): void {
        if (!DataValidator::validateFormat1($data)) {
            throw new Exception("Estrutura de dados inválida - Formato 1");
        }

        $estacao = $data['estacao'];
        $codigoEstacao = DataValidator::sanitizeString($estacao['idEstacao']);
        $cotas = $this->repository->getCotasEstacao($codigoEstacao);

        foreach ($data['datas'] as $dataIndex => $dataItem) {
            foreach ($data['horarios'] as $horaIndex => $horario) {
                $valor = $data['acumulados'][$dataIndex][$horaIndex] ?? null;
                
                if ($valor === null || !DataValidator::validateValue((float)$valor)) {
                    continue;
                }

                try {
                    $datetime = $this->parseDateTime($dataItem, $horario);
                    
                    $registro = $this->prepareRecord(
                        $datetime,
                        (float)$valor,
                        $codigoEstacao,
                        DataValidator::sanitizeString($estacao['nome']),
                        DataValidator::sanitizeString($estacao['idMunicipio']['cidade']),
                        DataValidator::sanitizeString($estacao['idMunicipio']['uf']),
                        $cotas
                    );

                    $this->addToBatch($registro);
                    $this->stats['processados']++;

                } catch (Exception $e) {
                    $this->stats['erros'][] = $e->getMessage();
                    $this->logger->warning("Erro ao processar registro: " . $e->getMessage());
                }
            }
        }

        $this->flushBatch();
    }

    /**
     * Processa dados no formato 2
     */
    public function processFormat2(array $data): void {
        if (!DataValidator::validateFormat2($data)) {
            throw new Exception("Estrutura de dados inválida - Formato 2");
        }

        foreach ($data as $registro) {
            try {
                $datetime = new DateTime($registro['datahora'], new DateTimeZone('UTC'));
                $datetime->setTimezone($this->timezone);

                $codigoEstacao = DataValidator::sanitizeString($registro['codigo']);
                $cotas = $this->repository->getCotasEstacao($codigoEstacao);
                $valor = (float)$registro['valor'];

                if (!DataValidator::validateValue($valor)) {
                    continue;
                }

                $record = $this->prepareRecord(
                    $datetime,
                    $valor,
                    $codigoEstacao,
                    DataValidator::sanitizeString($registro['estacao']),
                    DataValidator::sanitizeString($registro['cidade']),
                    DataValidator::sanitizeString($registro['uf']),
                    $cotas
                );

                $this->addToBatch($record);
                $this->stats['processados']++;

            } catch (Exception $e) {
                $this->stats['erros'][] = $e->getMessage();
                $this->logger->warning("Erro ao processar registro: " . $e->getMessage());
            }
        }

        $this->flushBatch();
    }

    /**
     * Converte data/hora string para DateTime
     */
    private function parseDateTime(string $data, string $hora): DateTime {
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

    /**
     * Prepara registro para inserção
     */
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
            'offset' => $cotas['offset'],
            'cota_atencao' => $cotas['cota_atencao'],
            'cota_alerta' => $cotas['cota_alerta'],
            'cota_transbordamento' => $cotas['cota_transbordamento'],
            'nivel_atual' => $valor
        ];
    }

    /**
     * Adiciona registro ao buffer de lote
     */
    private function addToBatch(array $registro): void {
        // Verifica duplicidade em memória antes de adicionar
        $key = "{$registro['codigo_estacao']}|{$registro['data_leitura']}|{$registro['hora_leitura']}";
        
        if (isset($this->batchBuffer[$key])) {
            $this->stats['duplicados']++;
            return;
        }

        // Verifica no banco
        if ($this->repository->exists(
            $registro['codigo_estacao'],
            $registro['data_leitura'],
            $registro['hora_leitura']
        )) {
            $this->stats['duplicados']++;
            return;
        }

        $this->batchBuffer[$key] = $registro;

        // Flush automático ao atingir tamanho do lote
        if (count($this->batchBuffer) >= BATCH_SIZE) {
            $this->flushBatch();
        }

        // Verificar alertas
        $this->alertService->checkAlerts($registro);
    }

    /**
     * Insere lote no banco
     */
    private function flushBatch(): void {
        if (empty($this->batchBuffer)) {
            return;
        }

        try {
            $inserted = $this->repository->insertBatch(array_values($this->batchBuffer));
            $this->stats['inseridos'] += $inserted;
            $this->batchBuffer = [];
        } catch (Exception $e) {
            $this->logger->error("Erro ao inserir lote: " . $e->getMessage());
            $this->stats['erros'][] = "Batch insert failed: " . $e->getMessage();
        }
    }

    /**
     * Retorna estatísticas do processamento
     */
    public function getStats(): array {
        return $this->stats;
    }
}

// ============================================================================
// CLASSE DE SERVIÇO DE ALERTAS
// ============================================================================

class AlertService {
    private $logger;
    private $sentAlerts = [];

    public function __construct() {
        $this->logger = AppLogger::getInstance();
    }

    /**
     * Verifica e envia alertas se necessário
     */
    public function checkAlerts(array $dados): void {
        $valor = $dados['valor'];
        $codigoEstacao = $dados['codigo_estacao'];
        
        // Evita enviar múltiplos alertas para mesma estação
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

    /**
     * Envia alerta por email
     */
    private function sendAlert(string $tipo, array $dados): void {
        $tipoMaiusculo = strtoupper($tipo);
        $cotaAtual = $dados["cota_$tipo"] ?? $dados['cota_transbordamento'];
        
        $mensagem = "
═══════════════════════════════════════════════
ALERTA DE $tipoMaiusculo - Sistema CEMADEN
═══════════════════════════════════════════════

Estação: {$dados['estacao_nome']} (Cód: {$dados['codigo_estacao']})
Data/Hora: {$dados['data_leitura']} às {$dados['hora_leitura']}
Localização: {$dados['cidade_nome']}/{$dados['uf_estado']}

Valor Atual: {$dados['valor']} mm
Cota de $tipoMaiusculo: $cotaAtual mm
Diferença: " . ($dados['valor'] - $cotaAtual) . " mm acima

Status: ATENÇÃO NECESSÁRIA

═══════════════════════════════════════════════
        ";

        $recipients = ($tipo === 'transbordamento') 
            ? Config::getAlertRecipients('critical')
            : Config::getAlertRecipients('default');

        foreach ($recipients as $email) {
            try {
                sendEmail(
                    $email,
                    "[$tipoMaiusculo] {$dados['estacao_nome']} - {$dados['cidade_nome']}/{$dados['uf_estado']}",
                    $mensagem
                );
                
                $this->logger->info("Alerta de $tipo enviado para $email - Estação: {$dados['codigo_estacao']}");
            } catch (Exception $e) {
                $this->logger->error("Erro ao enviar alerta: " . $e->getMessage());
            }
        }
    }
}

// ============================================================================
// FUNÇÃO PRINCIPAL
// ============================================================================

function main(): void {
    $logger = AppLogger::getInstance();
    $startTime = microtime(true);
    
    $logger->info("========================================");
    $logger->info("Iniciando coleta de dados CEMADEN");
    $logger->info("========================================");

    date_default_timezone_set(TIMEZONE);

    try {
        // Inicializa componentes
        $pdo = Database::getConnection();
        $repository = new CemadenRepository($pdo);
        $alertService = new AlertService();
        $processor = new DataProcessor($repository, $alertService);
        $apiClient = new CemadenAPIClient();

        $urls = Config::getUrls();
        $totalUrls = count($urls);
        $processedUrls = 0;

        foreach ($urls as $index => $url) {
            $logger->info("Processando URL " . ($index + 1) . "/$totalUrls: $url");

            $data = $apiClient->fetchData($url);

            if ($data === null) {
                $logger->warning("Falha ao obter dados de: $url");
                continue;
            }

            // Detecta formato e processa
            try {
                if (DataValidator::validateFormat1($data)) {
                    $logger->info("Formato detectado: Tipo 1 (estacao/datas/horarios)");
                    $processor->processFormat1($data);
                } elseif (DataValidator::validateFormat2($data)) {
                    $logger->info("Formato detectado: Tipo 2 (array de registros)");
                    $processor->processFormat2($data);
                } else {
                    throw new Exception("Formato de dados não reconhecido");
                }
                
                $processedUrls++;
            } catch (Exception $e) {
                $logger->error("Erro ao processar dados de $url: " . $e->getMessage());
            }
        }

        // Estatísticas finais
        $stats = $processor->getStats();
        $executionTime = round(microtime(true) - $startTime, 2);
        $stats['tempo'] = $executionTime;

        $logger->info("========================================");
        $logger->info("Processamento Concluído");
        $logger->info("URLs processadas: $processedUrls/$totalUrls");
        $logger->info("Registros processados: {$stats['processados']}");
        $logger->info("Registros inseridos: {$stats['inseridos']}");
        $logger->info("Duplicados ignorados: {$stats['duplicados']}");
        $logger->info("Erros: " . count($stats['erros']));
        $logger->info("Tempo de execução: {$executionTime}s");
        $logger->info("========================================");

        // Registra execução no banco
        $status = (count($stats['erros']) === 0) ? 'sucesso' : 'sucesso_com_erros';
        $repository->logExecution($status, $stats);

    } catch (PDOException $e) {
        $logger->error("Erro crítico de banco de dados: " . $e->getMessage());
        
        // Tenta registrar falha
        try {
            $repository->logExecution('falha', [
                'tempo' => round(microtime(true) - $startTime, 2),
                'erros' => [$e->getMessage()]
            ]);
        } catch (Exception $ex) {
            $logger->error("Não foi possível registrar falha no banco");
        }

    } catch (Exception $e) {
        $logger->error("Erro crítico: " . $e->getMessage());
    }
}

// Executa o script
main();