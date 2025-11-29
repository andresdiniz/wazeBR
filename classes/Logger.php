<?php
// classes/Logger.php

class Logger {
    private static $instance = null;
    private $logDir;
    private $maxFileSize = 5242880; // 5MB
    private $maxFiles = 10;
    private $isDebug;
    
    // Níveis de log (PSR-3 like)
    const EMERGENCY = 'EMERGENCY';
    const ALERT     = 'ALERT';
    const CRITICAL  = 'CRITICAL';
    const ERROR     = 'ERROR';
    const WARNING   = 'WARNING';
    const NOTICE    = 'NOTICE';
    const INFO      = 'INFO';
    const DEBUG     = 'DEBUG';
    
    private function __construct(string $logDir, bool $isDebug = false) {
        $this->logDir = $logDir;
        $this->isDebug = $isDebug;
        $this->ensureLogDirectory();
    }
    
    public static function getInstance(string $logDir = null, bool $isDebug = false): self {
        if (self::$instance === null) {
            // Se chamado sem caminho, assume o default
            if ($logDir === null) {
                $logDir = __DIR__ . '/../../logs'; 
            }
            self::$instance = new self($logDir, $isDebug);
        }
        return self::$instance;
    }
    
    private function ensureLogDirectory(): bool {
        if (!is_dir($this->logDir)) {
            // Permissão 0775 é a recomendada para diretórios
            if (!mkdir($this->logDir, 0775, true)) {
                error_log("CRÍTICO: Não foi possível criar diretório de logs: {$this->logDir}");
                return false;
            }
        }
        
        if (!is_writable($this->logDir)) {
            error_log("AVISO: Diretório de logs não tem permissão de escrita: {$this->logDir}");
            return false;
        }
        
        return true;
    }
    
    public function log(string $level, string $message, array $context = []): bool {
        $logFile = $this->getLogFilePath($level);
        $formattedMessage = $this->formatMessage($level, $message, $context);
        
        $this->rotateLogIfNeeded($logFile);
        
        $result = file_put_contents(
            $logFile,
            $formattedMessage . PHP_EOL,
            FILE_APPEND | LOCK_EX // LOCK_EX garante atomicidade na escrita
        );
        
        if ($result === false) {
            error_log("Falha ao escrever no log: {$logFile}");
        }
        
        if ($this->isDebug) {
            // Loga no stderr/apache_error.log também em modo debug
            error_log("[{$level}] {$message}");
        }
        
        // Notificação Imediata para Níveis Críticos
        if (in_array($level, [self::EMERGENCY, self::ALERT, self::CRITICAL])) {
            $this->notifyAdmin($level, $message, $context);
        }
        
        return $result !== false;
    }
    
    private function formatMessage(string $level, string $message, array $context): string {
        $timestamp = date('Y-m-d H:i:s');
        
        // Otimização: JSON_UNESCAPED_UNICODE para melhor leitura em logs (UTF-8)
        $contextStr = !empty($context) 
            ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) 
            : '';
        
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        
        // Pega o chamador real (quem chamou o método de conveniência, ex: $logger->error())
        $caller = $trace[2] ?? $trace[1]; 
        
        $file = isset($caller['file']) ? basename($caller['file']) : 'unknown';
        $line = isset($caller['line']) ? $caller['line'] : '0';
        
        return sprintf(
            "[%s] [%s] [%s:%s] %s%s",
            $timestamp,
            $level,
            $file,
            $line,
            $message,
            $contextStr
        );
    }
    
    private function getLogFilePath(string $level): string {
        $date = date('Y-m-d');
        
        // Separa logs críticos e de erro para fácil monitoramento
        if (in_array($level, [self::EMERGENCY, self::ALERT, self::CRITICAL])) {
            return "{$this->logDir}/critical_{$date}.log";
        }
        
        if ($level === self::ERROR) {
            return "{$this->logDir}/error_{$date}.log";
        }
        
        if ($level === self::DEBUG) {
            return "{$this->logDir}/debug_{$date}.log";
        }
        
        return "{$this->logDir}/application_{$date}.log";
    }
    
    private function rotateLogIfNeeded(string $logFile): void {
        if (!file_exists($logFile)) {
            return;
        }
        
        // Verifica o tamanho do arquivo antes de ler/manipular
        if (filesize($logFile) >= $this->maxFileSize) {
            $this->rotateLog($logFile);
        }
    }
    
    private function rotateLog(string $logFile): void {
        $timestamp = date('YmdHis');
        $backupFile = $logFile . '.' . $timestamp;
        
        if (rename($logFile, $backupFile)) {
            // Compressão GZIP para economizar espaço
            if (function_exists('gzencode')) {
                // Otimização: Lê o conteúdo, comprime e salva, depois remove o original
                $content = file_get_contents($backupFile);
                if ($content !== false) {
                    file_put_contents($backupFile . '.gz', gzencode($content, 9, FORCE_GZIP));
                    unlink($backupFile);
                }
            }
            
            $this->cleanOldLogs($logFile);
        }
    }
    
    private function cleanOldLogs(string $logFile): void {
        $pattern = $logFile . '.*';
        $files = glob($pattern);
        
        if (count($files) > $this->maxFiles) {
            // Otimização: uSort para ordernar por tempo de modificação (mais antigos primeiro)
            usort($files, function($a, $b) {
                return filemtime($a) <=> filemtime($b);
            });
            
            $toRemove = count($files) - $this->maxFiles;
            for ($i = 0; $i < $toRemove; $i++) {
                // Remove o arquivo mais antigo
                if (is_file($files[$i])) {
                    unlink($files[$i]);
                }
            }
        }
    }
    
    private function notifyAdmin(string $level, string $message, array $context): void {
        // Função logEmail() deve estar definida em functions/scripts.php
        if (function_exists('logEmail')) {
            $subject = "[{$level}] Alerta do Sistema";
            $body = "Nível: {$level}\n";
            $body .= "Mensagem: {$message}\n";
            $body .= "Hora: " . date('Y-m-d H:i:s') . "\n";
            $body .= "Contexto: " . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            // Assumindo que logEmail() lida com o destinatário
            logEmail($level, $body);
        }
    }
    
    // Métodos de conveniência (Mantidos)
    public function emergency(string $message, array $context = []): bool { return $this->log(self::EMERGENCY, $message, $context); }
    public function alert(string $message, array $context = []): bool     { return $this->log(self::ALERT, $message, $context); }
    public function critical(string $message, array $context = []): bool  { return $this->log(self::CRITICAL, $message, $context); }
    public function error(string $message, array $context = []): bool     { return $this->log(self::ERROR, $message, $context); }
    public function warning(string $message, array $context = []): bool   { return $this->log(self::WARNING, $message, $context); }
    public function notice(string $message, array $context = []): bool    { return $this->log(self::NOTICE, $message, $context); }
    public function info(string $message, array $context = []): bool      { return $this->log(self::INFO, $message, $context); }
    public function debug(string $message, array $context = []): bool     { return $this->log(self::DEBUG, $message, $context); }
    
    // Método de Stats mantido
    public function getStats(): array {
        // ... (lógica de getStats aqui)
    }
}