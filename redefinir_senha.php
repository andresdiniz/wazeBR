<?php
require_once __DIR__ . '/config/configbd.php';

// 1. Validação Básica de Dados Recebidos
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['email'], $_POST['password1'], $_POST['password2'], $_POST['token'])) {
    die("Acesso inválido.");
}

$email = $_POST['email'];
$token = $_POST['token'];
$password1 = $_POST['password1'];
$password2 = $_POST['password2'];

// **Validação Lado do Servidor (Fallback)**
if (strlen($password1) < 8 || !preg_match('/[A-Z]/', $password1) || !preg_match('/[0-9]/', $password1) || !preg_match('/[\W_]/', $password1) || $password1 !== $password2) {
    die("A senha submetida não atende aos requisitos de segurança.");
}

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction(); // 👈 Inicia a transação para proteger o uso do token

    // 2. RE-VALIDAÇÃO E BLOQUEIO ATÔMICO
    // Verifica se o token ainda é válido E não foi usado (used = 0) E BLOQUEIA a linha
    $stmtCheck = $pdo->prepare("
        SELECT email 
        FROM recuperar_senha 
        WHERE token = :token 
        AND valid >= NOW() 
        AND used = 0 
        FOR UPDATE
    ");
    $stmtCheck->bindParam(':token', $token, PDO::PARAM_STR);
    $stmtCheck->execute();

    if ($stmtCheck->rowCount() > 0) {
        $dbEmail = $stmtCheck->fetchColumn();
        
        // Confirma que o email vindo do formulário POST bate com o email do token
        if ($dbEmail !== $email) {
             $pdo->rollBack();
             die("Erro de segurança: Email associado ao token não corresponde.");
        }

        // 3. ATUALIZAÇÃO: Marca como usado E armazena a nova senha (usando hash)
        $newHashedPassword = password_hash($password1, PASSWORD_DEFAULT);
        
        $stmtUpdateUser = $pdo->prepare("
            UPDATE usuarios 
            SET senha = :senha_hash 
            WHERE email = :email
        ");
        $stmtUpdateUser->bindParam(':senha_hash', $newHashedPassword);
        $stmtUpdateUser->bindParam(':email', $email);
        $stmtUpdateUser->execute();

        // 4. CONSUMO DO TOKEN (Marcando como usado)
        $stmtInvalidate = $pdo->prepare("
            UPDATE recuperar_senha 
            SET used = 1, valid = NOW() 
            WHERE token = :token
        ");
        $stmtInvalidate->bindParam(':token', $token, PDO::PARAM_STR);
        $stmtInvalidate->execute();

        $pdo->commit(); // **SUCESSO**: Todas as alterações são salvas

        // Redirecionamento para sucesso
        echo "<script>
            alert('Senha redefinida com sucesso! Você será redirecionado para o login.');
            window.location.href = 'login.html';
        </script>";
        
    } else {
        $pdo->rollBack(); // Falhou a revalidação (token expirado ou usado)
        die("O link de redefinição expirou ou já foi utilizado. Por favor, solicite um novo.");
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Erro fatal ao salvar a senha: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waze Portal - Redefinição de Senha</title>
    <style>
        /* Estilos mantidos da versão anterior (Layout melhorado) */
        body, h2, form, ul, li, input, button {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #e9ecef; 
            color: #343a40;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        form {
            background: #fff;
            padding: 35px; 
            border-radius: 12px; 
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15); 
            width: 100%;
            max-width: 450px; 
        }

        h2 {
            text-align: center;
            margin-bottom: 10px; 
            color: #28a745; 
        }
        
        .info-box {
            text-align: center;
            margin-bottom: 20px;
            padding: 10px;
            border: 1px solid #ffc107; 
            background-color: #fff3cd;
            border-radius: 5px;
            color: #856404;
            font-size: 14px;
        }

        label {
            display: block;
            margin-bottom: 8px; 
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 12px; 
            margin-bottom: 15px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 16px;
        }

        input:focus {
            border-color: #28a745;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
        }

        ul {
            list-style: none;
            padding-left: 0;
            margin-bottom: 25px; 
        }

        li {
            font-size: 14px;
            padding-left: 20px; 
            position: relative;
            margin: 7px 0;
        }

        .error::before {
            content: '✗'; 
            position: absolute;
            left: 0;
            color: #dc3545;
            font-weight: bold;
        }

        .success::before {
            content: '✓'; 
            position: absolute;
            left: 0;
            color: #28a745;
            font-weight: bold;
        }
        
        .error, .success {
            color: #343a40; 
        }

        button {
            width: 100%;
            padding: 12px;
            background: #28a745;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 17px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        button:hover:not(:disabled) {
            background: #218838;
        }

        button:disabled {
            background: #adb5bd;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <form action="save_password.php" method="POST" id="passwordForm">
        <h2>Redefinir Senha</h2>
        
        <div class="info-box">
            Você está definindo uma nova senha para: **<?php echo $maskedEmail; ?>**
        </div>
        
        <label for="password1">Nova Senha:</label>
        <input type="password" id="password1" name="password1" placeholder="Mínimo 8 caracteres" required>
        
        <label for="password2">Confirmar Nova Senha:</label>
        <input type="password" id="password2" name="password2" placeholder="Confirme a nova senha" required>

        <input type="hidden" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="token" value="<?php echo $token; ?>">
        
        <ul id="requirements">
            <li id="length">Mínimo de 8 caracteres</li>
            <li id="uppercase">Pelo menos uma letra maiúscula</li>
            <li id="number">Pelo menos um número</li>
            <li id="symbol">Pelo menos um símbolo/caractere especial</li>
            <li id="match">As senhas devem ser iguais</li>
        </ul>
        
        <button type="submit" id="submitButton" disabled>Salvar Nova Senha</button>
    </form>

    <script>
        // Script de validação de senha (Mantido)
        const password1 = document.getElementById('password1');
        const password2 = document.getElementById('password2');
        const requirements = {
            length: document.getElementById('length'),
            uppercase: document.getElementById('uppercase'),
            number: document.getElementById('number'),
            symbol: document.getElementById('symbol'),
            match: document.getElementById('match'),
        };
        const submitButton = document.getElementById('submitButton');

        function validatePasswords() {
            const pwd1 = password1.value;
            const pwd2 = password2.value;

            requirements.length.className = pwd1.length >= 8 ? 'success' : 'error';
            requirements.uppercase.className = /[A-Z]/.test(pwd1) ? 'success' : 'error';
            requirements.number.className = /[0-9]/.test(pwd1) ? 'success' : 'error';
            requirements.symbol.className = /[\W_]/.test(pwd1) ? 'success' : 'error';
            requirements.match.className = pwd1 === pwd2 ? 'success' : 'error';

            const allValid = Object.values(requirements).every(req => req.className === 'success');
            
            submitButton.disabled = !allValid;
        }

        password1.addEventListener('input', validatePasswords);
        password2.addEventListener('input', validatePasswords);
        
        validatePasswords();
    </script>
</body>
</html>