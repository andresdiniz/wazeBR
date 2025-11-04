<?php
session_start();

require_once __DIR__ . '/config/configbd.php';

// Headers de segurança
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self' 'unsafe-inline'");

// Função para prevenir timing attacks
function hashEquals($a, $b) {
    if (function_exists('hash_equals')) {
        return hash_equals($a, $b);
    }
    if (strlen($a) !== strlen($b)) {
        return false;
    }
    $result = 0;
    for ($i = 0; $i < strlen($a); $i++) {
        $result |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $result === 0;
}

// Rate limiting simples (pode melhorar com Redis/Memcached)
$ip = $_SERVER['REMOTE_ADDR'];
$rateKey = 'rate_limit_' . md5($ip);

if (!isset($_SESSION[$rateKey])) {
    $_SESSION[$rateKey] = ['count' => 0, 'time' => time()];
}

// Reset contador após 15 minutos
if (time() - $_SESSION[$rateKey]['time'] > 900) {
    $_SESSION[$rateKey] = ['count' => 0, 'time' => time()];
}

// Máximo 5 tentativas em 15 minutos
if ($_SESSION[$rateKey]['count'] >= 5) {
    http_response_code(429);
    die("<script>
        alert('Muitas tentativas. Aguarde 15 minutos e tente novamente.');
        window.location.href = 'login.html';
    </script>");
}

$_SESSION[$rateKey]['count']++;

// Verifica se o parâmetro 'token' foi enviado
if (!isset($_GET['token']) || empty($_GET['token'])) {
    error_log("Token não fornecido - IP: " . $ip);
    echo "<script>
        alert('Token não fornecido ou inválido. Você será redirecionado para a página de login.');
        window.location.href = 'login.html';
    </script>";
    exit;
}

$token = $_GET['token'];

// Validação básica do formato do token (assumindo token hex de 64 caracteres)
if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
    error_log("Formato de token inválido - IP: " . $ip);
    echo "<script>
        alert('Token inválido. Você será redirecionado para a página de login.');
        window.location.href = 'login.html';
    </script>";
    exit;
}

try {
    $pdo = Database::getConnection();

    // Prepara a consulta
    $stmt = $pdo->prepare("
        SELECT email, token
        FROM recuperar_senha 
        WHERE token = :token 
        AND valid >= NOW() 
        AND used != 2
        LIMIT 1
    ");
    $stmt->bindParam(':token', $token, PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Usa hash_equals para prevenir timing attacks
        if (hashEquals($result['token'], $token)) {
            $email = $result['email'];
            
            // Marca o token como usado
            $stmtUpdate = $pdo->prepare("UPDATE recuperar_senha SET used = 2 WHERE token = :token");
            $stmtUpdate->bindParam(':token', $token, PDO::PARAM_STR);
            $stmtUpdate->execute();
            
            // Gera token CSRF
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            $csrfToken = $_SESSION['csrf_token'];
            
        } else {
            throw new Exception("Token mismatch");
        }
    } else {
        error_log("Token inválido/expirado - IP: " . $ip);
        echo "<script>
            alert('Token inválido, expirado ou já utilizado. Você será redirecionado para a página de login.');
            window.location.href = 'login.html';
        </script>";
        exit;
    }
} catch (Exception $e) {
    error_log("Erro na validação do token: " . $e->getMessage());
    die("<script>
        alert('Erro ao processar sua solicitação. Tente novamente mais tarde.');
        window.location.href = 'login.html';
    </script>");
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Redefinição de Senha - Portal Waze</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            line-height: 1.6;
        }

        .container {
            background: #fff;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 440px;
            animation: slideIn 0.4s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h2 {
            color: #333;
            font-size: 28px;
            margin-bottom: 8px;
        }

        .header p {
            color: #666;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .input-wrapper {
            position: relative;
        }

        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        input:focus {
            border-color: #667eea;
            outline: none;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 5px;
            color: #666;
            font-size: 18px;
            transition: color 0.3s ease;
        }

        .toggle-password:hover {
            color: #667eea;
        }

        .strength-meter {
            margin-top: 10px;
            height: 4px;
            background: #e0e0e0;
            border-radius: 2px;
            overflow: hidden;
        }

        .strength-meter-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .strength-weak { 
            background: #f44336; 
            width: 33%;
        }
        
        .strength-medium { 
            background: #ff9800; 
            width: 66%;
        }
        
        .strength-strong { 
            background: #4CAF50; 
            width: 100%;
        }

        .strength-text {
            font-size: 12px;
            margin-top: 5px;
            font-weight: 500;
        }

        .requirements {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
        }

        .requirements-title {
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .requirements ul {
            list-style: none;
        }

        .requirements li {
            font-size: 13px;
            margin: 8px 0;
            padding-left: 25px;
            position: relative;
            transition: all 0.3s ease;
        }

        .requirements li::before {
            content: '✕';
            position: absolute;
            left: 0;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .requirements li.error {
            color: #f44336;
        }

        .requirements li.error::before {
            content: '✕';
            color: #f44336;
        }

        .requirements li.success {
            color: #4CAF50;
        }

        .requirements li.success::before {
            content: '✓';
            color: #4CAF50;
        }

        button[type="submit"] {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        button[type="submit"]:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        button[type="submit"]:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .security-note {
            margin-top: 20px;
            padding: 12px;
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            border-radius: 4px;
            font-size: 12px;
            color: #1976d2;
        }

        @media (max-width: 480px) {
            .container {
                padding: 25px;
            }

            .header h2 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>🔐 Criar Nova Senha</h2>
            <p>Escolha uma senha forte e segura</p>
        </div>

        <form action="save_password.php" method="POST" id="passwordForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
            
            <div class="form-group">
                <label for="password1">Nova Senha</label>
                <div class="input-wrapper">
                    <input 
                        type="password" 
                        id="password1" 
                        name="password1" 
                        placeholder="Digite sua senha"
                        autocomplete="new-password"
                        required
                    >
                    <button type="button" class="toggle-password" data-target="password1" aria-label="Mostrar senha">
                        👁️
                    </button>
                </div>
                <div class="strength-meter">
                    <div class="strength-meter-fill" id="strengthMeter"></div>
                </div>
                <div class="strength-text" id="strengthText"></div>
            </div>

            <div class="form-group">
                <label for="password2">Confirmar Senha</label>
                <div class="input-wrapper">
                    <input 
                        type="password" 
                        id="password2" 
                        name="password2" 
                        placeholder="Confirme sua senha"
                        autocomplete="new-password"
                        required
                    >
                    <button type="button" class="toggle-password" data-target="password2" aria-label="Mostrar senha">
                        👁️
                    </button>
                </div>
            </div>

            <div class="requirements">
                <div class="requirements-title">Requisitos da senha:</div>
                <ul id="requirements">
                    <li id="length" class="error">Mínimo de 8 caracteres</li>
                    <li id="uppercase" class="error">Pelo menos uma letra maiúscula</li>
                    <li id="lowercase" class="error">Pelo menos uma letra minúscula</li>
                    <li id="number" class="error">Pelo menos um número</li>
                    <li id="symbol" class="error">Pelo menos um símbolo (!@#$%^&*)</li>
                    <li id="match" class="error">As senhas devem coincidir</li>
                </ul>
            </div>
            
            <button type="submit" id="submitButton" disabled>Salvar Senha</button>

            <div class="security-note">
                <strong>🛡️ Dica de segurança:</strong> Use uma combinação única de letras, números e símbolos. Evite informações pessoais óbvias.
            </div>
        </form>
    </div>

    <script>
        'use strict';

        const password1 = document.getElementById('password1');
        const password2 = document.getElementById('password2');
        const strengthMeter = document.getElementById('strengthMeter');
        const strengthText = document.getElementById('strengthText');
        const requirements = {
            length: document.getElementById('length'),
            uppercase: document.getElementById('uppercase'),
            lowercase: document.getElementById('lowercase'),
            number: document.getElementById('number'),
            symbol: document.getElementById('symbol'),
            match: document.getElementById('match'),
        };
        const submitButton = document.getElementById('submitButton');
        const form = document.getElementById('passwordForm');

        // Toggle visibilidade da senha
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const input = document.getElementById(targetId);
                
                if (input.type === 'password') {
                    input.type = 'text';
                    this.textContent = '🙈';
                } else {
                    input.type = 'password';
                    this.textContent = '👁️';
                }
            });
        });

        // Calcula força da senha
        function calculatePasswordStrength(password) {
            let strength = 0;
            
            if (password.length >= 8) strength += 1;
            if (password.length >= 12) strength += 1;
            if (/[a-z]/.test(password)) strength += 1;
            if (/[A-Z]/.test(password)) strength += 1;
            if (/[0-9]/.test(password)) strength += 1;
            if (/[\W_]/.test(password)) strength += 1;
            if (password.length >= 16) strength += 1;

            return strength;
        }

        function validatePasswords() {
            const pwd1 = password1.value;
            const pwd2 = password2.value;

            // Verifica requisitos individuais
            requirements.length.className = pwd1.length >= 8 ? 'success' : 'error';
            requirements.uppercase.className = /[A-Z]/.test(pwd1) ? 'success' : 'error';
            requirements.lowercase.className = /[a-z]/.test(pwd1) ? 'success' : 'error';
            requirements.number.className = /[0-9]/.test(pwd1) ? 'success' : 'error';
            requirements.symbol.className = /[\W_]/.test(pwd1) ? 'success' : 'error';
            requirements.match.className = (pwd1 === pwd2 && pwd1.length > 0) ? 'success' : 'error';

            // Atualiza medidor de força
            const strength = calculatePasswordStrength(pwd1);
            strengthMeter.className = 'strength-meter-fill';
            
            if (pwd1.length === 0) {
                strengthMeter.style.width = '0%';
                strengthText.textContent = '';
            } else if (strength <= 3) {
                strengthMeter.classList.add('strength-weak');
                strengthText.textContent = 'Fraca';
                strengthText.style.color = '#f44336';
            } else if (strength <= 5) {
                strengthMeter.classList.add('strength-medium');
                strengthText.textContent = 'Média';
                strengthText.style.color = '#ff9800';
            } else {
                strengthMeter.classList.add('strength-strong');
                strengthText.textContent = 'Forte';
                strengthText.style.color = '#4CAF50';
            }

            // Habilita botão apenas se tudo estiver válido
            const allValid = Object.values(requirements).every(req => req.className === 'success');
            submitButton.disabled = !allValid;
        }

        // Previne colar na confirmação de senha (boa prática)
        password2.addEventListener('paste', function(e) {
            e.preventDefault();
            return false;
        });

        // Validação em tempo real
        password1.addEventListener('input', validatePasswords);
        password2.addEventListener('input', validatePasswords);

        // Previne submit múltiplo
        form.addEventListener('submit', function(e) {
            if (submitButton.disabled) {
                e.preventDefault();
                return false;
            }
            
            submitButton.disabled = true;
            submitButton.textContent = 'Salvando...';
        });

        // Validação inicial
        validatePasswords();
    </script>
</body>
</html>