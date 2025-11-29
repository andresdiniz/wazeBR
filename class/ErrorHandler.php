<?php
// classes/ErrorHandler.php

class ErrorHandler {
    private Logger $logger;
    private bool $isDebug;
    
    public function __construct(Logger $logger, bool $isDebug = false) {
        $this->logger = $logger;
        $this->isDebug = $isDebug;
    }
    
    public function register(): void {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }
    
    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $errorType = $this->getErrorType($errno);
        
        $context = [
            'file' => $errfile,
            'line' => $errline,
            'type' => $errorType
        ];
        
        // Classifica o erro para o log
        if ($errno & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) {
            $this->logger->error($errstr, $context);
        } elseif ($errno & (E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING)) {
            $this->logger->warning($errstr, $context);
        } else {
            $this->logger->notice($errstr, $context);
        }
        
        // Em modo debug, deixa o PHP mostrar erros de NOTICE/WARNING, mas loga.
        return $this->isDebug ? false : true; 
    }
    
    public function handleException(Throwable $exception): void {
        $context = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'code' => $exception->getCode()
        ];
        
        $this->logger->critical($exception->getMessage(), $context);
        
        if (!$this->isDebug) {
            http_response_code(500);
            
            // Renderiza página de erro usando a lógica do index.php para fallback
            $this->renderStandaloneError(500, 'Erro Interno do Servidor', 
                'Ocorreu um erro inesperado. Tente novamente mais tarde.', 
                $exception->getMessage(), 
                $exception->getTraceAsString());
        } else {
            // Se debug, apenas loga e deixa o PHP exibir o trace
            echo '<h1>Exceção Capturada!</h1><pre>';
            echo $exception;
            echo '</pre>';
            exit;
        }
    }
    
    public function handleShutdown(): void {
        $error = error_get_last();
        
        // Captura erros fatais que não puderam ser tratados por handleError
        if ($error !== null && $error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
            $this->logger->emergency('Fatal Error: ' . $error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $this->getErrorType($error['type'])
            ]);
            
            if (!$this->isDebug) {
                http_response_code(500);
                $this->renderStandaloneError(500, 'Erro Crítico', 
                    'O sistema encontrou um erro fatal e não pode continuar.', 
                    $error['message']);
            }
        }
    }
    
    private function renderStandaloneError(int $code, string $title, string $message, string $description = '', string $trace = ''): void {
        // Redefine a função global para usar as variáveis locais
        $errorCode = $code;
        $errorTitle = $title;
        $errorMessage = $message;
        $errorDescription = $description;
        $error_id = uniqid('err_');
        $errorTrace = $this->isDebug ? $trace : '';
        $pagina_retorno = '/';
        $isDebug = $this->isDebug; // Passa o status debug para o template
        
        // O template de erro standalone é crucial e deve ser robusto
        include __DIR__ . '/../frontend/error_standalone.php';
        exit;
    }
    
    private function getErrorType(int $errno): string {
        $types = [
            E_ERROR             => 'E_ERROR', E_WARNING           => 'E_WARNING',
            E_PARSE             => 'E_PARSE', E_NOTICE            => 'E_NOTICE',
            E_CORE_ERROR        => 'E_CORE_ERROR', E_CORE_WARNING      => 'E_CORE_WARNING',
            E_COMPILE_ERROR     => 'E_COMPILE_ERROR', E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
            E_USER_ERROR        => 'E_USER_ERROR', E_USER_WARNING      => 'E_USER_WARNING',
            E_USER_NOTICE       => 'E_USER_NOTICE', E_STRICT            => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', E_DEPRECATED        => 'E_DEPRECATED',
            E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
        ];
        
        return $types[$errno] ?? 'UNKNOWN';
    }
}