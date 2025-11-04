<?php
session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Strict',
    'lifetime' => 0
]);

session_start();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
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
            display: none;
        }

        .alert-custom.show {
            display: block;
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

        .alert-success-custom {
            background: linear-gradient(135deg, #f0fff4 0%, #c6f6d5 100%);
            border-left: 4px solid #48bb78;
            color: #22543d;
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

        .form-floating .form-control.is-invalid {
            border-color: #dc3545;
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

        .invalid-feedback {
            display: none;
            font-size: 13px;
            color: #dc3545;
            margin-top: 5px;
        }

        .form-control.is-invalid ~ .invalid-feedback {
            display: block;
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

        .btn-waze:hover:not(:disabled) {
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
            opacity: 0.7;
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

        .btn-waze.loading .btn-text {
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
                            <img src="/img/login-img.jpeg" alt="Waze Brasil" class="brand-logo">
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
                            <div class="alert-custom" id="errorMessage" role="alert">
                                <strong>⚠️ Atenção!</strong>
                                <span id="errorText"></span>
                            </div>

                            <!-- Mensagem de Sucesso -->
                            <div class="alert-custom alert-success-custom" id="successMessage" role="alert">
                                <strong>✓ Sucesso!</strong>
                                <span id="successText"></span>
                            </div>

                            <!-- Formulário de Login -->
                            <form id="loginForm" action="login.php" method="POST" novalidate>
                                <input type="hidden" name="csrf_token" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <div class="form-floating">
                                    <input type="email" 
                                           class="form-control" 
                                           id="email" 
                                           name="email"
                                           placeholder="Email" 
                                           required 
                                           autocomplete="email">
                                    <label for="email">📧 Email institucional</label>
                                    <div class="invalid-feedback">
                                        Por favor, insira um email válido
                                    </div>
                                </div>

                                <div class="form-floating" style="position: relative;">
                                    <input type="password" 
                                           class="form-control" 
                                           id="password" 
                                           name="password"
                                           placeholder="Senha" 
                                           required 
                                           minlength="6" 
                                           autocomplete="current-password">
                                    <label for="password">🔒 Senha de acesso</label>
                                    <button type="button" 
                                            class="password-toggle" 
                                            id="togglePassword" 
                                            aria-label="Mostrar senha">
                                        👁️
                                    </button>
                                    <div class="invalid-feedback">
                                        A senha deve ter pelo menos 6 caracteres
                                    </div>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           id="rememberMe" 
                                           name="rememberMe">
                                    <label class="form-check-label" for="rememberMe">
                                        Manter conectado por 30 dias
                                    </label>
                                </div>

                                <button type="submit" class="btn btn-waze w-100 mb-4" id="submitBtn">
                                    <span class="btn-text">Acessar Plataforma</span>
                                    <span class="loading-spinner"></span>
                                </button>

                                <div class="text-center">
                                    <a href="forgot-password.html" class="link-forgot">
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

        // Elementos do DOM
        const loginForm = document.getElementById('loginForm');
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');
        const submitBtn = document.getElementById('submitBtn');
        const togglePassword = document.getElementById('togglePassword');
        const errorMessage = document.getElementById('errorMessage');
        const errorText = document.getElementById('errorText');
        const successMessage = document.getElementById('successMessage');
        const successText = document.getElementById('successText');

        /* Geração do Token CSRF
        async function loadCSRFToken() {
            try {
                const response = await fetch('get_csrf.php');
                const data = await response.json();
                
                if (data.csrf_token) {
                    document.getElementById('csrfToken').value = data.csrf_token;
                } else {
                    console.error('Token CSRF não recebido');
                }
            } catch (error) {
                console.error('Erro ao carregar token CSRF:', error);
            }
        }*/

        // Inicialização
        document.addEventListener('DOMContentLoaded', () => {
            // Carrega o token CSRF do servidor
            //loadCSRFToken();
            
            // Corrige a action do form
            loginForm.action = 'login.php';

            // Auto-foco no email
            emailInput.focus();
        });

        // Toggle mostrar/ocultar senha
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = type;
            this.textContent = type === 'password' ? '👁️' : '🙈';
        });

        // Validação de email
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        // Validação em tempo real - Email
        emailInput.addEventListener('blur', function() {
            if (this.value && !validateEmail(this.value)) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });

        emailInput.addEventListener('input', function() {
            if (this.classList.contains('is-invalid') && validateEmail(this.value)) {
                this.classList.remove('is-invalid');
            }
        });

        // Validação em tempo real - Senha
        passwordInput.addEventListener('blur', function() {
            if (this.value && this.value.length < 6) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });

        passwordInput.addEventListener('input', function() {
            if (this.classList.contains('is-invalid') && this.value.length >= 6) {
                this.classList.remove('is-invalid');
            }
        });

        // Submit do formulário
        loginForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const email = emailInput.value.trim();
            const password = passwordInput.value;
            let hasError = false;

            // Limpa erros anteriores
            emailInput.classList.remove('is-invalid');
            passwordInput.classList.remove('is-invalid');

            // Validação do email
            if (!email) {
                emailInput.classList.add('is-invalid');
                hasError = true;
            } else if (!validateEmail(email)) {
                emailInput.classList.add('is-invalid');
                hasError = true;
            }

            // Validação da senha
            if (!password) {
                passwordInput.classList.add('is-invalid');
                hasError = true;
            } else if (password.length < 6) {
                passwordInput.classList.add('is-invalid');
                hasError = true;
            }

            // Se houver erros, não submete
            if (hasError) {
                showError('Por favor, corrija os campos destacados');
                return false;
            }

            // Adiciona loading
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
            submitBtn.querySelector('.btn-text').textContent = 'Autenticando...';

            // Submete o formulário
            this.submit();
        });

        // Função para mostrar erro
        function showError(message) {
            errorText.textContent = message;
            errorMessage.classList.add('show');
            successMessage.classList.remove('show');

            // Auto-esconder após 5 segundos
            setTimeout(() => {
                errorMessage.classList.remove('show');
            }, 5000);
        }

        // Função para mostrar sucesso
        function showSuccess(message) {
            successText.textContent = message;
            successMessage.classList.add('show');
            errorMessage.classList.remove('show');

            // Auto-esconder após 5 segundos
            setTimeout(() => {
                successMessage.classList.remove('show');
            }, 5000);
        }

        // Tratamento de mensagens via URL
        const urlParams = new URLSearchParams(window.location.search);
        const error = urlParams.get('erro');
        const success = urlParams.get('success');

        if (error) {
            showError(decodeURIComponent(error).replace(/\+/g, ' '));
        }

        if (success) {
            showSuccess(decodeURIComponent(success).replace(/\+/g, ' '));
        }

        // Remove loading se voltar para a página
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || performance.navigation.type === 2) {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
                submitBtn.querySelector('.btn-text').textContent = 'Acessar Plataforma';
            }
        });

        // Previne envio acidental ao pressionar Enter em campos
        emailInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                passwordInput.focus();
            }
        });
    </script>
</body>

</html>