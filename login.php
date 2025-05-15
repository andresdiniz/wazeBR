<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'error_log.log');

session_start();

// Verificar CSRF Token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("Tentativa de CSRF detectada");
        http_response_code(403);
        exit('Ação não autorizada');
    }
}

try {
    require_once './config/configbd.php';
    require_once './functions/scripts.php';
    
    $pdo = Database::getConnection();

    // Consulta por email
    $sql = "SELECT * FROM users WHERE email = :email";
    $stmt = $pdo->prepare($sql);

    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (password_verify($password, $user['password'])) {
            // Regenerar ID da sessão
            session_regenerate_id(true);

            // Definir dados da sessão
            $_SESSION = [
                'usuario_id' => $user['id'],
                'usuario_nome' => $user['nome'],
                'usuario_email' => $user['email'],
                'usuario_username' => $user['username'],
                'usuario_photo' => $user['photo'],
                'usuario_id_parceiro' => $user['id_parceiro'],
                'type' => $user['type'],
                'csrf_token' => bin2hex(random_bytes(32))
            ];

            setcookie('usuario_id_parceiro', $user['id_parceiro'], [
                'expires' => time() + (86400 * 30),
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);

            // Registrar login no histórico
            $ip = getIp();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido';

            $sql = "
                INSERT INTO historic_login 
                (user_id, login_time, ip_address, user_agent, success)
                VALUES 
                (:user_id, NOW(), :ip, :user_agent, 1)
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $user['id'],
                ':ip' => $ip,
                ':user_agent' => substr($userAgent, 0, 255)
            ]);

            header("Location: /");
            exit();
        }
    }

    // Registrar tentativa fracassada
    $sql = "
        INSERT INTO historic_login 
        (user_id, login_time, ip_address, user_agent, success)
        VALUES 
        (NULL, NOW(), :ip, :user_agent, 0)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':ip' => getIp(),
        ':user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido', 0, 255)
    ]);

    header("Location: login.html?erro=Credenciais inválidas");
    exit();

} catch (PDOException $e) {
    error_log("Erro de banco: " . $e->getMessage());
    header("Location: login.html?erro=Erro no sistema");
    exit();
}