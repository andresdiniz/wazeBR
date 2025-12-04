<?php
/**
 * Sistema de Health Check - CEMADEN
 * Monitora a saúde do sistema e alerta sobre problemas
 */

require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/classes/Logger.php';
require_once __DIR__ . '/functions/scripts.php';

class HealthCheckService {
    private $pdo;
    private $logger;
    private $checks = [];
    private $status = 'healthy';
    
    // Thresholds configuráveis
    const MAX_MINUTES_WITHOUT_EXECUTION = 120; // 2 horas
    const MAX_FAILED_EXECUTIONS = 5;
    const MIN_DISK_SPACE_PERCENT = 10;
    const MAX_RESPONSE_TIME_MS = 5000;
    
    public function __construct(PDO $pdo, Logger $logger) {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }
    
    /**
     * Executa todos os health checks
     */
    public function runAllChecks(): array {
        $this->logger->info("Iniciando Health Check completo");
        
        $this->checkDatabase();
        $this->checkLastExecution();
        $this->checkRecentErrors();
        $this->checkStationsReporting();
        $this->checkDiskSpace();
        $this->checkLogFiles();
        $this->checkDatabaseSize();
        $this->checkAPIConnectivity();
        
        return $this->getResults();
    }
    
    /**
     * Check 1: Conectividade com banco de dados
     */
    private function checkDatabase(): void {
        $startTime = microtime(true);
        
        try {
            $stmt = $this->pdo->query("SELECT 1");
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($responseTime > 1000) {
                $this->addCheck('database', 'warning', 
                    "Banco de dados respondendo lentamente ({$responseTime}ms)",
                    ['response_time_ms' => $responseTime]
                );
            } else {
                $this->addCheck('database', 'ok', 
                    "Banco de dados OK ({$responseTime}ms)",
                    ['response_time_ms' => $responseTime]
                );
            }
            
        } catch (PDOException $e) {
            $this->addCheck('database', 'critical', 
                "Erro de conexão com banco de dados: " . $e->getMessage()
            );
            $this->status = 'unhealthy';
        }
    }
    
    /**
     * Check 2: Última execução do cron
     */
    private function checkLastExecution(): void {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    data_execucao,
                    status,
                    TIMESTAMPDIFF(MINUTE, data_execucao, NOW()) as minutos_atras
                FROM execucoes_log 
                ORDER BY data_execucao DESC 
                LIMIT 1
            ");
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                $this->addCheck('cron', 'critical', 
                    "Nenhuma execução registrada no banco"
                );
                $this->status = 'unhealthy';
                return;
            }
            
            $minutosAtras = (int)$result['minutos_atras'];
            
            if ($minutosAtras > self::MAX_MINUTES_WITHOUT_EXECUTION) {
                $this->addCheck('cron', 'critical', 
                    "Última execução há {$minutosAtras} minutos (limite: " . self::MAX_MINUTES_WITHOUT_EXECUTION . ")",
                    ['last_execution' => $result['data_execucao'], 'minutes_ago' => $minutosAtras]
                );
                $this->status = 'unhealthy';
            } elseif ($minutosAtras > 70) {
                $this->addCheck('cron', 'warning', 
                    "Última execução há {$minutosAtras} minutos",
                    ['last_execution' => $result['data_execucao'], 'minutes_ago' => $minutosAtras]
                );
                if ($this->status === 'healthy') {
                    $this->status = 'degraded';
                }
            } else {
                $this->addCheck('cron', 'ok', 
                    "Última execução há {$minutosAtras} minutos - Status: {$result['status']}",
                    ['last_execution' => $result['data_execucao'], 'minutes_ago' => $minutosAtras]
                );
            }
            
        } catch (PDOException $e) {
            $this->addCheck('cron', 'warning', 
                "Erro ao verificar última execução: " . $e->getMessage()
            );
        }
    }
    
    /**
     * Check 3: Erros recentes
     */
    private function checkRecentErrors(): void {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_falhas,
                    MAX(data_execucao) as ultima_falha
                FROM execucoes_log
                WHERE status = 'falha'
                AND data_execucao >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalFalhas = (int)$result['total_falhas'];
            
            if ($totalFalhas >= self::MAX_FAILED_EXECUTIONS) {
                $this->addCheck('errors', 'critical', 
                    "{$totalFalhas} falhas nas últimas 24h (limite: " . self::MAX_FAILED_EXECUTIONS . ")",
                    ['failed_executions' => $totalFalhas, 'last_failure' => $result['ultima_falha']]
                );
                $this->status = 'unhealthy';
            } elseif ($totalFalhas > 0) {
                $this->addCheck('errors', 'warning', 
                    "{$totalFalhas} falha(s) nas últimas 24h",
                    ['failed_executions' => $totalFalhas, 'last_failure' => $result['ultima_falha']]
                );
                if ($this->status === 'healthy') {
                    $this->status = 'degraded';
                }
            } else {
                $this->addCheck('errors', 'ok', 
                    "Nenhuma falha nas últimas 24h"
                );
            }
            
        } catch (PDOException $e) {
            $this->addCheck('errors', 'warning', 
                "Erro ao verificar falhas: " . $e->getMessage()
            );
        }
    }
    
    /**
     * Check 4: Estações reportando
     */
    private function checkStationsReporting(): void {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(DISTINCT codigo_estacao) as total_hoje,
                    COUNT(*) as total_leituras
                FROM leituras_cemaden
                WHERE data_leitura = CURDATE()
            ");
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $estacoesHoje = (int)$result['total_hoje'];
            $leiturasHoje = (int)$result['total_leituras'];
            
            // Verifica total esperado
            $stmtTotal = $this->pdo->query("
                SELECT COUNT(*) as total FROM estacoes_config WHERE ativo = 1
            ");
            $totalEsperado = (int)$stmtTotal->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($estacoesHoje === 0) {
                $this->addCheck('stations', 'critical', 
                    "Nenhuma estação reportou hoje",
                    ['stations_today' => 0, 'readings_today' => 0]
                );
                $this->status = 'unhealthy';
            } elseif ($totalEsperado > 0 && $estacoesHoje < $totalEsperado) {
                $percentual = round(($estacoesHoje / $totalEsperado) * 100, 1);
                $this->addCheck('stations', 'warning', 
                    "{$estacoesHoje} de {$totalEsperado} estações reportaram ({$percentual}%)",
                    ['stations_today' => $estacoesHoje, 'expected' => $totalEsperado, 'readings_today' => $leiturasHoje]
                );
                if ($this->status === 'healthy') {
                    $this->status = 'degraded';
                }
            } else {
                $this->addCheck('stations', 'ok', 
                    "{$estacoesHoje} estações reportaram hoje ({$leiturasHoje} leituras)",
                    ['stations_today' => $estacoesHoje, 'readings_today' => $leiturasHoje]
                );
            }
            
        } catch (PDOException $e) {
            $this->addCheck('stations', 'warning', 
                "Erro ao verificar estações: " . $e->getMessage()
            );
        }
    }
    
    /**
     * Check 5: Espaço em disco
     */
    private function checkDiskSpace(): void {
        try {
            $logDir = __DIR__ . '/logs';
            
            if (!is_dir($logDir)) {
                $this->addCheck('disk', 'warning', 
                    "Diretório de logs não encontrado"
                );
                return;
            }
            
            $diskFree = disk_free_space($logDir);
            $diskTotal = disk_total_space($logDir);
            $percentFree = ($diskFree / $diskTotal) * 100;
            $gbFree = round($diskFree / (1024 * 1024 * 1024), 2);
            
            if ($percentFree < self::MIN_DISK_SPACE_PERCENT) {
                $this->addCheck('disk', 'critical', 
                    "Pouco espaço em disco: {$gbFree}GB livres (" . round($percentFree, 1) . "%)",
                    ['free_gb' => $gbFree, 'free_percent' => round($percentFree, 1)]
                );
                $this->status = 'unhealthy';
            } elseif ($percentFree < 20) {
                $this->addCheck('disk', 'warning', 
                    "Espaço em disco baixo: {$gbFree}GB livres (" . round($percentFree, 1) . "%)",
                    ['free_gb' => $gbFree, 'free_percent' => round($percentFree, 1)]
                );
                if ($this->status === 'healthy') {
                    $this->status = 'degraded';
                }
            } else {
                $this->addCheck('disk', 'ok', 
                    "{$gbFree}GB livres (" . round($percentFree, 1) . "%)",
                    ['free_gb' => $gbFree, 'free_percent' => round($percentFree, 1)]
                );
            }
            
        } catch (Exception $e) {
            $this->addCheck('disk', 'warning', 
                "Erro ao verificar espaço em disco: " . $e->getMessage()
            );
        }
    }
    
    /**
     * Check 6: Arquivos de log
     */
    private function checkLogFiles(): void {
        try {
            $logDir = __DIR__ . '/logs';
            
            if (!is_dir($logDir)) {
                $this->addCheck('logs', 'warning', "Diretório de logs não existe");
                return;
            }
            
            // Verifica permissões de escrita
            if (!is_writable($logDir)) {
                $this->addCheck('logs', 'critical', 
                    "Diretório de logs sem permissão de escrita"
                );
                $this->status = 'unhealthy';
                return;
            }
            
            // Conta arquivos de log
            $logFiles = glob($logDir . '/*.log');
            $totalSize = 0;
            
            foreach ($logFiles as $file) {
                $totalSize += filesize($file);
            }
            
            $totalSizeMB = round($totalSize / (1024 * 1024), 2);
            
            $this->addCheck('logs', 'ok', 
                count($logFiles) . " arquivo(s) de log ({$totalSizeMB}MB)",
                ['log_files' => count($logFiles), 'total_size_mb' => $totalSizeMB]
            );
            
        } catch (Exception $e) {
            $this->addCheck('logs', 'warning', 
                "Erro ao verificar logs: " . $e->getMessage()
            );
        }
    }
    
    /**
     * Check 7: Tamanho do banco de dados
     */
    private function checkDatabaseSize(): void {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    table_schema as 'database',
                    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as 'size_mb'
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
                GROUP BY table_schema
            ");
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $sizeMB = $result['size_mb'] ?? 0;
            
            // Verifica crescimento
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as total FROM leituras_cemaden
            ");
            $totalRegistros = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            $this->addCheck('database_size', 'ok', 
                "Banco de dados: {$sizeMB}MB ({$totalRegistros} registros)",
                ['size_mb' => $sizeMB, 'total_records' => $totalRegistros]
            );
            
        } catch (PDOException $e) {
            $this->addCheck('database_size', 'warning', 
                "Erro ao verificar tamanho do banco: " . $e->getMessage()
            );
        }
    }
    
    /**
     * Check 8: Conectividade com APIs CEMADEN
     */
    private function checkAPIConnectivity(): void {
        $testUrl = "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/3121/8";
        $startTime = microtime(true);
        
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $testUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            curl_close($ch);
            
            if ($httpCode === 200) {
                if ($responseTime > self::MAX_RESPONSE_TIME_MS) {
                    $this->addCheck('api', 'warning', 
                        "API CEMADEN respondendo lentamente ({$responseTime}ms)",
                        ['response_time_ms' => $responseTime, 'http_code' => $httpCode]
                    );
                    if ($this->status === 'healthy') {
                        $this->status = 'degraded';
                    }
                } else {
                    $this->addCheck('api', 'ok', 
                        "API CEMADEN OK ({$responseTime}ms)",
                        ['response_time_ms' => $responseTime, 'http_code' => $httpCode]
                    );
                }
            } else {
                $this->addCheck('api', 'warning', 
                    "API CEMADEN retornou código {$httpCode}",
                    ['response_time_ms' => $responseTime, 'http_code' => $httpCode]
                );
                if ($this->status === 'healthy') {
                    $this->status = 'degraded';
                }
            }
            
        } catch (Exception $e) {
            $this->addCheck('api', 'warning', 
                "Erro ao testar API CEMADEN: " . $e->getMessage()
            );
            if ($this->status === 'healthy') {
                $this->status = 'degraded';
            }
        }
    }
    
    /**
     * Adiciona resultado de um check
     */
    private function addCheck(string $name, string $status, string $message, array $details = []): void {
        $this->checks[$name] = [
            'status' => $status,
            'message' => $message,
            'details' => $details,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Log baseado no status
        if ($status === 'critical') {
            $this->logger->error("Health Check - $name: $message", $details);
        } elseif ($status === 'warning') {
            $this->logger->warning("Health Check - $name: $message", $details);
        } else {
            $this->logger->info("Health Check - $name: $message", $details);
        }
    }
    
    /**
     * Retorna resultados do health check
     */
    public function getResults(): array {
        $summary = [
            'ok' => 0,
            'warning' => 0,
            'critical' => 0
        ];
        
        foreach ($this->checks as $check) {
            if ($check['status'] === 'ok') {
                $summary['ok']++;
            } elseif ($check['status'] === 'warning') {
                $summary['warning']++;
            } else {
                $summary['critical']++;
            }
        }
        
        return [
            'status' => $this->status,
            'timestamp' => date('Y-m-d H:i:s'),
            'summary' => $summary,
            'checks' => $this->checks
        ];
    }
    
    /**
     * Envia alerta se sistema não está saudável
     */
    public function alertIfUnhealthy(): void {
        if ($this->status === 'unhealthy') {
            $this->sendHealthAlert();
        }
    }
    
    /**
     * Envia alerta de saúde
     */
    private function sendHealthAlert(): void {
        try {
            $problemas = [];
            
            foreach ($this->checks as $name => $check) {
                if ($check['status'] === 'critical') {
                    $problemas[] = "🔴 $name: {$check['message']}";
                } elseif ($check['status'] === 'warning') {
                    $problemas[] = "🟡 $name: {$check['message']}";
                }
            }
            
            if (empty($problemas)) {
                return;
            }
            
            $mensagem = "⚠️ ALERTA DE SAÚDE DO SISTEMA CEMADEN\n\n";
            $mensagem .= "Status: " . strtoupper($this->status) . "\n";
            $mensagem .= "Timestamp: " . date('d/m/Y H:i:s') . "\n\n";
            $mensagem .= "Problemas detectados:\n";
            $mensagem .= implode("\n", $problemas);
            
            // Envia email
            if (function_exists('sendEmail')) {
                sendEmail(
                    'admin@example.com',
                    $mensagem,
                    'ALERTA: Sistema CEMADEN com problemas'
                );
            }
            
            $this->logger->critical("Health Alert enviado - Sistema: " . $this->status);
            
        } catch (Exception $e) {
            $this->logger->error("Erro ao enviar alerta de saúde: " . $e->getMessage());
        }
    }
    
    /**
     * Salva resultado no banco
     */
    public function saveResults(): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO health_checks 
                (data_check, status, resumo, detalhes)
                VALUES (NOW(), :status, :resumo, :detalhes)
            ");
            
            $results = $this->getResults();
            
            $stmt->execute([
                'status' => $this->status,
                'resumo' => json_encode($results['summary']),
                'detalhes' => json_encode($results['checks'])
            ]);
            
        } catch (PDOException $e) {
            $this->logger->error("Erro ao salvar health check: " . $e->getMessage());
        }
    }
}

/**
 * Execução do Health Check
 */
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    try {
        $pdo = Database::getConnection();
        $logger = Logger::getInstance(__DIR__ . '/logs');
        
        $healthCheck = new HealthCheckService($pdo, $logger);
        
        echo "=== EXECUTANDO HEALTH CHECK ===\n\n";
        
        $results = $healthCheck->runAllChecks();
        
        echo "Status Geral: " . strtoupper($results['status']) . "\n";
        echo "Timestamp: {$results['timestamp']}\n\n";
        echo "Resumo:\n";
        echo "  ✅ OK: {$results['summary']['ok']}\n";
        echo "  ⚠️  Warnings: {$results['summary']['warning']}\n";
        echo "  🔴 Critical: {$results['summary']['critical']}\n\n";
        
        echo "Detalhes:\n";
        foreach ($results['checks'] as $name => $check) {
            $icon = $check['status'] === 'ok' ? '✅' : 
                   ($check['status'] === 'warning' ? '⚠️' : '🔴');
            echo "  $icon $name: {$check['message']}\n";
        }
        
        // Salva resultados
        $healthCheck->saveResults();
        
        // Alerta se não estiver saudável
        $healthCheck->alertIfUnhealthy();
        
        // Retorna código de saída apropriado
        exit($results['status'] === 'healthy' ? 0 : 1);
        
    } catch (Exception $e) {
        echo "ERRO: " . $e->getMessage() . "\n";
        exit(2);
    }
}
?>