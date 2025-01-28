<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Verificar se o arquivo .env existe
$envPath = __DIR__ . '/../.env';

if (!file_exists($envPath)) {
    die("Arquivo .env não encontrado no caminho: $envPath");
}

// Carregar variáveis de ambiente
$dotenv = Dotenv::createImmutable(__DIR__ . '/../.env'); // Corrigido o caminho para subir um nível
$dotenv->load();

try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    echo "Arquivo .env carregado com sucesso!\n";
    echo "Conteúdo do .env carregado:\n EMAIL_USERNAME: " . $_ENV['EMAIL_USERNAME'] . "\n";
    print_r($_ENV);  // Para ver todas as variáveis carregadas
} catch (Exception $e) {
    die("Erro ao carregar o .env: " . $e->getMessage());
}


// Verificar o valor da variável DEBUG
if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true') {
    // Ativar logs de erros
    ini_set('display_errors', 0); // Desativa a exibição de erros para o usuário
    ini_set('log_errors', 1); // Ativa o registro de erros
    ini_set('error_log', __DIR__ . '/error_log.txt'); // Caminho do arquivo de log

    // Definir o nível de erro que será registrado
    error_reporting(E_ALL); // Registra todos os tipos de erros
    // Definir o manipulador de erros
    set_error_handler("customErrorHandler");

    // Registrar a função de captura de erros fatais
    register_shutdown_function("shutdownHandler");
} else {
    // Caso DEBUG esteja desativado, garantir que os erros não sejam exibidos ou registrados
    ini_set('display_errors', 0);
    ini_set('log_errors', 0);
}
//Define o fuso horário padrão
date_default_timezone_set('America/Sao_Paulo');

/**
 * Funções principais da aplicação
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Funções relacionadas ao banco de dados
 */

// Realiza uma busca no banco de dados com base nos parâmetros fornecidos.
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

// Obtém informações dos usuários
function getSiteUsers(PDO $pdo)
{
    $userId = $_SESSION['usuario_id'] ?? 1;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Obtém configurações do site
function getSiteSettings(PDO $pdo)
{
    $stmt = $pdo->prepare("SELECT * FROM settings LIMIT 1");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Funções relacionadas a logs e execução de rotinas
 */

// Registra log de execução e atualiza a última execução
function logExecution($scriptName, $status, $message = null)
{
    try {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        $executionTime = new DateTime("now", new DateTimeZone('America/Sao_Paulo'));

        $stmtLog = $pdo->prepare("INSERT INTO execution_log (script_name, execution_time, status, message) 
                                  VALUES (?, ?, ?, ?)");
        $stmtLog->execute([$scriptName, $executionTime->format('Y-m-d H:i:s'), $status, $message]);

        $stmtUpdate = $pdo->prepare("UPDATE rotina_cron SET last_execution = ? WHERE name_cron = ?");
        $stmtUpdate->execute([$executionTime->format('Y-m-d H:i:s'), $scriptName]);

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo "Erro ao registrar log de execução e atualizar rotina: " . $e->getMessage();
    }
}

// Verifica se o script pode ser executado
function shouldRunScript($scriptName)
{
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT last_execution, execution_interval, active FROM rotina_cron WHERE name_cron = ?");
        $stmt->execute([$scriptName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['active'] === '1') {
            $lastExecution = new DateTime($result['last_execution'], new DateTimeZone('America/Sao_Paulo'));
            $currentDateTime = new DateTime("now", new DateTimeZone('America/Sao_Paulo'));
            $interval = new DateInterval("PT" . $result['execution_interval'] . "M");
            $nextExecutionTime = $lastExecution->add($interval);

            return $currentDateTime >= $nextExecutionTime;
        }
        return false;
    } catch (PDOException $e) {
        echo "Erro ao verificar o tempo de execução para o script $scriptName: " . $e->getMessage();
        return false;
    }
}

// Executa o script com verificação
function executeScript($scriptName, $scriptFile)
{
    if (shouldRunScript($scriptName)) {
        try {
            include __DIR__ . '/../' . $scriptFile;
            logExecution($scriptName, 'success', 'Execução bem-sucedida.');
        } catch (Exception $e) {
            logExecution($scriptName, 'error', $e->getMessage());
        }
    }
}
/**
 * Funções relacionadas a e-mails
 */

// Função personalizada para enviar e-mails
function sendEmail($userEmail, $emailBody, $titleEmail)
{
    $mail = new PHPMailer(true);
    try {
        // Gerar ID único para o envio do e-mail e horário de envio
        $emailId = uniqid('email_', true);
        $sendTime = date('Y-m-d H:i:s');

        $mail->Host = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['EMAIL_USERNAME'];
        $mail->Password = $_ENV['EMAIL_PASSWORD'];
        $mail->Port = $_ENV['SMTP_PORT'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->setFrom($_ENV['EMAIL_USERNAME'], 'Waze Portal Brasil');
        $mail->addAddress($userEmail);
        $mail->isHTML(true);
        $mail->Subject = $titleEmail;
        $mail->Body = $emailBody;

        // Envia o e-mail
        if ($mail->send()) {
            // Log de sucesso do envio de e-mail
            $logMessage = "ID do E-mail: $emailId | Horário: $sendTime | Destinatário: $userEmail | Status: Enviado com sucesso";
            logEmail('success', $logMessage);
            return true;
        }
    } catch (Exception $e) {
        // Log de erro
        $logMessage = "ID do E-mail: $emailId | Horário: $sendTime | Destinatário: $userEmail | Erro: " . $e->getMessage();
        logEmail('error', $logMessage);
        return false;
    }
}

/**
 * Função de manipulação de erros do PHP
 */

// Função para registrar logs de erros
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

// Função para manipular erros do PHP
function customErrorHandler($errno, $errstr, $errfile, $errline)
{
    $logMessage = "Erro PHP: [$errno] $errstr - Arquivo: $errfile - Linha: $errline";
    logEmail('error', $logMessage);
    return true; // Não propaga o erro
}

// Função para capturar erros fatais
function shutdownHandler()
{
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR)) {
        $logMessage = "Erro Fatal: " . $error['message'] . " - Arquivo: " . $error['file'] . " - Linha: " . $error['line'];
        logEmail('error', $logMessage);
    }
}
 
// Obtém o endereço IP do usuário
function getIp()
{
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ipList[0]);
    }
    return $_SERVER['REMOTE_ADDR'];
}

// Consulta localização por longitude e latitude
function consultarLocalizacaoKm($longitude, $latitude, $raio = 250, $data = null)
{
    $urlBase = "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm";
    $data = $data ?? date('Y-m-d');
    $url = sprintf("%s?lng=%s&lat=%s&r=%d&data=%s", $urlBase, $longitude, $latitude, $raio, $data);

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
