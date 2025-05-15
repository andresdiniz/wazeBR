<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'error_log.log');
date_default_timezone_set('America/Sao_Paulo'); // Definir timezone no PHP

session_start();

// Verificação CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) {
        error_log('Tentativa de acesso sem CSRF token');
        die('Acesso não autorizado');
    }
    
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        error_log('CSRF token inválido de ' . $_SERVER['REMOTE_ADDR']);
        die('Token de segurança inválido');
    }
}

try {
    require_once './config/configbd.php';
    require_once './functions/scripts.php';
    
    $pdo = Database::getConnection();

    // Sanitização
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

    // Consulta segura
    $stmt = $pdo->prepare("SELECT id, password, nome, email, username, photo, id_parceiro, type 
                          FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Obter data/hora SP
    $dataHora = (new DateTime())->format('Y-m-d H:i:s');
    $ip = substr(getIp(), 0, 50);
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido', 0, 255);

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        
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

        // Registrar login (timezone SP via PHP)
        $stmt = $pdo->prepare("
            INSERT INTO historic_login 
            (user_id, login_time, ip, user_agent, success)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $user['id'],
            $dataHora,
            $ip,
            $userAgent
        ]);

        header("Location: /dashboard");
        exit();
    }

    // Login falhou
    $stmt = $pdo->prepare("
        INSERT INTO historic_login 
        (login_time, ip, user_agent, success)
        VALUES (?, ?, ?, 0)
    ");
    $stmt->execute([
        $dataHora,
        $ip,
        $userAgent
    ]);

    sleep(random_int(1, 3));
    header("Location: /login?erro=Credenciais inválidas");
    exit();

} catch (PDOException $e) {
    error_log("Erro PDO: " . $e->getMessage());
    header("Location: /login?erro=Erro no sistema");
    exit();
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    header("Location: /login?erro=Erro inesperado");
    exit();
}