<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Verifica se o arquivo .env existe no caminho especificado
$envPath = __DIR__ . '/../.env';

if (!file_exists($envPath)) {
    die("Arquivo .env não encontrado no caminho: $envPath");
}

try {
    $dotenv = Dotenv::createImmutable(__DIR__.'/../');
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
// Obtém informações dos usuários
// Função para buscar informações do usuário atual
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Obtém configurações do site
function getSiteSettings(PDO $pdo)
{
// Obtém configurações do site
// Função para obter configurações gerais do site
    $stmt = $pdo->query("SELECT * FROM site_settings");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*
 * Funções relacionadas a logs e execução de rotinas
 */

// Registra log de execução e atualiza a última execução
function logExecution($scriptName, $status, $message)
{
    try {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        $executionTime = new DateTime("now", new DateTimeZone('America/Sao_Paulo'));

        $stmtLog = $pdo->prepare("INSERT INTO execution_log (script_name, execution_time, status, message) 
                                  VALUES (?, ?, ?, ?)");
        $stmtLog->execute([$scriptName, $executionTime->format('Y-m-d H:i:s'), $status, $message]);

        $stmtUpdate = $pdo->prepare("UPDATE script_status SET last_execution = ? WHERE script_name = ?");
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
// Verifica se o script pode ser executado
// Verifica se um script deve ser executado baseado no intervalo configurado
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
// Executa o script com verificação
// Executa um script se ele estiver dentro do intervalo permitido
            logExecution($scriptName, 'error', $e->getMessage());
        }
    }
}
/**
 * Funções relacionadas a e-mails
 */

// Função personalizada para enviar e-mails
function sendEmail($userEmail, $emailBody, $titleEmail)
/**
 * Funções relacionadas a e-mails
 */

// Função personalizada para enviar e-mails

// Função para enviar e-mails usando PHPMailer
        $sendTime = date('Y-m-d H:i:s');

        $mail->Host = $_ENV['SMTP_HOST'];
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
        // Envia o e-mail
    } catch (Exception $e) {
            // Log de sucesso do envio de e-mail
        $logMessage = "ID do E-mail: $emailId | Horário: $sendTime | Destinatário: $userEmail | Erro: " . $e->getMessage();
        logEmail('error', $logMessage);
        return false;
    }
}
        // Log de erro
/**
 * Função de manipulação de erros do PHP
 */

// Função para registrar logs de erros
function logEmail($type, $message)
/**
 * Função de manipulação de erros do PHP
 */

// Função para registrar logs de erros
// Função para registrar logs de e-mail
        mkdir(__DIR__ . '/logs', 0777, true);
    }

    // Cria o diretório de logs caso não exista
}
 
// Obtém o endereço IP do usuário
function getIp()
    // Adiciona a mensagem ao arquivo de log
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
// Obtém o endereço IP do usuário
// Função para obter o IP real do usuário
    }
    return $_SERVER['REMOTE_ADDR'];
}

// Consulta localização por longitude e latitude
function consultarLocalizacaoKm($longitude, $latitude, $raio = 250, $data = null)
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