<?php
// classes/CronErrorHandler.php

class CronErrorHandler
{
    private Logger $logger;
    private bool $convertErrorsToExceptions;

    public function __construct(Logger $logger, bool $convertErrorsToExceptions = true)
    {
        $this->logger = $logger;
        $this->convertErrorsToExceptions = $convertErrorsToExceptions;
    }

    public function register(): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Trata erros PHP (warnings, notices, etc.)
     */
    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $context = [
            'file' => $errfile,
            'line' => $errline,
            'type' => $this->getErrorType($errno)
        ];

        // Converte erros graves em exceções (recomendado)
        if ($this->convertErrorsToExceptions && $this->isFatalError($errno)) {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        }

        if ($this->isFatalError($errno)) {
            $this->logger->error($errstr, $context);
        } elseif ($this->isWarning($errno)) {
            $this->logger->warning($errstr, $context);
        } else {
            $this->logger->notice($errstr, $context);
        }

        return true; // CRON nunca deve exibir erro
    }

    /**
     * Trata exceções não capturadas
     */
    public function handleException(Throwable $exception): void
    {
        $this->logger->critical($exception->getMessage(), [
            'file'  => $exception->getFile(),
            'line'  => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'code'  => $exception->getCode()
        ]);

        exit(1); // ERRO
    }

    /**
     * Captura erros fatais
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && $this->isShutdownFatal($error['type'])) {
            $this->logger->emergency('Fatal error', [
                'message' => $error['message'],
                'file'    => $error['file'],
                'line'    => $error['line'],
                'type'    => $this->getErrorType($error['type'])
            ]);

            exit(1);
        }
    }

    /* ==========================
       Helpers
       ========================== */

    private function isFatalError(int $errno): bool
    {
        return in_array($errno, [
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR,
            E_USER_ERROR, E_RECOVERABLE_ERROR
        ], true);
    }

    private function isWarning(int $errno): bool
    {
        return in_array($errno, [
            E_WARNING, E_CORE_WARNING,
            E_COMPILE_WARNING, E_USER_WARNING
        ], true);
    }

    private function isShutdownFatal(int $errno): bool
    {
        return in_array($errno, [
            E_ERROR, E_PARSE, E_CORE_ERROR,
            E_COMPILE_ERROR
        ], true);
    }

    private function getErrorType(int $errno): string
    {
        $types = [
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED'
        ];

        return $types[$errno] ?? 'UNKNOWN';
    }
}
