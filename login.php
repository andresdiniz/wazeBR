<?php
session_start();

require_once './config/configbd.php';
require_once './functions/scripts.php';

function redirectWithError($msg) {
    header("Location: /login?erro=" . urlencode($msg));
    exit();
}

// Se usuário já está logado, manda pro dashboard direto
if (isset($_SESSION['usuario_id'])) {
    header("Location: /dashboard");
    exit();
}

// Tenta auto-login pelo cookie "remember_me"
if (!isset($_SESSION['usuario_id']) && isset($_COOKIE['remember_me'])) {
    try {
        $pdo = Database::getConnection();
        $token = $_COOKIE['remember_me'];

        $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ? AND remember_expire > NOW() LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Auto-login bem sucedido, preenche sessão
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

            header("Location: /dashboard");
            exit();
        } else {
            // Token inválido ou expirado: apaga cookie
            setcookie('remember_me', '', time() - 3600, '/', '', true, true);
        }
    } catch (Exception $e) {
        error_log("Erro ao tentar auto-login remember_me: " . $e->getMessage());
        // Segue normalmente mostrando o formulário
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    $posted_token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';
    if (!$posted_token || !hash_equals($session_token, $posted_token)) {
        redirectWithError('Token CSRF inválido.');
    }

    try {
        $pdo = Database::getConnection();

        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';
        $rememberMe = isset($_POST['remember']);

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
                // Cria token para "lembrar-me"
                $token = bin2hex(random_bytes(32));
                setcookie('remember_me', $token, time() + (86400 * 7), '/', '', true, true);

                $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, remember_expire = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?");
                $stmt->execute([$token, $user['id']]);
            } else {
                // Remove token antigo se existir e cookie
                setcookie('remember_me', '', time() - 3600, '/', '', true, true);
                $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL, remember_expire = NULL WHERE id = ?");
                $stmt->execute([$user['id']]);
            }

            $stmt = $pdo->prepare("INSERT INTO historic_login (user_id, login_time, ip, user_agent, success) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$user['id'], $dataHora, $ip, $userAgent]);

            header("Location: /dashboard");
            exit();
        }

        // Falha no login
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
}

// Gera token CSRF para GET (exibição do formulário)
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
        /* estilos simplificados para foco */
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
            box-shadow: 0 0 10px #33CCFF;
            outline: none;
        }
        .alert-warning {
            border-left: 5px solid #FF6633;
            background: #fff4e5;
            color: #663300;
            padding: 10px 20px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
    </style>
</head>
<body>
<main class="login-card" role="main" aria-label="Login de parceiros">
    <div class="brand-section">
        <img src="/img/login-img.svg" alt="Logo Waze Brasil" width="200" height="200" />
    </div>
    <div class="form-section">
        <h1>Login de Parceiros</h1>
        <p>Acesse sua plataforma colaborativa</p>

        <?php if (isset($_GET['erro'])): ?>
            <div class="alert-warning" role="alert">
                <?= htmlspecialchars($_GET['erro']) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" novalidate aria-describedby="formHelp" aria-label="Formulário de login">
            <label for="email" class="form-label">Email institucional</label>
            <input
                type="email"
                id="email"
                name="email"
                class="form-control mb-3"
                placeholder="email@empresa.com"
                required
                autocomplete="email"
                aria-required="true"
            />

            <label for="password" class="form-label">Senha de acesso</label>
            <input
                type="password"
                id="password"
                name="password"
                class="form-control mb-3"
                placeholder="Senha"
                required
                minlength="6"
                autocomplete="current-password"
                aria-required="true"
            />

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>" />

            <div class="form-check mb-3">
                <input type="checkbox" id="remember" name="remember" class="form-check-input" />
                <label for="remember" class="form-check-label">Manter conectado por 7 dias</label>
            </div>

            <button type="submit" class="btn-primary">Entrar</button>

            <p class="mt-3">
                <a href="forgot-password.html" aria-label="Esqueceu sua senha?">Esqueceu sua senha?</a>
            </p>
        </form>
    </div>
</main>
</body>
</html>
