<?php
/**
 * Funções principais da aplicação
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '/../vendor/autoload.php'; // Certifique-se de que o PHPMailer esteja instalado via Composer
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

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

// Envia um e-mail personalizado
function sendEmail($userEmail, $emailBody, $titleEmail)
{
    $mail = new PHPMailer(true);
    try {
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

        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Funções utilitárias
 */

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
