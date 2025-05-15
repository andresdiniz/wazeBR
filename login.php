<?php
session_start();

// Configurações iniciais
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'error_log.log');
date_default_timezone_set('America/Sao_Paulo');

require_once './config/configbd.php';
require_once './functions/scripts.php';

function redirectWithError($msg) {
    header("Location: /login?erro=" . urlencode($msg));
    exit();
}

// Função para criar cookie seguro
function setRememberMe($userId) {
    $token = bin2hex(random_bytes(32));
    setcookie('remember_me', $token, time() + (86400 * 7), '/', '', true, true);
    $_SESSION['remember_me_token'] = $token;
    
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, remember_expire = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?");
    $stmt->execute([$token, $userId]);
}

// CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';

    if (!$posted_token || !hash_equals($session_token, $posted_token)) {
        redirectWithError('Token CSRF inválido.');
    }
}

try {
    $pdo = Database::getConnection();

    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember']) ? true : false;

    $stmt = $pdo->prepare("SELECT id, password, nome, email, username, photo, id_parceiro, type FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

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

        if ($rememberMe) {
            setRememberMe($user['id']);
        }

        $stmt = $pdo->prepare("INSERT INTO historic_login (user_id, login_time, ip, user_agent, success) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([$user['id'], $dataHora, $ip, $userAgent]);

        header("Location: /dashboard");
        exit();
    }

    $stmt = $pdo->prepare("INSERT INTO historic_login (login_time, ip, user_agent, success) VALUES (?, ?, ?, 0)");
    $stmt->execute([$dataHora, $ip, $userAgent]);

    sleep(random_int(1, 3));
    redirectWithError('Credenciais inválidas');

} catch (PDOException $e) {
    error_log("Erro PDO: " . $e->getMessage());
    redirectWithError('Erro no sistema');
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    redirectWithError('Erro inesperado');
}
?>

<!-- HTML Modernizado e Acessível -->
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Parceiros - Waze BR</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: #1A237E linear-gradient(135deg, #1A237E, #33CCFF);
            font-family: 'Roboto', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-card {
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            overflow: hidden;
            display: flex;
            max-width: 900px;
            width: 100%;
        }
        .brand-section {
            background: linear-gradient(135deg, #33CCFF, #FF6633);
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }
        .form-section {
            flex: 1;
            padding: 40px;
        }
        .form-control, .btn {
            border-radius: 10px;
        }
        .btn-primary {
            background: linear-gradient(45deg, #33CCFF, #FF6633);
            border: none;
            color: white;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        .alert {
            border-left: 5px solid #FF6633;
        }
    </style>
</head>
<body>
<main class="login-card">
    <div class="brand-section">
        <img src="/img/login-img.svg" alt="Logo Waze Brasil" width="200" height="200">
    </div>
    <div class="form-section">
        <h2 class="mb-3">Login de Parceiros</h2>
        <p class="text-muted mb-4">Acesse sua plataforma colaborativa</p>

        <?php if (isset($_GET['erro'])): ?>
            <div class="alert alert-warning" role="alert">
                <?= htmlspecialchars($_GET['erro']) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" aria-label="Formulário de login de parceiros">
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" name="email" id="email" required autocomplete="email">
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Senha</label>
                <input type="password" class="form-control" name="password" id="password" required autocomplete="current-password" minlength="6">
            </div>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="remember" id="remember">
                <label class="form-check-label" for="remember">Manter conectado por 7 dias</label>
            </div>
            <button type="submit" class="btn btn-primary w-100">Entrar</button>
            <p class="mt-3 text-center">
                <a href="forgot-password.html" class="text-decoration-none">Esqueceu sua senha?</a>
            </p>
        </form>
    </div>
</main>
</body>
</html>
