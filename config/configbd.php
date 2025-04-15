<?php

// Configurações do Banco de Dados
define('DB_HOST', '185.213.81.52');
define('DB_NAME', 'u335174317_wazeportal');
define('DB_USER', 'u335174317_wazeportal');
define('DB_PASS', '@Ndre2025.');

// Definir o caminho do arquivo de log
define('LOG_FILE', __DIR__ . '/error_log.txt');

class MonitoredPDO extends PDO {
    private $queryCount = 0;
    private $totalQueryTime = 0;

    public function query($statement, $mode = PDO::ATTR_DEFAULT_FETCH_MODE, $arg3 = null, array $ctorargs = array()) {
        $start = microtime(true);
        $result = parent::query($statement, $mode, $arg3, $ctorargs);
        $this->updateStats($start);
        return $result;
    }

    public function exec($statement) {
        $start = microtime(true);
        $result = parent::exec($statement);
        $this->updateStats($start);
        return $result;
    }

    public function prepare($statement, $options = array()) {
        $start = microtime(true);
        $stmt = parent::prepare($statement, $options);
        $this->updateStats($start);
        return $stmt;
    }

    private function updateStats($start) {
        $this->queryCount++;
        $this->totalQueryTime += microtime(true) - $start;
        $GLOBALS['query_count'] = $this->queryCount;
        $GLOBALS['query_time'] = $this->totalQueryTime;
    }

    public function getQueryCount() {
        return $this->queryCount;
    }

    public function getQueryTime() {
        return $this->totalQueryTime;
    }
}

class Database {
    private static $instance = null;

    public static function getConnection() {
        if (self::$instance === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_PERSISTENT => true,
                ];

                // Usando nossa classe monitorada
                self::$instance = new MonitoredPDO($dsn, DB_USER, DB_PASS, $options);
                
            } catch (PDOException $e) {
                self::logError($e);
                die("Erro ao conectar ao banco de dados. Por favor, tente novamente mais tarde.");
            }
        }
        return self::$instance;
    }

    private static function logError($e) {
        if (is_writable(LOG_FILE)) {
            $errorDetails = date('Y-m-d H:i:s') . " | " . $e->getMessage() 
                          . " | Arquivo: " . $e->getFile() 
                          . " | Linha: " . $e->getLine() . "\n";
            file_put_contents(LOG_FILE, $errorDetails, FILE_APPEND);
        } else {
            error_log("Erro ao registrar no log: " . $e->getMessage());
        }
    }

    // Métodos adicionais para acesso às métricas
    public static function getQueryStats() {
        return [
            'count' => self::$instance ? self::$instance->getQueryCount() : 0,
            'time' => self::$instance ? round(self::$instance->getQueryTime() * 1000, 2) . ' ms' : '0 ms'
        ];
    }
}