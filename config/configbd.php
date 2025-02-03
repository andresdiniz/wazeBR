<?php

// Configurações do Banco de Dados
define('DB_HOST', '82.197.82.45');
define('DB_NAME', 'u509716858_wazeportal');
define('DB_USER', 'u509716858_wazeportal');
define('DB_PASS', '@Ndre2024.');

// Definir o caminho do arquivo de log
define('LOG_FILE', __DIR__ . '/error_log.txt');

class Database {
    private static $instance = null; // Instância única do PDO

    /**
     * Obtém a conexão ao banco de dados.
     * 
     * @return PDO
     * @throws PDOException
     */
    public static function getConnection() {
        if (self::$instance === null) {
            try {
                // Criar a conexão PDO apenas uma vez
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Relatar erros como exceções
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Padrão: fetch como array associativo
                    PDO::ATTR_PERSISTENT => true, // Habilitar conexões persistentes
                ];

                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Em produção, evite exibir a mensagem diretamente
                self::logError($e);  // Logar o erro no arquivo de log
                die("Erro ao conectar ao banco de dados. Por favor, tente novamente mais tarde."); // Mensagem genérica para o usuário
            }
        }

        return self::$instance;
    }

    /**
     * Registra o erro no arquivo de log.
     *
     * @param Exception $e
     */
    private static function logError($e) {
        // Verifica se o arquivo de log é gravável
        if (is_writable(LOG_FILE)) {
            // Cria uma string com os detalhes do erro
            $errorDetails = date('Y-m-d H:i:s') . " | " . $e->getMessage() . " | Arquivo: " . $e->getFile() . " | Linha: " . $e->getLine() . "\n";

            // Grava o erro no arquivo de log
            file_put_contents(LOG_FILE, $errorDetails, FILE_APPEND);
        } else {
            // Se o arquivo de log não for gravável, logar no sistema de erro padrão
            error_log("Erro ao registrar no log: " . $e->getMessage());
        }
    }
}