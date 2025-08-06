<?php
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Verifica se o arquivo .env existe no caminho especificado
$envPath = __DIR__ . '/../.env';

if (!file_exists($envPath)) {
    die("Arquivo .env não encontrado no caminho: $envPath");
}

try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
} catch (Exception $e) {
    die("Erro ao carregar o .env: " . $e->getMessage());
}

// Configura o ambiente de debug com base na variável DEBUG do .env
if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] == 'true') {
    // Configura as opções de log para ambiente de debug

    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/debug.log');

    // Cria o diretório de logs se não existir
    if (!is_dir(__DIR__ . '/../logs')) {
        mkdir(__DIR__ . '/../logs', 0777, true);
    }
}
// Configura o fuso horário padrão para São Paulo
date_default_timezone_set('America/Sao_Paulo');

// Importação das classes necessárias para envio de e-mail
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Função para realizar consultas SELECT no banco de dados
function getSiteUsers(PDO $pdo, $userId)
{
    // Reutilizando a função selectFromDatabase para buscar informações do usuário
    $result = selectFromDatabase($pdo, 'users', ['id' => $userId]);

    // Retornar apenas o primeiro resultado, pois o ID é único
    return $result ? $result[0] : null;
}

function getSitepages($pdo, $pageurl)
{
    // Inicia o array para armazenar os dados da página
    $data = [];
    // Consulta na tabela 'pages' com o parâmetro 'url' para pegar os dados da página
    try {
        // Preparar a consulta SQL para buscar os dados da página com base na URL
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE url = :url LIMIT 1");
        $stmt->bindParam(':url', $pageurl, PDO::PARAM_STR);  // Usando o parâmetro correto $pageurl

        $stmt->execute();

        // Verifica se encontrou a página
        $pageData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pageData) {
            // Se encontrou, adiciona os dados da página ao array $data
            $data['pageData'] = $pageData;
            //logToFile('info','pages', $data); // Adicionado para depuração
            //var_dump($data); // Adicionado para depuração
        } else {
            // Se não encontrou, pode adicionar uma mensagem de erro ou página não encontrada
            $data['pageData'] = null;
        }
    } catch (PDOException $e) {
        // Caso ocorra erro na consulta
        $data['pageData'] = null;
        error_log("Erro ao consultar a tabela 'pages': " . $e->getMessage());
    }

    // Retorna o array com os dados da página ou null se não encontrada
    return $data;
}


/**
 * Função genérica para realizar consultas SELECT no banco de dados.
 *
 * @param PDO $pdo Instância do PDO.
 * @param string $table Nome da tabela no banco de dados.
 * @param array $where Condições para o WHERE (opcional).
 * @return array|false Retorna os resultados como um array associativo ou false em caso de falha.
 */
function selectFromDatabase(PDO $pdo, string $table, array $where = [])
{
    try {
        $query = "SELECT * FROM {$table}";
        if (!empty($where)) {
            $conditions = array_map(fn($key) => "{$key} = :{$key}", array_keys($where));
            $query .= " WHERE " . implode(" AND ", $conditions);
        }

        $stmt = $pdo->prepare($query);
        foreach ($where as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erro ao executar consulta: " . $e->getMessage());
        return false;
    }
}


function insertIntoDatabase(PDO $pdo, string $table, array $data)
{
    try {
        if (empty($data)) {
            throw new Exception("Nenhum dado fornecido para inserção.");
        }

        $data = is_assoc($data) ? [$data] : $data;
        $expectedKeys = array_keys($data[0]);

        foreach ($data as $index => $row) {
            if (array_keys($row) !== $expectedKeys) {
                throw new Exception("Linha {$index} possui colunas inconsistentes.");
            }
        }

        // Garante que não haja transação ativa antes de iniciar uma nova
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        } else {
            logToFile('info', "Transação ativa antes da inserção. Iniciando Inserção...");
        }

        $columns = implode(", ", array_map(fn($key) => "`{$key}`", $expectedKeys));
        $placeholders = implode(", ", array_map(fn($key) => ":{$key}", $expectedKeys));
        logToFile('info', "Colunas: " . $columns);
        logToFile('info', "Placeholders: " . $placeholders);
        $query = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";

        $stmt = $pdo->prepare($query);
        logToFile('info', "Query de inserção: " . $query);

        foreach ($data as $row) {
            $stmt->execute($row);
        }

        $pdo->commit();
        return true;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erro PDO: " . $e->getMessage());
        logToFile('error', "Erro PDO: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log("Erro Geral: " . $e->getMessage());
        logToFile('error', "Erro PDO: " . $e->getMessage());
        return false;
    } finally {
        $stmt = null; // Fecha statement
    }
}


/**
 * Verifica se um array é associativo.
 *
 * @param array $array O array a ser verificado.
 * @return bool Retorna true se o array for associativo; false caso contrário.
 */
function is_assoc(array $array): bool
{
    return array_keys($array) !== range(0, count($array) - 1);
}

// Função para obter informações dos usuários
/*function getSiteUsers(PDO $pdo, $userId)
{
    // Consulta SQL para buscar informações do usuário
    $sql = "SELECT * FROM users WHERE id = :id";
    
    // Prepara a consulta SQL
    $stmt = $pdo->prepare($sql);
    
    // Vincula o valor do ID do usuário
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
    
    // Executa a consulta
    $stmt->execute();
    
    // Retorna as informações do usuário como um array associativo
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
*/

// Obtém configurações do site
function getSiteSettings(PDO $pdo)
{
    // Obtém configurações do site da tabela 'settings' usando a função genérica
    $result = selectFromDatabase($pdo, 'settings');

    // Retorna apenas o primeiro registro, assumindo que há apenas uma configuração geral
    return $result ? $result[0] : null;
}

/*
 * Funções relacionadas a logs e execução de rotinas
 */

// Função para registrar log de execução e atualizar a última execução
function logExecution($scriptName, $status, $message, $pdo)
{
    try {
        // Obtém o tempo de execução
        $executionTime = (new DateTime("now", new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');

        // Atualiza a última execução na tabela rotina_cron
        $stmtUpdate = $pdo->prepare("UPDATE rotina_cron SET last_execution = ? WHERE name_cron = ?");
        $stmtUpdate->execute([$executionTime, $scriptName]);

        // Inserção na tabela execution_log
        $insertLog = insertIntoDatabase($pdo, 'execution_log', [
            'script_name' => $scriptName,
            'execution_time' => $executionTime,
            'status' => $status,
            'message' => $message
        ]);

        if (!$insertLog) {
            throw new Exception("Erro ao inserir log na tabela execution_log.");
        }

        // Log de execução bem-sucedida
        $logMessage = "Script '$scriptName' executado com sucesso. Status: $status - $message";
        error_log($logMessage);
        logToFile('info', $logMessage);

    } catch (PDOException $e) {
        error_log("Erro no banco de dados: " . $e->getMessage());
        logToFile('error', "Erro no banco de dados: " . $e->getMessage());
        echo "Erro: " . $e->getMessage();
    } catch (Exception $e) {
        error_log("Erro: " . $e->getMessage());
        logToFile('error', $e->getMessage());
        echo "Erro: " . $e->getMessage();
    }
}

// Verifica se o script pode ser executado
function shouldRunScript($scriptName, $pdo)
{
    try {
        // Inicia o processo de log
        logToFile('info', "Verificando se o script '$scriptName' deve ser executado.", ['scriptName' => $scriptName]);

        // Usando a função genérica selectFromDatabase para consultar a tabela 'rotina_cron'
        $result = selectFromDatabase($pdo, 'rotina_cron', ['name_cron' => $scriptName]);

        // Verifica se o script foi encontrado e está ativo
        if (empty($result)) {
            logToFile('warning', "Script '$scriptName' não encontrado ou não está ativo.", ['scriptName' => $scriptName]);
            error_log("Script '$scriptName' não encontrado ou não está ativo.");
            return false;
        }

        // Se o script foi encontrado e está ativo
        $result = $result[0]; // Como esperamos um único resultado, pegamos o primeiro elemento

        if (isset($result['active']) && $result['active'] === '1') {
            // Obtém a data da última execução do script e a converte para o fuso horário correto
            $lastExecution = new DateTime($result['last_execution'], new DateTimeZone('America/Sao_Paulo'));

            // Obtém a data e hora atuais no fuso horário correto
            $currentDateTime = new DateTime("now", new DateTimeZone('America/Sao_Paulo'));

            // Obtém o intervalo de execução em minutos a partir do banco de dados
            $executionInterval = isset($result['execution_interval']) ? (int) $result['execution_interval'] : 0;

            // Cria o intervalo em minutos (DateInterval precisa de um formato específico)
            $interval = new DateInterval("PT{$executionInterval}M");

            // Calcula o próximo horário de execução
            $nextExecutionTime = clone $lastExecution;
            $nextExecutionTime->add($interval);

            // Registra o horário da próxima execução no log
            logToFile('info', "Próxima execução do script '$scriptName': " . $nextExecutionTime->format('Y-m-d H:i:s'), ['scriptName' => $scriptName]);

            // Verifica se a data e hora atual já passou do tempo de próxima execução
            if ($currentDateTime >= $nextExecutionTime) {
                logToFile('info', "Script '$scriptName' pode ser executado. Próxima execução: " . $nextExecutionTime->format('Y-m-d H:i:s'), ['scriptName' => $scriptName]);
                return true; // Momento de executar o script
            } else {
                logToFile('info', "Script '$scriptName' não deve ser executado ainda. Próxima execução: " . $nextExecutionTime->format('Y-m-d H:i:s'), ['scriptName' => $scriptName]);
                return false; // Não é o momento de executar
            }
        }

        // Caso o script não esteja ativo
        logToFile('warning', "Script '$scriptName' está inativo.", ['scriptName' => $scriptName]);
        error_log("Script '$scriptName' está inativo.");
        return false; // Caso o script esteja inativo
    } catch (PDOException $e) {
        // Caso ocorra um erro ao consultar o banco de dados
        $errorMessage = "Erro ao verificar o tempo de execução para o script '$scriptName': " . $e->getMessage();
        logToFile('error', $errorMessage, ['scriptName' => $scriptName]);
        error_log($errorMessage);
        return false; // Caso haja erro ao consultar o banco
    }
}

// Executa o script com verificação
function executeScript($scriptName, $scriptFile, $pdo)
{
    echo "Verificando se é para executar o script: $scriptName\n";

    if (shouldRunScript($scriptName, $pdo)) {
        try {
            // Marca o tempo de início da execução
            $startTime = microtime(true);

            // Obtém o caminho completo do script
            $url = dirname(__DIR__) . '/' . ltrim($scriptFile, '/');
            echo 'Endereço do script é: ' . $url . PHP_EOL;

            // Verifica se o arquivo existe antes de incluí-lo
            if (!file_exists($url)) {
                throw new Exception("O script '$scriptFile' não foi encontrado no caminho '$url'.");
            }

            // Inclui o script
            include $url;

            // Marca o tempo de fim da execução
            $endTime = microtime(true);

            // Calcula o tempo total de execução
            $executionTime = $endTime - $startTime;

            // Mensagem de sucesso
            $logMessage = "Script '$scriptName' executado com sucesso. Tempo de execução: " . number_format($executionTime, 4) . " segundos.";

            // Registra logs
            logToFile('info', $logMessage);
            logExecution($scriptName, 'success', $logMessage, $pdo);
            error_log($logMessage);
        } catch (Exception $e) {
            // Log de erro
            logExecution($scriptName, 'error', $e->getMessage(), $pdo);
            logToFile('error', $e->getMessage(), ['scriptName' => $scriptName]);
            error_log("Erro ao executar o script '$scriptName': " . $e->getMessage());
        }
    } else {
        echo "Script '$scriptName' não deve ser executado.\n";
    }
}

/**
 * Funções relacionadas a e-mails
 */

// Função personalizada para enviar e-mails
function sendEmail($userEmail, $emailBody, $titleEmail)
{
    try {
        $mail = new PHPMailer(true);  // Cria a instância do PHPMailer
        error_log("PHPMailer carregado corretamente!");
    } catch (Exception $e) {
        error_log("Erro ao carregar PHPMailer: " . $e->getMessage());  // Log de erro, caso o PHPMailer não seja carregado corretamente
    }
    $sendTime = date('Y-m-d H:i:s');
    $emailId = uniqid('email_', true); // Gerar ID único para o e-mail

    try {
        // Log de início do envio de e-mail
        $logMessage = "Iniciando envio do e-mail para: $userEmail | ID: $emailId";
        logToFile('info', $logMessage);
        $mail->isSMTP(); // Usar SMTP explicitamente
        $mail->Host = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['EMAIL_USERNAME'];
        $mail->Password = $_ENV['EMAIL_PASSWORD'];
        $mail->Port = $_ENV['SMTP_PORT'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->setFrom($_ENV['EMAIL_USERNAME'], 'Waze Portal Brasil');
        $mail->addAddress($userEmail);
        $mail->Subject = $titleEmail;
        $mail->Body = $emailBody;
        $mail->CharSet = 'UTF-8';                         // Configurar para UTF-8
        $mail->Encoding = 'base64';                       // Melhor codificação para caracteres especiais
        /*$mail->SMTPDebug = 2;  // Mostra detalhes de depuração
        $mail->Debugoutput = 'html';  // Saída de depuração em formato HTML
        */
        $mail->AltBody = strip_tags($emailBody);  // Converter para texto puro

        // Envia o e-mail
        if ($mail->send()) { // Utiliza o Sendmail do PHP (sem SMTP) {
            // Log de sucesso do envio
            $logMessage = "ID do E-mail: $emailId | Horário: $sendTime | Destinatário: $userEmail | Status: Enviado com sucesso";
            logToFile('success', $logMessage);
            return true;
        } else {
            // Log de falha no envio
            $logMessage = "ID do E-mail: $emailId | Horário: $sendTime | Destinatário: $userEmail | Status: Falha ao enviar e-mail";
            logToFile('error', $logMessage);
            return false;
        }
    } catch (Exception $e) {
        // Log detalhado do erro
        $logMessage = "ID do E-mail: $emailId | Horário: $sendTime | Destinatário: $userEmail | Erro: " . $e->getMessage();
        error_log('Erro ao enviar e-mail: ' . $e->getMessage());
        logToFile('error', $logMessage);
        return false;
    }
}

/**
 * Função para registrar logs de e-mail
 * @param string $type Tipo de log (error ou email)
 * @param string $message Mensagem a ser registrada
 */

function logEmail($type, $message)
{
    $logFile = __DIR__ . '/logs/' . ($type == 'error' ? 'error_log.txt' : 'email_log.txt');

    // Cria o diretório de logs caso não exista
    if (!is_dir(__DIR__ . '/logs')) {
        mkdir(__DIR__ . '/logs', 0777, true);
    }

    // Adiciona a mensagem ao arquivo de log
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] - $message" . PHP_EOL, FILE_APPEND);
}


// Função para obter o endereço IP real do usuário
function getIp()
{
    // Verifica se o IP está no cabeçalho HTTP_CLIENT_IP (usado por proxies)
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    // Verifica se o IP está no cabeçalho HTTP_X_FORWARDED_FOR (usado por proxies reversos)
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Caso existam múltiplos IPs, o primeiro é o IP real
        $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ipList[0]);
    }
    // Caso contrário, usa o REMOTE_ADDR, que pode ser o IP direto do usuário
    return $_SERVER['REMOTE_ADDR'];
}

// Consulta localização por longitude e latitude NAO FUNCIONA EM
function consultarLocalizacaoKm($longitude, $latitude, $raio = 150, $data = null)
{
    $urlBase = "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm";
    $data = $data ?? date('Y-m-d');
    $url = sprintf("%s?lng=%s&lat=%s&r=%d&data=%s", $urlBase, $longitude, $latitude, $raio, $data);
    // Consulta localização por longitude e latitude
// Função para consultar localização geográfica via API do DNIT
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data[0]['km'] ?? null;
}

// Escreve logs em um arquivo
function writeLog($logFilePath, $message)
{
    $logMessage = "[" . date("Y-m-d H:i:s") . "] " . $message . PHP_EOL;
    file_put_contents($logFilePath, $logMessage, FILE_APPEND);
}

function logToFile($level, $message, $context = [])
{
    // Define o caminho do log
    $logDirectory = __DIR__ . '/logs/';

    // Verifica se o diretório "logs" existe, caso contrário, cria o diretório
    if (!is_dir($logDirectory)) {
        mkdir($logDirectory, 0777, true);  // Cria o diretório com permissões adequadas
    }

    // Exibe o nível do log e a mensagem para depuração
    //echo $level . ' ' . $message . PHP_EOL;

    // Define o caminho completo do arquivo de log
    $logFile = $logDirectory . 'logs.log';

    // Formata a mensagem de log com data, nível e contexto
    $logMessage = sprintf(
        "[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        json_encode($context)
    );

    // Registra a mensagem de log no arquivo
    error_log($logMessage, 3, $logFile);
}

function traduzirAlerta($tipo, $subtipo)
{
    try {
        $pdo = Database::getConnection();
        $sql = "SELECT alert_type.name AS tipo, alert_subtype.name AS subtipo
                FROM alert_type
                JOIN alert_subtype ON alert_type.id = alert_subtype.alert_type_id
                WHERE alert_type.value = :tipo AND alert_subtype.subtype_value = :subtipo";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':tipo', $tipo, PDO::PARAM_STR);
        $stmt->bindParam(':subtipo', $subtipo, PDO::PARAM_STR);
        $stmt->execute();

        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        return $resultado ?: ["tipo" => $tipo, "subtipo" => $subtipo]; // Retorna original caso não encontre
    } catch (PDOException $e) {
        error_log("Erro ao buscar tradução do alerta: " . $e->getMessage());
        return ["tipo" => $tipo, "subtipo" => $subtipo];
    }
}

function getParceiros(PDO $pdo, $id_parceiro = null)
{
    $query = "SELECT * FROM parceiros";

    // Se o ID do parceiro for passado e não for o administrador (99), aplica o filtro
    if (!is_null($id_parceiro) && $id_parceiro != 99) {
        $query .= " WHERE id = :id_parceiro";
    }

    $stmt = $pdo->prepare($query);

    // Se necessário, vincula o parâmetro do parceiro
    if (!is_null($id_parceiro) && $id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function measurePerformance(callable $function, &$metric)
{
    $start = microtime(true);
    $memoryStart = memory_get_usage();

    // Rede - fallback para ambientes sem exec
    $networkStart = [
        'bytes_sent' => 0,
        'bytes_recv' => 0
    ];

    if (function_exists('exec')) {
        $networkStart['bytes_sent'] = (int) trim(@exec('cat /proc/net/dev | grep eth0 | awk \'{print $10}\''));
        $networkStart['bytes_recv'] = (int) trim(@exec('cat /proc/net/dev | grep eth0 | awk \'{print $2}\''));
    }

    // Banco de dados
    $dbStart = [
        'queries' => 0,
        'time' => 0,
        'connection_time' => microtime(true)
    ];

    $result = $function();

    $networkEnd = [
        'bytes_sent' => 0,
        'bytes_recv' => 0
    ];

    if (function_exists('exec')) {
        $networkEnd['bytes_sent'] = (int) trim(@exec('cat /proc/net/dev | grep eth0 | awk \'{print $10}\''));
        $networkEnd['bytes_recv'] = (int) trim(@exec('cat /proc/net/dev | grep eth0 | awk \'{print $2}\''));
    }

    $networkMetrics = [
        'bytes_sent' => $networkEnd['bytes_sent'] - $networkStart['bytes_sent'],
        'bytes_recv' => $networkEnd['bytes_recv'] - $networkStart['bytes_recv'],
        'http_response_size' => ob_get_length()
    ];

    $dbMetrics = [
        'connection_time' => round((microtime(true) - $dbStart['connection_time']) * 1000, 2) . ' ms',
        'total_queries' => $GLOBALS['query_count'] ?? 0,
        'query_time' => round(($GLOBALS['query_time'] ?? 0) * 1000, 2) . ' ms'
    ];

    $metric = [
        'time' => round((microtime(true) - $start) * 1000, 2) . ' ms',
        'memory' => round((memory_get_peak_usage() - $memoryStart) / 1024 / 1024, 2) . ' MB',
        'network' => $networkMetrics,
        'database' => $dbMetrics
    ];

    return $result;
}

function savePerformanceMetrics($metrics, $startTime)
{
    $logData = [
        'timestamp' => date('c'),
        'total_time' => round((microtime(true) - $startTime) * 1000, 2) . ' ms',
        'system_metrics' => [
            'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
            'cpu_usage' => round(sys_getloadavg()[0], 2) . '%'
        ],
        'detailed_metrics' => $metrics
    ];
    $logDir = $_SERVER['DOCUMENT_ROOT'] . '/desempenho';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    file_put_contents(
        $_SERVER['DOCUMENT_ROOT'] . '/desempenho/metrics-' . date('Y-m-d') . '.log',
        json_encode($logData, JSON_PRETTY_PRINT) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function getPublicPosts($pdo)
{
    $stmt = $pdo->prepare("
        SELECT * FROM posts 
        WHERE publicado = 1 
        AND data_publicacao <= NOW() 
        ORDER BY data_publicacao DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getFeaturedPosts($pdo, $limit = 3)
{
    $stmt = $pdo->prepare("
        SELECT * FROM posts 
        WHERE destaque = 1 
        AND publicado = 1 
        ORDER BY data_publicacao DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sendErrorResponse($message, $statusCode = 500)
{
    http_response_code($statusCode);
    echo json_encode(['error' => $message]);
    exit;
}

function sendSuccessResponse($data, $statusCode = 200)
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $data
    ]);
    exit;
}


function getLatestExecutionLogByStatus(PDO $pdo, string $status): array|false // Adicionado tipo de retorno (requer PHP 7.1+)
{
    // A consulta SQL busca a entrada mais recente para o status fornecido
    $sql = "SELECT * FROM execution_log WHERE status = :status ORDER BY execution_time DESC LIMIT 1";

    try {
        // Prepara a consulta
        $stmt = $pdo->prepare($sql);

        // Verifica se a preparação falhou
        if ($stmt === false) {
            // Registra um erro no log do servidor (melhor que apenas silenciar)
            error_log("PDO prepare failed for query: " . $sql);
            // Dependendo da sua configuração, prepare pode lançar exceção aqui.
            return false; // Retorna false para indicar falha na preparação
        }

        // Vincula o parâmetro :status. PDO::PARAM_STR é apropriado para strings.
        // CORREÇÃO PRINCIPAL: Usando ':status' e a variável $status
        $stmt->bindParam(':status', $status, PDO::PARAM_STR);

        // Executa a consulta
        // Verifica se a execução falhou
        if ($stmt->execute() === false) {
            // Registra informações detalhadas do erro de execução, se disponíveis
            $errorInfo = $stmt->errorInfo();
            error_log("PDO execute failed: " . $errorInfo[2]); // errorInfo[2] geralmente contém a mensagem de erro do driver
            // Dependendo da sua configuração, execute pode lançar exceção aqui.
            return false; // Retorna false para indicar falha na execução
        }

        // Busca a primeira (e única, devido ao LIMIT 1) linha como array associativo
        return $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        // Captura exceções PDO se o PDO estiver configurado para PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        error_log("PDO Exception in getLatestExecutionLogByStatus: " . $e->getMessage());
        return false; // Retorna false em caso de exceção
    }
}
function getCredenciais(PDO $pdo, int $userId): ?array
{
    $sql = "SELECT device_token, auth_token, instance_name, phone_number
            FROM evolution_config
            WHERE user_id = :user_id
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['user_id' => $userId]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return $result ?: null;
}

function enviarNotificacaoPush($deviceToken, $authToken, $numero, $jsonData)
{
    $street = $jsonData['alerts'][0]['street'] ?? 'Nome da via desconhecida';
    $lat = $jsonData['alerts'][0]['location']['x'] ?? 'LATITUDE_INDEFINIDA';
    $lng = $jsonData['alerts'][0]['location']['y'] ?? 'LONGITUDE_INDEFINIDA';
    $timestampMs = $jsonData['alerts'][0]['pubMillis'] ?? null;
    $horaFormatada = $timestampMs ? date('d/m/Y H:i', intval($timestampMs / 1000)) : 'horário desconhecido';

    $mensagem = "🚨 Alerta de Acidente: Um acidente foi reportado em {$street} no seguinte local: https://www.waze.com/ul?ll={$lng},{$lat} às {$horaFormatada}. Por favor, verifique envie equipe especilizada.";

    // Instancia a classe corretamente com os tokens
    $api = new ApiBrasilWhatsApp($deviceToken, $authToken);

    // Envia a mensagem de texto
    $resposta = $api->enviarTexto($numero, $mensagem);
    var_dump(json_decode($resposta, true));
}