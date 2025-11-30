<?php
// classes/ErrorHandler.php

class ErrorHandler {
    private Logger $logger;
    private bool $isDebug;
    private $twig; // Instância do Twig
    
    public function __construct(Logger $logger, $twig = null, bool $isDebug = false) {
        $this->logger = $logger;
        $this->twig = $twig;
        $this->isDebug = $isDebug;
    }
    
    public function register(): void {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }
    
    public function setTwig($twig): void {
        $this->twig = $twig;
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
        
        http_response_code(500);
        
        // Renderiza página de erro usando Twig
        $this->renderErrorPage(500, 'Erro Interno do Servidor', 
            'Ocorreu um erro inesperado. Tente novamente mais tarde.', 
            $exception->getMessage(), 
            $this->isDebug ? $exception->getTraceAsString() : '');
        
        exit;
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
            
            http_response_code(500);
            $this->renderErrorPage(500, 'Erro Crítico', 
                'O sistema encontrou um erro fatal e não pode continuar.', 
                $error['message']);
        }
    }
    
    /**
     * Renderiza a página de erro usando Twig
     */
    private function renderErrorPage(int $code, string $title, string $message, string $description = '', string $trace = ''): void {
        // Define os dados para o template
        $errorData = $this->getErrorData($code);
        
        $templateData = [
            'errorCode' => $code,
            'errorTitle' => $title,
            'errorMessage' => $message,
            'errorDescription' => $description,
            'errorTrace' => $trace,
            'error_id' => uniqid('ERR_'),
            'pagina_retorno' => '/',
            'titulo' => $title
        ];
        
        // Se temos Twig, usa o template error.twig
        if ($this->twig !== null) {
            try {
                echo $this->twig->render('error.twig', $templateData);
                exit;
            } catch (Throwable $e) {
                // Fallback se houver erro no Twig
                $this->renderFallbackError($code, $title, $message, $description);
            }
        } else {
            // Fallback sem Twig
            $this->renderFallbackError($code, $title, $message, $description);
        }
    }
    
    /**
     * Fallback para quando o Twig não está disponível
     */
    private function renderFallbackError(int $code, string $title, string $message, string $description = ''): void {
        $errorData = $this->getErrorData($code);
        
        echo '<!DOCTYPE html>
        <html lang="pt-br">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Erro ' . $code . ' - ' . htmlspecialchars($title) . '</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    margin: 0; padding: 20px; 
                    display: flex; justify-content: center; align-items: center;
                    min-height: 100vh; color: #333;
                }
                .error-container { 
                    background: white; padding: 40px; border-radius: 10px; 
                    box-shadow: 0 10px 30px rgba(0,0,0,0.2); text-align: center;
                    max-width: 600px;
                }
                .error-code { 
                    font-size: 72px; font-weight: bold; color: ' . $errorData['color'] . ';
                    margin: 0; 
                }
                .error-title { 
                    color: #2c3e50; margin: 10px 0; font-size: 24px;
                }
                .error-message { 
                    color: #7f8c8d; margin: 20px 0; line-height: 1.6;
                }
                .btn { 
                    display: inline-block; padding: 10px 20px; 
                    background: #667eea; color: white; text-decoration: none;
                    border-radius: 5px; margin: 5px; border: none; cursor: pointer;
                }
                .btn:hover { background: #5a6fd8; }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div style="width: 80px; height: 80px; background: ' . $errorData['color'] . '; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                    <i class="fas ' . $errorData['icon'] . '" style="color: white; font-size: 30px;"></i>
                </div>
                <div class="error-code">' . $code . '</div>
                <h1 class="error-title">' . htmlspecialchars($title) . '</h1>
                <p class="error-message">' . htmlspecialchars($message) . '</p>
                ' . ($description ? '<p style="color: #e74c3c; background: #fdf2f2; padding: 10px; border-radius: 5px;">' . htmlspecialchars($description) . '</p>' : '') . '
                <div style="margin-top: 30px;">
                    <a href="/" class="btn"><i class="fas fa-home"></i> Página Inicial</a>
                    <button onclick="history.back()" class="btn" style="background: #95a5a6;"><i class="fas fa-arrow-left"></i> Voltar</button>
                </div>
            </div>
        </body>
        </html>';
        exit;
    }
    
    /**
     * Obtém dados específicos para cada tipo de erro
     */
    private function getErrorData(int $code): array {
        $errors = [
            400 => [
                'title' => 'Requisição Inválida',
                'icon' => 'fa-exclamation-triangle',
                'color' => '#f39c12'
            ],
            401 => [
                'title' => 'Não Autorizado',
                'icon' => 'fa-lock', 
                'color' => '#e67e22'
            ],
            403 => [
                'title' => 'Acesso Negado',
                'icon' => 'fa-ban',
                'color' => '#c0392b'
            ],
            404 => [
                'title' => 'Página Não Encontrada',
                'icon' => 'fa-search',
                'color' => '#3498db'
            ],
            500 => [
                'title' => 'Erro Interno do Servidor',
                'icon' => 'fa-server',
                'color' => '#e74c3c'
            ],
            503 => [
                'title' => 'Serviço Indisponível',
                'icon' => 'fa-tools',
                'color' => '#95a5a6'
            ]
        ];
        
        return $errors[$code] ?? [
            'title' => 'Erro Inesperado',
            'icon' => 'fa-exclamation-circle',
            'color' => '#e74c3c'
        ];
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
    
    /**
     * Método público para exibir erros de forma controlada
     */
    public function showError(int $code, string $title, string $message, string $description = ''): void {
        http_response_code($code);
        $this->renderErrorPage($code, $title, $message, $description);
    }
}