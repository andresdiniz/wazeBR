<?php
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/class.php';

use Dotenv\Dotenv;

// Verifica se o arquivo .env existe no caminho especificado
$envPath = __DIR__ . '/../.env';

if (!file_exists($envPath)) {
    // Em produção, use um log ou exceção, não 'die'
    error_log("CRÍTICO: Arquivo .env não encontrado no caminho: $envPath");
}

try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
} catch (Exception $e) {
    error_log("CRÍTICO: Erro ao carregar o .env: " . $e->getMessage());
}

// Configura o ambiente de debug com base na variável DEBUG do .env
$isDebug = isset($_ENV['DEBUG']) && $_ENV['DEBUG'] == 'true';

if ($isDebug) {
    // Configura as opções de log para ambiente de debug
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/debug.log');

    // Cria o diretório de logs se não existir
    if (!is_dir(__DIR__ . '/../logs')) {
        mkdir(__DIR__ . '/../logs', 0777, true);
    }
}
// Configura o fuso horário padrão (Garantindo que a variável de ambiente seja usada, se houver)
date_default_timezone_set($_ENV['TIMEZONE'] ?? 'America/Sao_Paulo');

// Importação das classes necessárias para envio de e-mail
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Função otimizada para obter dados do usuário com cache estático (memoization).
 * @param PDO $pdo Instância do PDO.
 * @param int $userId ID do usuário.
 * @return array|null
 */
function getSiteUsers(PDO $pdo, $userId)
{
    // OTIMIZAÇÃO: Cache Estático (Memoization)
    static $cache = [];
    $cacheKey = (int) $userId;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    
    // Inicia o timing (assumindo $GLOBALS['logger'] está setado no index)
    if (function_exists('timeEvent') && isset($GLOBALS['logger'])) {
        timeEvent($GLOBALS['logger'], 'DB_getSiteUsers');
    }

    // Reutilizando a função selectFromDatabase para buscar informações do usuário
    $result = selectFromDatabase($pdo, 'users', ['id' => $userId]);

    // Fim do timing
    if (function_exists('timeEvent') && isset($GLOBALS['logger'])) {
        timeEvent($GLOBALS['logger'], 'DB_getSiteUsers', true);
    }

    // Retornar apenas o primeiro resultado, pois o ID é único
    $data = $result ? $result[0] : null;
    $cache[$cacheKey] = $data; // Salva no cache

    return $data;
}

function getSitepages($pdo, $pageurl)
{
    // OTIMIZAÇÃO: Adicionar cache estático se os dados da página forem estáticos por requisição
    // Exemplo: static $cache = []; if (isset($cache[$pageurl])) { return $cache[$pageurl]; }

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
        } else {
            // Se não encontrou, pode adicionar uma mensagem de erro ou página não encontrada
            $data['pageData'] = null;
        }
    } catch (PDOException $e) {
        // Caso ocorra erro na consulta
        $data['pageData'] = null;
        error_log("Erro ao consultar a tabela 'pages': " . $e->getMessage());
    }
    
    // OTIMIZAÇÃO: Salvar no cache estático se implementado
    // $cache[$pageurl] = $data;
    
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
    // OTIMIZAÇÃO: Sugestão para o usuário: Evitar SELECT * sempre que possível
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
        } 

        $columns = implode(", ", array_map(fn($key) => "`{$key}`", $expectedKeys));
        $placeholders = implode(", ", array_map(fn($key) => ":{$key}", $expectedKeys));
        $query = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";

        $stmt = $pdo->prepare($query);

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
        return false;
    } catch (Exception $e) {
        error_log("Erro Geral: " . $e->getMessage());
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

function generateUuid(): string
{
    // UUID v4 é gerado com 32 caracteres hexadecimais e 4 hífens
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, // Versão 4
        mt_rand(0, 0x3fff) | 0x8000, // Bits 6 e 7 de 10
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

// Obtém configurações do site
function getSiteSettings(PDO $pdo)
{
    // OTIMIZAÇÃO: Cache Estático (Memoization)
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }
    
    // Inicia o timing (assumindo $GLOBALS['logger'] está setado no index)
    if (function_exists('timeEvent') && isset($GLOBALS['logger'])) {
        timeEvent($GLOBALS['logger'], 'DB_getSiteSettings');
    }

    // Obtém configurações do site da tabela 'settings' usando a função genérica
    $result = selectFromDatabase($pdo, 'settings');

    // Fim do timing
    if (function_exists('timeEvent') && isset($GLOBALS['logger'])) {
        timeEvent($GLOBALS['logger'], 'DB_getSiteSettings', true);
    }

    // Retorna apenas o primeiro registro, assumindo que há apenas uma configuração geral
    $data = $result ? $result[0] : null;
    $cache = $data; // Salva no cache

    return $data;
}

/*
 * Funções relacionadas a logs e execução de rotinas
 */

// Função para registrar log de execução e atualizar a última execução
function logExecution($scriptName, $status, $message, $pdo)
{
    try {
        // Obtém o tempo de execução
        $executionTime = (new DateTime("now", new DateTimeZone($_ENV['TIMEZONE'] ?? 'America/Sao_Paulo')))->format('Y-m-d H:i:s');

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

    } catch (PDOException $e) {
        error_log("Erro no banco de dados: " . $e->getMessage());
        echo "Erro: " . $e->getMessage();
    } catch (Exception $e) {
        error_log("Erro: " . $e->getMessage());
        echo "Erro: " . $e->getMessage();
    }
}

// Verifica se o script pode ser executado
function shouldRunScript($scriptName, $pdo)
{
    try {
        // Usando a função genérica selectFromDatabase para consultar a tabela 'rotina_cron'
        $result = selectFromDatabase($pdo, 'rotina_cron', ['name_cron' => $scriptName]);

        // Verifica se o script foi encontrado e está ativo
        if (empty($result)) {
            error_log("Script '$scriptName' não encontrado ou não está ativo.");
            return false;
        }

        // Se o script foi encontrado e está ativo
        $result = $result[0]; // Como esperamos um único resultado, pegamos o primeiro elemento

        if (isset($result['active']) && $result['active'] === '1') {
            // OTIMIZAÇÃO: Usar o timezone configurado no .env
            $timezone = $_ENV['TIMEZONE'] ?? 'America/Sao_Paulo';
            
            // Obtém a data da última execução do script e a converte para o fuso horário correto
            $lastExecution = new DateTime($result['last_execution'], new DateTimeZone($timezone));

            // Obtém a data e hora atuais no fuso horário correto
            $currentDateTime = new DateTime("now", new DateTimeZone($timezone));

            // Obtém o intervalo de execução em minutos a partir do banco de dados
            $executionInterval = isset($result['execution_interval']) ? (int) $result['execution_interval'] : 0;

            // Cria o intervalo em minutos (DateInterval precisa de um formato específico)
            $interval = new DateInterval("PT{$executionInterval}M");

            // Calcula o próximo horário de execução
            $nextExecutionTime = clone $lastExecution;
            $nextExecutionTime->add($interval);

            // Verifica se a data e hora atual já passou do tempo de próxima execução
            if ($currentDateTime >= $nextExecutionTime) {
                return true; // Momento de executar o script
            } else {
                return false; // Não é o momento de executar
            }
        }

        // Caso o script não esteja ativo
        error_log("Script '$scriptName' está inativo.");
        return false; // Caso o script esteja inativo
    } catch (PDOException $e) {
        // Caso ocorra um erro ao consultar o banco de dados
        $errorMessage = "Erro ao verificar o tempo de execução para o script '$scriptName': " . $e->getMessage();
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
            logExecution($scriptName, 'success', $logMessage, $pdo);
            error_log($logMessage);
        } catch (Exception $e) {
            // Log de erro
            logExecution($scriptName, 'error', $e->getMessage(), $pdo);
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
    } catch (Exception $e) {
        error_log("Erro ao carregar PHPMailer: " . $e->getMessage());  // Log de erro, caso o PHPMailer não seja carregado corretamente
    }
    $sendTime = date('Y-m-d H:i:s');
    $emailId = uniqid('email_', true); // Gerar ID único para o e-mail

    try {
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
        $mail->AltBody = strip_tags($emailBody);  // Converter para texto puro

        // Envia o e-mail
        if ($mail->send()) { 
            return true;
        } else {
            // Log de falha no envio
            error_log("Falha ao enviar e-mail (PHPMailer Error): " . $mail->ErrorInfo);
            return false;
        }
    } catch (Exception $e) {
        // Log detalhado do erro
        error_log('Erro ao enviar e-mail: ' . $e->getMessage());
        return false;
    }
}

/**
 * Função para registrar logs de e-mail (SIMPLIFICADA)
 */

function logEmail($type, $message)
{
    // OTIMIZAÇÃO: Usar o Logger principal (Logger::getInstance()) em vez de um arquivo de log separado
    // Manter a função como um wrapper para compatibilidade
    if (isset($GLOBALS['logger']) && method_exists($GLOBALS['logger'], 'log')) {
        $level = ($type == 'error') ? 'ERROR' : 'INFO';
        $GLOBALS['logger']->log($level, "EMAIL LOG: " . $message, ['type' => $type]);
    } else {
        // Fallback para arquivo de log
        $logFile = __DIR__ . '/logs/' . ($type == 'error' ? 'error_log.txt' : 'email_log.txt');
        if (!is_dir(__DIR__ . '/logs')) {
            mkdir(__DIR__ . '/logs', 0777, true);
        }
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] - $message" . PHP_EOL, FILE_APPEND);
    }
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
    // CORREÇÃO: Variável $ch não está definida. Adicionando curl_init
    $ch = curl_init($url); 
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
    // OTIMIZAÇÃO: Redirecionar para o Logger principal
    if (isset($GLOBALS['logger']) && method_exists($GLOBALS['logger'], 'log')) {
        $GLOBALS['logger']->log(strtoupper($level), $message, $context);
        return;
    }
    
    // Define o caminho do log (Fallback)
    $logDirectory = __DIR__ . '/logs/';
    if (!is_dir($logDirectory)) {
        mkdir($logDirectory, 0777, true); 
    }
    $logFile = $logDirectory . 'logs.log';

    $logMessage = sprintf(
        "[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        json_encode($context)
    );
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

// ... (measurePerformance, savePerformanceMetrics, getPublicPosts, getFeaturedPosts, sendErrorResponse, sendSuccessResponse, getLatestExecutionLogByStatus, getCredenciais, enviarNotificacaoPush, logToJson, logToJsonNotify, enviarNotificacaoWhatsApp, verificarConexaoWhatsApp, encontrarKmPorCoordenadasEPR permanecem inalteradas, pois são funções específicas e não o foco principal de otimização de request-response) ...

/**
 * Registra uma atividade do usuário no banco de dados e no log de aplicação.
 * @param PDO $pdo Conexão com o banco de dados.
 * @param object $logger Instância do Logger.
 * @param string $eventType Tipo de evento (LOGIN, VIEW_PAGE, ACTION_EDIT, etc.).
 * @param string $description Descrição resumida da ação.
 * @param array $details Detalhes adicionais (ID, dados, etc.).
 * @return bool
 */
function logUserActivity(PDO $pdo, $logger, string $eventType, string $description, array $details = []): bool {
    $userId = $_SESSION['usuario_id'] ?? 0;
    
    // 1. Grava no Log de Aplicação (Para rastreamento imediato)
    if ($logger && method_exists($logger, 'info')) {
        $logger->info("ATIVIDADE DE USUÁRIO: {$description}", array_merge(['user_id' => $userId, 'event' => $eventType], $details));
    }
    
    // 2. Grava no Banco de Dados (Para relatórios e auditoria)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_log (user_id, event_type, description, details)
            VALUES (:user_id, :event_type, :description, :details)
        ");
        
        return $stmt->execute([
            ':user_id' => $userId,
            ':event_type' => $eventType,
            ':description' => $description,
            // Armazena detalhes como JSON.
            ':details' => json_encode($details, JSON_UNESCAPED_UNICODE)
        ]);
    } catch (\PDOException $e) {
        // Usa o logger para erros do banco de dados
        if ($logger && method_exists($logger, 'error')) {
            $logger->error("Falha ao registrar atividade no BD", ['db_error' => $e->getMessage(), 'event' => $eventType]);
        }
        return false;
    }
}

/**
 * Inicia ou para um timer de performance e registra no log.
 * @param object $logger Instância do Logger.
 * @param string $event Nome do evento a ser medido.
 * @param bool $stop Se verdadeiro, para o timer e loga o resultado.
 * @return float|null Retorna a duração em ms se for uma parada.
 */
function timeEvent($logger, string $event, bool $stop = false): ?float {
    static $timers = [];
    $microtime = microtime(true);
    
    // Garante que o logger seja um objeto com o método info
    $useLogger = ($logger && method_exists($logger, 'info'));

    if (!$stop) {
        // INÍCIO
        $timers[$event] = $microtime;
        return null;
    }

    // FIM
    if (!isset($timers[$event])) {
        if ($useLogger) $logger->warning("Tentativa de parar timer não iniciado: {$event}");
        return null;
    }

    $duration = round(($microtime - $timers[$event]) * 1000, 2); // Duração em milissegundos
    unset($timers[$event]);
    
    if ($useLogger) {
        $logger->info("TIMING: {$event} concluído", ['duration_ms' => $duration]);
    }
    
    return $duration;
}
?>