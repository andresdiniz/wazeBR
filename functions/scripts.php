<?php
/**
 * Funções principais da aplicação
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // Certifique-se de que o PHPMailer esteja instalado via Composer

// Realiza uma busca no banco de dados com base nos parâmetros fornecidos.
function selectFromDatabase(PDO $pdo, string $table, array $where = [])
{
    try {
        // Monta a query base
        $query = "SELECT * FROM {$table}";
        
        // Adiciona condições do WHERE, se houver
        if (!empty($where)) {
            $conditions = array_map(fn($key) => "{$key} = :{$key}", array_keys($where));
            $query .= " WHERE " . implode(" AND ", $conditions);
        }

        // Prepara e executa a query
        $stmt = $pdo->prepare($query);
        foreach ($where as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->execute();

        // Retorna os resultados
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log de erro (apenas para depuração)
        error_log("Erro ao executar consulta: " . $e->getMessage());
        return false;
    }
}

/**
 * Exemplo de outra função: Verifica se um usuário está logado.
 * Você pode adicionar mais funções aqui conforme necessário.
 */
function isLoggedIn()
{
    return isset($_SESSION['user']);
}

/**
 * Função para redirecionar com base no status de login.
 */
function requireLogin()
{
    if (!isLoggedIn()) {
        header('Location: /login');
        exit;
    }
}

function getSiteUsers(PDO $pdo) {
    $userId = $_SESSION['usuario_id'] ?? 1; // ID padrão ou da sessão
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getSiteSettings(PDO $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM settings LIMIT 1");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
// Função para registrar log de execução e atualizar a última execução
function logExecution($scriptName, $status, $message = null) {
    try {
        // Conexão com o banco de dados
        $pdo = Database::getConnection();

        // Início da transação para garantir que ambos os inserts ocorram de forma atômica
        $pdo->beginTransaction();

        // Criando o horário de execução no fuso horário de São Paulo
        $executionTime = new DateTime("now", new DateTimeZone('America/Sao_Paulo'));
        
        // Primeiro INSERT: Registrar log de execução na tabela execution_log
        $stmtLog = $pdo->prepare("INSERT INTO execution_log (script_name, execution_time, status, message) 
                                  VALUES (?, ?, ?, ?)");
        $stmtLog->execute([$scriptName, $executionTime->format('Y-m-d H:i:s'), $status, $message]);

        // Segundo INSERT: Atualizar a última execução na tabela rotina_cron
        $stmtUpdate = $pdo->prepare("UPDATE rotina_cron SET last_execution = ? WHERE name_cron = ?");
        $stmtUpdate->execute([$executionTime->format('Y-m-d H:i:s'), $scriptName]);

        // Comitar a transação após os dois inserts
        $pdo->commit();

    } catch (PDOException $e) {
        // Em caso de erro, reverter a transação
        $pdo->rollBack();
        echo "Erro ao registrar log de execução e atualizar rotina: " . $e->getMessage();
    }
}


// Função para verificar se o script pode ser executado
function shouldRunScript($scriptName) {
    try {
        // Conexão com o banco de dados
        $pdo = Database::getConnection();
        
        // Consulta para obter a última execução, o intervalo e o status de ativo
        $stmt = $pdo->prepare("SELECT last_execution, execution_interval, active FROM rotina_cron WHERE name_cron = ?");
        $stmt->execute([$scriptName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            // Verifica se o script está ativo
            if ($result['active'] !== '1') {
                echo "O script $scriptName está inativo.<br>";
                return false; // Script não pode ser executado se não estiver ativo
            }

            // Obter a última execução e o intervalo, considerando o fuso horário de São Paulo
            $lastExecution = new DateTime($result['last_execution'], new DateTimeZone('America/Sao_Paulo'));
            $executionInterval = $result['execution_interval'];
            
            // Obter o horário atual em São Paulo
            $currentDateTime = new DateTime("now", new DateTimeZone('America/Sao_Paulo'));
            
            // Calcular o próximo horário de execução
            $interval = new DateInterval("PT" . $executionInterval . "M"); // Convertendo o intervalo para minutos
            $nextExecutionTime = clone $lastExecution;
            $nextExecutionTime->add($interval);
            
            // Verifica se o próximo horário de execução já passou
            if ($currentDateTime >= $nextExecutionTime) {
                return true; // Pode executar o script
            } else {
                echo "Script $scriptName aguardando para ser executado.<br>";
                return false; // Não pode executar o script ainda
            }
        } else {
            // Caso não encontre o script, retorna falso
            echo "Script $scriptName não encontrado na tabela rotina_cron.<br>";
            return false;
        }
    } catch (PDOException $e) {
        echo "Erro ao verificar o tempo de execução para o script $scriptName: " . $e->getMessage() . "<br>";
        return false; // Caso ocorra erro na consulta
    }
}

// Função de execução com verificação
function executeScript($scriptName, $scriptFile) {
    if (shouldRunScript($scriptName)) {
        try {
            echo "Iniciando $scriptName...<br>";
            include __DIR__ . '/../' . $scriptFile;
            logExecution($scriptName, 'success', 'Execução bem-sucedida.');
            echo "Finalizando $scriptName.<br>";
        } catch (Exception $e) {
            logExecution($scriptName, 'error', $e->getMessage());
            echo "Erro na execução de $scriptName: " . $e->getMessage() . "<br>";
        }
    } else {
        echo "O script $scriptName não será executado, pois o intervalo de execução ainda não foi atingido.<br>";
    }
}

// Função para enviar o e-mail
function sendEmailAlert($stationName, $valor, $cotaMaxima) {
    $to = "andresoaresdiniz201218@gmail.com"; // Defina o e-mail do destinatário
    $subject = "Alerta: Excedeu Cota Máxima de Estação";
    $message = "
    Alerta: A estação $stationName excedeu a cota máxima definida.

    Valor acumulado: $valor
    Cota máxima: $cotaMaxima

    Por favor, tome as devidas providências.
    ";
    $headers = "From: wazebrasil@fenixsmm.store\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    // Envia o e-mail
    if (mail($to, $subject, $message, $headers)) {
        error_log("E-mail de alerta enviado para $to");
    } else {
        error_log("Falha ao enviar o e-mail de alerta");
    }
}

function getIp() {
    // Verifica se o IP está no cabeçalho HTTP_CLIENT_IP
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } 
    // Verifica se o IP está no cabeçalho HTTP_X_FORWARDED_FOR (usado em proxies)
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Pode conter uma lista de IPs, pega o primeiro da lista
        $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ipList[0]);
    } 
    // Obtém o IP diretamente do REMOTE_ADDR
    else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }

    // Valida o formato do IP (IPv4 e IPv6)
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    } else {
        return 'IP não válido';
    }
}


function writeLog($logFilePath, $message) {
    $logMessage = "[" . date("Y-m-d H:i:s") . "] " . $message . PHP_EOL;
    file_put_contents($logFilePath, $logMessage, FILE_APPEND);
}

function sendEmail($userEmail, $emailBody, $titleEmail) {
    //teste github
    $logFilePath = __DIR__ . '/email_logs.txt';

    $mail = new PHPMailer(true);
    try {
        // Configuração do servidor SMTP
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com'; 
        $mail->SMTPAuth   = true;
        $mail->Username   = 'wazebrasil@fenixsmm.store'; 
        $mail->Password   = '@Ndre2025';             
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
        $mail->Port       = 587; 
        $mail->CharSet = 'UTF-8'; 

        // Remetente e destinatário
        $mail->setFrom('wazebrasil@fenixsmm.store', 'Waze Brasil');
        $mail->addAddress($userEmail); 

        // Corpo do e-mail
        $mail->isHTML(true); 
        $mail->Subject = $titleEmail; 
        $mail->Body    = $emailBody;

        // Envia o e-mail
        if ($mail->send()) {
            writeLog($logFilePath, "E-mail enviado para $userEmail com sucesso.");
            return true;  // Retorna true se o envio for bem-sucedido
        } else {
            writeLog($logFilePath, "Falha ao enviar o e-mail para $userEmail. Erro: {$mail->ErrorInfo}");
            return false; // Retorna false se o envio falhar
        }
    } catch (Exception $e) {
        writeLog($logFilePath, "Falha ao enviar o e-mail para $userEmail. Erro: {$e->getMessage()}");
        return false; // Retorna false se ocorrer uma exceção
    }
}

<?php
function consultarLocalizacaoKm($longitude, $latitude, $raio = 250, $data = null) {
    // Define a URL base da API
    $urlBase = "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm";

    // Usa a data atual se nenhuma data for fornecida
    if (!$data) {
        $data = date('Y-m-d');
    }

    // Constrói a URL com os parâmetros
    $url = sprintf(
        "%s?lng=%s&lat=%s&r=%d&data=%s",
        $urlBase,
        urlencode($longitude),
        urlencode($latitude),
        $raio,
        urlencode($data)
    );

    // Inicializa o cURL
    $ch = curl_init();

    // Configurações do cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Se necessário para evitar problemas com SSL

    // Executa a requisição
    $response = curl_exec($ch);

    // Verifica erros
    if (curl_errno($ch)) {
        throw new Exception('Erro ao executar a requisição: ' . curl_error($ch));
    }

    // Fecha a conexão cURL
    curl_close($ch);

    // Decodifica a resposta JSON
    $data = json_decode($response, true);

    // Verifica se a resposta contém o campo "km"
    if (is_array($data) && isset($data[0]['km'])) {
        return $data[0]['km'];
    }

    // Retorna null caso não haja "km" na resposta
    return null;
}
