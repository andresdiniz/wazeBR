<?php
session_start();

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');
error_reporting(E_ALL);

require_once './config/configbd.php';
require_once './functions/scripts.php';

function redirectWithError(string $msg): void {
    $_SESSION['login_error'] = $msg;
    header("Location: /login");
    exit();
}

// Captura e limpa erro para exibir no toast
$login_error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);

// Se usuário já está logado, redireciona para dashboard
if (isset($_SESSION['usuario_id'])) {
    header("Location: /dashboard");
    exit();
}

// Auto-login via cookie "remember_me"
if (!isset($_SESSION['usuario_id']) && !empty($_COOKIE['remember_me'])) {
    try {
        $pdo = Database::getConnection();
        $token = $_COOKIE['remember_me'];

        $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ? AND remember_expire > NOW() LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = $user['id'];
            $_SESSION['usuario_nome'] = $user['nome'];
            $_SESSION['usuario_email'] = $user['email'];
            $_SESSION['usuario_username'] = $user['username'];
            $_SESSION['usuario_photo'] = $user['photo'];
            $_SESSION['usuario_id_parceiro'] = $user['id_parceiro'];
            $_SESSION['type'] = $user['type'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header("Location: /dashboard");
            exit();
        } else {
            // Token inválido ou expirado: remove cookie
            setcookie('remember_me', '', time() - 3600, '/', '', true, true);
        }
    } catch (Exception $e) {
        error_log("Erro auto-login remember_me: " . $e->getMessage());
        // continua para mostrar formulário
    }
}

// Processa POST (tentativa de login)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validação CSRF
    $posted_token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';
    if (!$posted_token || !hash_equals($session_token, $posted_token)) {
        redirectWithError('Token CSRF inválido.');
    }

    // Sanitização básica dos inputs
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember']);

    if (!$email || empty($password)) {
        redirectWithError('Email ou senha inválidos.');
    }

    try {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT id, password, nome, email, username, photo, id_parceiro, type FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $dataHora = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        $ip = substr(getIp(), 0, 50);
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido', 0, 255);

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = $user['id'];
            $_SESSION['usuario_nome'] = $user['nome'];
            $_SESSION['usuario_email'] = $user['email'];
            $_SESSION['usuario_username'] = $user['username'];
            $_SESSION['usuario_photo'] = $user['photo'];
            $_SESSION['usuario_id_parceiro'] = $user['id_parceiro'];
            $_SESSION['type'] = $user['type'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            if ($rememberMe) {
                $token = bin2hex(random_bytes(32));
                setcookie('remember_me', $token, time() + 86400 * 7, '/', '', true, true);

                $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, remember_expire = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?");
                $stmt->execute([$token, $user['id']]);
            } else {
                // Remove token e cookie se existir
                setcookie('remember_me', '', time() - 3600, '/', '', true, true);
                $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL, remember_expire = NULL WHERE id = ?");
                $stmt->execute([$user['id']]);
            }

            // Log de login bem sucedido
            $stmt = $pdo->prepare("INSERT INTO historic_login (user_id, login_time, ip, user_agent, success) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$user['id'], $dataHora, $ip, $userAgent]);

            header("Location: /dashboard");
            exit();
        }

        // Login falhou: log e erro
        $stmt = $pdo->prepare("INSERT INTO historic_login (login_time, ip, user_agent, success) VALUES (?, ?, ?, 0)");
        $stmt->execute([$dataHora, $ip, $userAgent]);

        // Delay anti brute-force
        sleep(random_int(1, 3));
        redirectWithError('Credenciais inválidas.');

    } catch (PDOException $e) {
        error_log("Erro PDO login.php: " . $e->getMessage());
        redirectWithError('Erro no sistema, tente novamente mais tarde.');
    } catch (Exception $e) {
        error_log("Erro geral login.php: " . $e->getMessage());
        redirectWithError('Erro inesperado, tente novamente.');
    }
}

// Gera token CSRF para GET (formulário)
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Login Parceiros - Waze BR</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
<style>
    body {
        background: linear-gradient(135deg, #1A237E, #33CCFF);
        font-family: 'Roboto', sans-serif;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        margin: 0;
    }
    .login-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        max-width: 900px;
        width: 100%;
        display: flex;
        overflow: hidden;
    }
    .brand-section {
        flex: 1;
        background: linear-gradient(135deg, #33CCFF, #FF6633);
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 40px;
    }
    .form-section {
        flex: 1;
        padding: 40px;
    }
    .btn-primary {
        background: linear-gradient(45deg, #33CCFF, #FF6633);
        border: none;
        border-radius: 10px;
        color: white;
        padding: 15px;
        width: 100%;
        font-weight: 600;
    }
    .btn-primary:hover {
        opacity: 0.9;
    }
    .form-control {
        border-radius: 10px;
        padding: 15px;
        border: 2px solid #e0e0e0;
    }
    .form-control:focus {
        border-color: #33CCFF;
        box-shadow: 0 0 5px #33CCFF;
        outline: none;
    }
    .remember-label {
        user-select: none;
    }
</style>
</head>
<body>
<div class="login-card">
    <div class="brand-section">
        <img src="https://wazeparceiros.com.br/imagens/logo_white.png" alt="Logo Waze Parceiros" style="max-width: 180px;"/>
    </div>
    <div class="form-section">
        <h2 class="mb-4 text-center" style="color:#333;">Entrar na Conta</h2>
        <form method="POST" action="/login" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>" />
            <div class="mb-3">
                <label for="email" class="form-label">E-mail</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="seu@email.com" required autofocus autocomplete="email" />
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Senha</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password" />
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" id="remember" name="remember" class="form-check-input" />
                <label for="remember" class="form-check-label remember-label">Manter-me conectado</label>
            </div>
            <button type="submit" class="btn btn-primary">Entrar</button>
        </form>
        <p class="mt-3 text-center">
            <a href="/register" style="color:#33CCFF;">Criar conta</a> | 
            <a href="/password-reset" style="color:#33CCFF;">Esqueci a senha</a>
        </p>
    </div>
</div>

<!-- Toast de Erro -->
<?php if ($login_error): ?>
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1100;">
  <div id="errorToast" class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">
        <?= htmlspecialchars($login_error) ?>
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fechar"></button>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const toastEl = document.getElementById('errorToast');
    if (toastEl) {
        const toast = new bootstrap.Toast(toastEl, { delay: 7000 });
        toast.show();
    }
});
</script>
</body>
</html>
