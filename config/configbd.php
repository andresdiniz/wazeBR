<?php

// Configurações do Banco de Dados
define('DB_HOST', 'localhost');
define('DB_NAME', 'u509716858_wazeportal');
define('DB_USER', 'u509716858_wazeportal');
define('DB_PASS', '@Ndre2024.');

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
                // Em produção, evite exibir a mensagem diretamente para maior segurança
                die("Erro ao conectar ao banco de dados: " . $e->getMessage());
            }
        }

        return self::$instance;
    }
}
