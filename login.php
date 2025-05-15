<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.log');

session_start();

// Verificação do CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    
    // Verificar token vazio ou incompatível
    if (empty($postToken) {
        error_log('Tentativa de acesso sem CSRF token');
        die('Acesso não autorizado');
    }
    
    // Comparação segura
    if (!hash_equals($sessionToken, $postToken)) {
        error_log('Tentativa de CSRF detectada. Token recebido: ' . $postToken);
        die('Operação não permitida');
    }
}

try {
    require_once './config/configbd.php';
    require_once './functions/scripts.php';
    
    $pdo = Database::getConnection();

    // Sanitizar inputs
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

    // Consulta segura
    $stmt = $pdo->prepare("SELECT id, password, nome, email, username, photo, id_parceiro, type FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Dados para log
    $logData = [
        'ip' => getIp(),
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido', 0, 255),
        'email' => $email
    ];

    if ($user && password_verify($password, $user['password'])) {
        // Login bem-sucedido
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

        setcookie('usuario_id_parceiro', $user['id_parceiro'], [
            'expires' => time() + 86400 * 30,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        // Registrar login bem-sucedido
        $stmt = $pdo->prepare("
            INSERT INTO historic_login 
            (user_id, ip_address, user_agent, success)
            VALUES (?, ?, ?, 1)
        ");
        $stmt->execute([
            $user['id'],
            $logData['ip'],
            $logData['user_agent']
        ]);

        header("Location: /dashboard");
        exit();
    }

    // Login falhou
    $stmt = $pdo->prepare("
        INSERT INTO historic_login 
        (user_id, ip_address, user_agent, success)
        VALUES (NULL, ?, ?, 0)
    ");
    $stmt->execute([
        $logData['ip'],
        $logData['user_agent']
    ]);

    // Log seguro de erro
    logError('Tentativa de login falhou', $logData);
    sleep(random_int(1, 3)); // Prevenção contra força bruta

    header("Location: /login?erro=Credenciais inválidas");
    exit();

} catch (PDOException $e) {
    logError('Erro de banco de dados', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    header("Location: /login?erro=Erro no sistema");
    exit();
} catch (Exception $e) {
    logError('Erro geral', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    header("Location: /login?erro=Erro inesperado");
    exit();
}

function logError(string $message, array $context = []): void
{
    $logEntry = sprintf(
        "[%s] %s: %s\n",
        date('Y-m-d H:i:s'),
        $message,
        json_encode($context, JSON_UNESCAPED_SLASHES)
    );
    error_log($logEntry, 3, __DIR__ . '/auth_errors.log');
}