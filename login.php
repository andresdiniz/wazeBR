<?php
// Headers de segurança
header("X-XSS-Protection: 1; mode=block");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Configurações de erro
ini_set('display_errors', 0); // Mudar para 0 em produção
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.log');
date_default_timezone_set('America/Sao_Paulo');

// Configurações de sessão
session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Strict',
    'lifetime' => 0
]);

session_start();

// Gera CSRF Token se não existir
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Se não for POST, redireciona para login.html
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Se já está logado, vai para dashboard
    if (isset($_SESSION['usuario_id'])) {
        header("Location: /dashboard");
        exit();
    }
    // Senão, mostra o formulário
    header("Location: /login.html");
    exit();
}

// ============================================================
// PROCESSAMENTO DO LOGIN (POST)
// ============================================================

try {
    require_once './config/configbd.php';
    require_once './functions/scripts.php';

    $pdo = Database::getConnection();

    // Captura dados do formulário
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    // Valida CSRF Token
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        error_log('Tentativa de CSRF - IP: ' . $_SERVER['REMOTE_ADDR'] . ' - Email: ' . $email);
        sleep(2);
        header("Location: /login.html?erro=" . urlencode("Sessão expirada. Recarregue a página e tente novamente."));
        exit();
    }

    // Informações do usuário
    $dateTime = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $dataHora = $dateTime->format('Y-m-d H:i:s');
    $ip = substr(getIp(), 0, 50);
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido', 0, 255);

    // ============================================================
    // RATE LIMITING - Proteção contra brute force
    // ============================================================

    // Verifica tentativas por IP (5 tentativas em 15 minutos)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM historic_login 
        WHERE ip = ? AND success = 0 
        AND login_time > (NOW() - INTERVAL 15 MINUTE)
    ");
    $stmt->execute([$ip]);
    $tentativasFalhasIP = $stmt->fetchColumn();

    if ($tentativasFalhasIP >= 5) {
        error_log("IP bloqueado por múltiplas tentativas: $ip");
        sleep(3);
        header("Location: /login.html?erro=" . urlencode("Muitas tentativas de login. Por favor, aguarde 15 minutos antes de tentar novamente."));
        exit();
    }

    // Verifica tentativas por email (3 tentativas em 15 minutos)
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM historic_login 
            WHERE email_attempt = ? AND success = 0 
            AND login_time > (NOW() - INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$email]);
        $tentativasFalhasEmail = $stmt->fetchColumn();

        if ($tentativasFalhasEmail >= 3) {
            error_log("Email com múltiplas tentativas: $email - IP: $ip");
            sleep(3);
            header("Location: /login.html?erro=" . urlencode("Conta temporariamente bloqueada por motivos de segurança. Recupere sua senha ou aguarde 15 minutos."));
            exit();
        }
    }

    // ============================================================
    // VALIDAÇÕES DE INPUT
    // ============================================================

    // Validação: campos vazios
    if (empty($email) || empty($password)) {
        sleep(1);
        header("Location: /login.html?erro=" . urlencode("Por favor, preencha todos os campos."));
        exit();
    }

    // Validação: formato de email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sleep(1);
        header("Location: /login.html?erro=" . urlencode("Por favor, insira um endereço de email válido."));
        exit();
    }

    // Validação: tamanho mínimo da senha
    if (strlen($password) < 6) {
        sleep(1);
        header("Location: /login.html?erro=" . urlencode("Senha deve ter no mínimo 6 caracteres."));
        exit();
    }

    // ============================================================
    // AUTENTICAÇÃO
    // ============================================================

    // Busca usuário no banco
    $stmt = $pdo->prepare("
        SELECT id, password, nome, email, username, photo, id_parceiro, type 
        FROM users 
        WHERE email = ? 
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verifica credenciais
    if ($user && password_verify($password, $user['password'])) {
        
        // ============================================================
        // LOGIN BEM-SUCEDIDO
        // ============================================================
        
        // Regenera ID da sessão por segurança
        session_regenerate_id(true);

        // Armazena dados na sessão
        $_SESSION = [
            'usuario_id' => $user['id'],
            'usuario_nome' => $user['nome'],
            'usuario_email' => $user['email'],
            'usuario_username' => $user['username'],
            'usuario_photo' => $user['photo'],
            'usuario_id_parceiro' => $user['id_parceiro'],
            'type' => $user['type'],
            'csrf_token' => bin2hex(random_bytes(32)),
            'login_time' => time()
        ];

        // Cookie "Lembrar-me" (opcional - 30 dias)
        if (isset($_POST['rememberMe']) && $_POST['rememberMe'] === 'on') {
            $cookieExpire = time() + (30 * 24 * 60 * 60); // 30 dias
            setcookie('parceiro_id', $user['id_parceiro'], $cookieExpire, '/', '', true, true);
        }

        // Registra login bem-sucedido no histórico
        $stmt = $pdo->prepare("
            INSERT INTO historic_login 
            (user_id, login_time, ip, user_agent, success, email_attempt)
            VALUES (?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([$user['id'], $dataHora, $ip, $userAgent, $email]);

        // Log de sucesso
        error_log("Login bem-sucedido - User ID: {$user['id']} - Email: $email - IP: $ip");

        // Redireciona para dashboard
        header("Location: /dashboard");
        exit();

    } else {
        
        // ============================================================
        // LOGIN FALHOU
        // ============================================================
        
        // Registra tentativa falha no histórico
        $stmt = $pdo->prepare("
            INSERT INTO historic_login 
            (login_time, ip, user_agent, success, email_attempt)
            VALUES (?, ?, ?, 0, ?)
        ");
        $stmt->execute([$dataHora, $ip, $userAgent, $email]);

        // Log de falha
        error_log("Login falhou - Email: $email - IP: $ip - Tentativas IP: " . ($tentativasFalhasIP + 1));

        // Delay aleatório para dificultar ataques
        sleep(random_int(2, 4));

        // Mensagem genérica de erro (não revela se email existe)
        $errorMsg = "Email ou senha incorretos. Por favor, verifique suas credenciais e tente novamente.";
        
        // Se já tem muitas tentativas, avisa
        if ($tentativasFalhasIP >= 3) {
            $tentativasRestantes = 5 - ($tentativasFalhasIP + 1);
            $errorMsg = "Email ou senha incorretos. Você tem mais $tentativasRestantes tentativa(s) antes de ser bloqueado temporariamente.";
        }

        header("Location: /login.html?erro=" . urlencode($errorMsg));
        exit();
    }

} catch (PDOException $e) {
    // Erro de banco de dados
    error_log("Erro PDO no login: " . $e->getMessage() . " - IP: " . $_SERVER['REMOTE_ADDR']);
    sleep(2);
    header("Location: /login.html?erro=" . urlencode("Erro ao processar sua solicitação. Por favor, tente novamente em alguns instantes."));
    exit();

} catch (Exception $e) {
    // Erro geral
    error_log("Erro geral no login: " . $e->getMessage() . " - IP: " . $_SERVER['REMOTE_ADDR']);
    sleep(2);
    header("Location: /login.html?erro=" . urlencode("Ocorreu um erro inesperado. Se o problema persistir, entre em contato com o suporte."));
    exit();
}
?>