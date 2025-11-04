<?php
header("X-XSS-Protection: 1; mode=block");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.log');
date_default_timezone_set('America/Sao_Paulo');

session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Strict'
]);

session_start();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Verifica se já está logado
if (isset($_SESSION['usuario_id'])) {
    header("Location: /dashboard");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        error_log('Tentativa de CSRF - IP: ' . $_SERVER['REMOTE_ADDR']);
        header('Location: /login?erro=' . urlencode('Acesso não autorizado'));
        exit();
    }

    try {
        require_once './config/configbd.php';
        require_once './functions/scripts.php';

        $pdo = Database::getConnection();

        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';

        $dateTime = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $dataHora = $dateTime->format('Y-m-d H:i:s');
        $ip = substr(getIp(), 0, 50);
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido', 0, 255);

        // Verificar limite de tentativas por IP
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM historic_login 
                               WHERE ip = ? AND success = 0 
                               AND login_time > (NOW() - INTERVAL 15 MINUTE)");
        $stmt->execute([$ip]);
        $tentativasFalhas = $stmt->fetchColumn();

        if ($tentativasFalhas >= 5) {
            error_log("IP bloqueado por múltiplas tentativas: $ip");
            sleep(2);
            header("Location: /login?erro=" . urlencode("Muitas tentativas falhas. Tente novamente em 15 minutos."));
            exit();
        }

        // Verificar limite de tentativas por email
        if ($email) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM historic_login 
                                   WHERE email_attempt = ? AND success = 0 
                                   AND login_time > (NOW() - INTERVAL 15 MINUTE)");
            $stmt->execute([$email]);
            $tentativasEmail = $stmt->fetchColumn();

            if ($tentativasEmail >= 3) {
                error_log("Email com múltiplas tentativas: $email");
                sleep(2);
                header("Location: /login?erro=" . urlencode("Conta temporariamente bloqueada. Tente recuperar sua senha."));
                exit();
            }
        }

        if (!$email || empty($password)) {
            sleep(1);
            header("Location: /login?erro=" . urlencode("Por favor, preencha todos os campos"));
            exit();
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sleep(1);
            header("Location: /login?erro=" . urlencode("Email inválido"));
            exit();
        }

        $stmt = $pdo->prepare("SELECT id, password, nome, email, username, photo, id_parceiro, type 
                               FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

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
                'csrf_token' => bin2hex(random_bytes(32)),
                'login_time' => time()
            ];

            // Cookie "Lembrar-me"
            if (isset($_POST['rememberMe']) && $_POST['rememberMe'] === 'on') {
                setcookie('parceiro_id', $user['id_parceiro'], time() + (30 * 24 * 60 * 60), '/', '', true, true);
            }

            // Registra login bem-sucedido
            $stmt = $pdo->prepare("
                INSERT INTO historic_login 
                (user_id, login_time, ip, user_agent, success, email_attempt)
                VALUES (?, ?, ?, ?, 1, ?)
            ");
            $stmt->execute([
                $user['id'],
                $dataHora,
                $ip,
                $userAgent,
                $email
            ]);

            header("Location: /dashboard");
            exit();
        }

        // Login falhou - registra tentativa
        $stmt = $pdo->prepare("
            INSERT INTO historic_login 
            (login_time, ip, user_agent, success, email_attempt)
            VALUES (?, ?, ?, 0, ?)
        ");
        $stmt->execute([
            $dataHora,
            $ip,
            $userAgent,
            $email
        ]);

        // Delay para dificultar brute force
        sleep(random_int(1, 3));
        header("Location: /login?erro=" . urlencode("Email ou senha incorretos"));
        exit();

    } catch (PDOException $e) {
        error_log("PDO Error: " . $e->getMessage());
        header("Location: /login?erro=" . urlencode("Erro no sistema. Tente novamente."));
        exit();
    } catch (Exception $e) {
        error_log("General Error: " . $e->getMessage());
        header("Location: /login?erro=" . urlencode("Erro inesperado. Contate o suporte."));
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="Painel de Parceiros Waze Brasil - Acesso Seguro">
    <meta name="author" content="Waze Brasil">
    <meta name="robots" content="noindex, nofollow">
    <title>Login - Portal de Parceiros Waze Brasil</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --waze-blue: #33CCFF;
            --waze-blue-dark: #2ab8e6;
            --waze-orange: #FF6633;
            --waze-orange-dark: #e65a2e;
            --dark-blue: #1A237E;
            --dark-blue-light: #283593;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, var(--dark-blue) 0%, var(--waze-blue) 100%);
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* Efeito de ondas animadas no fundo */
        body::before,
        body::after {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            opacity: 0.1;
            animation: float 20s infinite ease-in-out;
        }

        body::before {
            background: var(--waze-orange);
            top: -200px;
            left: -200px;
            animation-delay: 0s;
        }

        body::after {
            background: var(--waze-blue);
            bottom: -200px;
            right: -200px;
            animation-delay: 5s;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(50px, 50px) scale(1.1); }
        }

        .login-container {
            position: relative;
            z-index: 1;
        }

        .login-card {
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            background: #fff;
            backdrop-filter: blur(10px);
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .brand-section {
            background: linear-gradient(135deg, var(--waze-blue) 0%, var(--waze-orange) 100%);
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .brand-section::before {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            top: -100px;
            right: -100px;
        }

        .brand-logo {
            max-width: 240px;
            width: 100%;
            height: auto;
            transition: transform 0.4s ease;
            position: relative;
            z-index: 1;
            filter: drop-shadow(0 10px 20px rgba(0, 0, 0, 0.2));
        }

        .brand-logo:hover {
            transform: scale(1.05) rotate(2deg);
        }

        .brand-text {
            color: white;
            text-align: center;
            margin-top: 30px;
            position: relative;
            z-index: 1;
        }

        .brand-text h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .brand-text p {
            font-size: 14px;
            opacity: 0.95;
        }

        .form-section {
            padding: 50px 40px;
        }

        .form-header {
            text-align: center;
            margin-bottom: 35px;
        }

        .form-header h2 {
            color: var(--dark-blue);
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .form-header p {
            color: #666;
            font-size: 15px;
        }

        .alert-custom {
            background: linear-gradient(135deg, #fff5f5 0%, #ffe0e0 100%);
            border: none;
            border-left: 4px solid var(--waze-orange);
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 25px;
            animation: shake 0.5s ease;
            box-shadow: 0 4px 12px rgba(255, 102, 51, 0.1);
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }

        .alert-custom strong {
            color: var(--waze-orange-dark);
            display: block;
            margin-bottom: 5px;
        }

        .form-floating {
            margin-bottom: 20px;
            position: relative;
        }

        .form-floating .form-control {
            border: 2px solid #e8e8e8;
            border-radius: 12px;
            padding: 18px 20px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .form-floating .form-control:focus {
            border-color: var(--waze-blue);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(51, 204, 255, 0.1);
            outline: none;
        }

        .form-floating label {
            padding: 18px 20px;
            color: #666;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 20px;
            color: #666;
            padding: 5px;
            transition: color 0.3s ease;
            z-index: 10;
        }

        .password-toggle:hover {
            color: var(--waze-blue);
        }

        .form-check {
            margin-bottom: 25px;
        }

        .form-check-input {
            width: 20px;
            height: 20px;
            border: 2px solid #e8e8e8;
            margin-top: 0;
        }

        .form-check-input:checked {
            background-color: var(--waze-blue);
            border-color: var(--waze-blue);
        }

        .form-check-label {
            margin-left: 8px;
            color: #666;
            font-size: 14px;
        }

        .btn-waze {
            background: linear-gradient(135deg, var(--waze-blue) 0%, var(--waze-orange) 100%);
            color: white;
            padding: 16px 30px;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-waze::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .btn-waze:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(51, 204, 255, 0.4);
        }

        .btn-waze:hover::before {
            left: 100%;
        }

        .btn-waze:active {
            transform: translateY(0);
        }

        .btn-waze:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .link-forgot {
            color: var(--dark-blue);
            text-decoration: none;
            font-weight: 500;
            display: inline-block;
            position: relative;
            transition: color 0.3s ease;
        }

        .link-forgot::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--waze-orange);
            transition: width 0.3s ease;
        }

        .link-forgot:hover {
            color: var(--waze-orange);
        }

        .link-forgot:hover::after {
            width: 100%;
        }

        .footer-links {
            text-align: center;
            margin-top: 30px;
        }

        .footer-links p {
            color: white;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .footer-links a {
            color: white;
            font-weight: 600;
            text-decoration: none;
            transition: opacity 0.3s ease;
        }

        .footer-links a:hover {
            opacity: 0.8;
            text-decoration: underline;
        }

        .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-left: 10px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .btn-waze.loading .loading-spinner {
            display: inline-block;
        }

        @media (max-width: 768px) {
            .brand-section {
                padding: 40px 30px;
            }

            .form-section {
                padding: 40px 30px;
            }

            .brand-logo {
                max-width: 180px;
            }

            .form-header h2 {
                font-size: 24px;
            }

            .brand-text h3 {
                font-size: 20px;
            }
        }

        @media (max-width: 576px) {
            body {
                padding: 10px;
            }

            .login-card {
                border-radius: 16px;
            }

            .form-section {
                padding: 30px 20px;
            }

            .brand-section {
                padding: 30px 20px;
            }
        }
    </style>
</head>

<body>
    <div class="container login-container">
        <div class="row justify-content-center">
            <div class="col-lg-10 col-xl-9">
                <div class="login-card">
                    <div class="row g-0">
                        <!-- Seção da Marca -->
                        <div class="col-md-5 brand-section">
                            <img src="/img/logologin.webp" alt="Waze Brasil" class="brand-logo">
                            <div class="brand-text">
                                <h3>Bem-vindo!</h3>
                                <p>Portal de gestão e colaboração para parceiros Waze Brasil</p>
                            </div>
                        </div>

                        <!-- Seção do Formulário -->
                        <div class="col-md-7 form-section">
                            <div class="form-header">
                                <h2>🔐 Acesso de Parceiros</h2>
                                <p>Entre com suas credenciais</p>
                            </div>

                            <!-- Mensagem de Erro -->
                            <?php if (isset($_GET['erro'])): ?>
                            <div class="alert-custom" role="alert">
                                <strong>⚠️ Atenção!</strong>
                                <?php echo htmlspecialchars($_GET['erro'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php endif; ?>

                            <!-- Mensagem de Sucesso -->
                            <?php if (isset($_GET['success'])): ?>
                            <div class="alert alert-success" role="alert">
                                <strong>✓ Sucesso!</strong>
                                <?php echo htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php endif; ?>

                            <!-- Formulário de Login -->
                            <form id="loginForm" action="/login" method="POST" novalidate>
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                                <div class="form-floating">
                                    <input type="email" class="form-control" id="email" name="email"
                                        placeholder="Email" required autocomplete="email">
                                    <label for="email">📧 Email institucional</label>
                                </div>

                                <div class="form-floating" style="position: relative;">
                                    <input type="password" class="form-control" id="password" name="password"
                                        placeholder="Senha" required minlength="6" autocomplete="current-password">
                                    <label for="password">🔒 Senha de acesso</label>
                                    <button type="button" class="password-toggle" id="togglePassword" aria-label="Mostrar senha">
                                        👁️
                                    </button>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="rememberMe" name="rememberMe">
                                    <label class="form-check-label" for="rememberMe">
                                        Manter conectado por 30 dias
                                    </label>
                                </div>

                                <button type="submit" class="btn btn-waze w-100 mb-4" id="submitBtn">
                                    <span>Acessar Plataforma</span>
                                    <span class="loading-spinner"></span>
                                </button>

                                <div class="text-center">
                                    <a href="/forgot-password.html" class="link-forgot">
                                        Esqueceu sua senha?
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="footer-links">
                    <p>
                        Novo parceiro? 
                        <a href="mailto:parceiros@wazebrasil.com?subject=Solicitação de Credenciais">
                            Solicite suas credenciais
                        </a>
                    </p>
                    <p>
                        🆘 Suporte técnico: 
                        <a href="mailto:parceiros@wazebrasil.com">
                            parceiros@wazebrasil.com
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        'use strict';

        const loginForm = document.getElementById('loginForm');
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');
        const submitBtn = document.getElementById('submitBtn');
        const togglePassword = document.getElementById('togglePassword');

        // Toggle mostrar/ocultar senha
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = type;
            this.textContent = type === 'password' ? '👁️' : '🙈';
        });

        // Validação em tempo real
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        emailInput.addEventListener('blur', function() {
            if (this.value && !validateEmail(this.value)) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });

        passwordInput.addEventListener('blur', function() {
            if (this.value && this.value.length < 6) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });

        // Prevenir submit múltiplo e adicionar loading
        loginForm.addEventListener('submit', function(e) {
            const email = emailInput.value.trim();
            const password = passwordInput.value;

            // Validações
            if (!email || !password) {
                e.preventDefault();
                alert('Por favor, preencha todos os campos');
                return false;
            }

            if (!validateEmail(email)) {
                e.preventDefault();
                alert('Por favor, insira um email válido');
                emailInput.focus();
                return false;
            }

            if (password.length < 6) {
                e.preventDefault();
                alert('A senha deve ter pelo menos 6 caracteres');
                passwordInput.focus();
                return false;
            }

            // Adiciona loading no botão
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
            submitBtn.querySelector('span').textContent = 'Autenticando...';
        });

        // Remove loading se voltar para a página (browser back)
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
                submitBtn.querySelector('span').textContent = 'Acessar Plataforma';
            }
        });

        // Auto-foco no campo email
        window.addEventListener('load', function() {
            emailInput.focus();
        });
    </script>
</body>

</html>