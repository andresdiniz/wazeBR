<?php
require __DIR__ . '/../vendor/autoload.php';

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

// Função para obter informações dos usuários
function getSiteUsers(PDO $pdo, $userId)
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


// Obtém configurações do site
function getSiteSettings(PDO $pdo)
{
// Obtém configurações do site
// Função para obter configurações gerais do site
    $stmt = $pdo->query("SELECT * FROM settings");
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
        // Cria uma conexão PDO (se ainda não existir), substitua os valores pela sua configuração
        $pdo = new PDO('mysql:host=localhost;dbname=seu_banco', 'usuario', 'senha');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Prepara a consulta SQL para buscar o script
        $stmt = $pdo->prepare("SELECT * FROM scripts WHERE script_name = :scriptName");
        $stmt->bindParam(':scriptName', $scriptName, PDO::PARAM_STR);
        $stmt->execute();

        // Verifica se o script foi encontrado e se está ativo
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
        // Caso ocorra um erro ao consultar o banco de dados
        echo "Erro ao verificar o tempo de execução para o script $scriptName: " . $e->getMessage();
        return false;
    }
}


// Executa o script com verificação
function executeScript($scriptName, $scriptFile)
{
    if (shouldRunScript($scriptName)) {
        try {
            // Incluir o script
            include __DIR__ . '/../' . $scriptFile;
        } catch (Exception $e) {
            // Log de erro, caso a execução do script falhe
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
    try {
        $mail = new PHPMailer(true);  // Cria a instância do PHPMailer
        echo "PHPMailer carregado corretamente!";
    } catch (Exception $e) {
        error_log("Erro ao carregar PHPMailer: " . $e->getMessage());  // Log de erro, caso o PHPMailer não seja carregado corretamente
    }
    $sendTime = date('Y-m-d H:i:s');
    $emailId = uniqid('email_', true); // Gerar ID único para o e-mail

    try {
        // Log de início do envio de e-mail
        $logMessage = "Iniciando envio do e-mail para: $userEmail | ID: $emailId";
        logEmail('info', $logMessage);


        $mail->Host = $_ENV['SMTP_HOST'];
        error_log("SMTP Host: " . $_ENV['SMTP_HOST']);  // Log da configuração do host SMTP
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['EMAIL_USERNAME'];
        error_log("E-mail Username: " . $_ENV['EMAIL_USERNAME']);  // Log da configuração do username
        $mail->Password = $_ENV['EMAIL_PASSWORD'];
        error_log("E-mail Password: " . $_ENV['EMAIL_PASSWORD']);  // Log da configuração da senha
        $mail->Port = $_ENV['SMTP_PORT'];
        error_log("SMTP Port: " . $_ENV['SMTP_PORT']);  // Log da configuração da porta SMTP
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->setFrom($_ENV['EMAIL_USERNAME'], 'Waze Portal Brasil');
        error_log($_ENV['EMAIL_USERNAME']);
        $mail->addAddress($userEmail);
        $mail->Subject = $titleEmail;
        $mail->Body = $emailBody;
        $mail->SMTPDebug = 2;  // Mostra detalhes de depuração
        $mail->Debugoutput = 'html';  // Saída de depuração em formato HTML
        $mail->AltBody = strip_tags($emailBody);  // Converter para texto puro

        // Envia o e-mail
        if ($mail->isSendmail() ){ // Utiliza o Sendmail do PHP (sem SMTP) {
            // Log de sucesso do envio
            $logMessage = "ID do E-mail: $emailId | Horário: $sendTime | Destinatário: $userEmail | Status: Enviado com sucesso";
            logEmail('success', $logMessage);
            return true;
        } else {
            // Log de falha no envio
            $logMessage = "ID do E-mail: $emailId | Horário: $sendTime | Destinatário: $userEmail | Status: Falha ao enviar e-mail";
            logEmail('error', $logMessage);
            return false;
        }
    } catch (Exception $e) {
        // Log detalhado do erro
        $logMessage = "ID do E-mail: $emailId | Horário: $sendTime | Destinatário: $userEmail | Erro: " . $e->getMessage();
        error_log('Erro ao enviar e-mail: ' . $e->getMessage());
        logEmail('error', $logMessage);
        return false;
    }
}

/**
 * Função para registrar logs de e-mail
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
function getIp() {
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

// Consulta localização por longitude e latitude
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