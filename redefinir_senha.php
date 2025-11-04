<?php
// save_password.php
require_once __DIR__ . '/config/configbd.php';

// Processa APENAS requisições POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.html');
    exit;
}

// Valida se todos os campos obrigatórios foram enviados
if (!isset($_POST['password1'], $_POST['password2'], $_POST['email'], $_POST['token'])) {
    die("Dados incompletos. Por favor, tente novamente.");
}

$password1 = $_POST['password1'];
$password2 = $_POST['password2'];
$email = $_POST['email'];
$token = $_POST['token'];

// Validação das senhas
if ($password1 !== $password2) {
    die("As senhas não coincidem. Por favor, volte e tente novamente.");
}

// Validação de força da senha (redundância com frontend)
if (strlen($password1) < 8 || 
    !preg_match('/[A-Z]/', $password1) || 
    !preg_match('/[0-9]/', $password1) || 
    !preg_match('/[\W_]/', $password1)) {
    die("A senha não atende aos requisitos mínimos de segurança.");
}

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();

    // PASSO 1: Verifica novamente se o token ainda é válido e NÃO foi usado
    $stmtCheck = $pdo->prepare("
        SELECT email 
        FROM recuperar_senha 
        WHERE token = :token 
        AND email = :email 
        AND valid >= NOW() 
        AND used = 0
        FOR UPDATE
    ");
    $stmtCheck->bindParam(':token', $token, PDO::PARAM_STR);
    $stmtCheck->bindParam(':email', $email, PDO::PARAM_STR);
    $stmtCheck->execute();

    if ($stmtCheck->rowCount() === 0) {
        $pdo->rollBack();
        echo "<!DOCTYPE html>
        <html lang='pt-br'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Token Inválido</title>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background-color: #e9ecef;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                }
                .error-box {
                    background: #fff;
                    padding: 40px;
                    border-radius: 12px;
                    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
                    text-align: center;
                    max-width: 400px;
                }
                .error-box h2 {
                    color: #dc3545;
                    margin-bottom: 20px;
                }
                .error-box p {
                    color: #343a40;
                    margin-bottom: 25px;
                    line-height: 1.6;
                }
                .error-box a {
                    display: inline-block;
                    padding: 12px 30px;
                    background: #007bff;
                    color: #fff;
                    text-decoration: none;
                    border-radius: 6px;
                    font-weight: bold;
                    transition: background 0.3s;
                }
                .error-box a:hover {
                    background: #0056b3;
                }
            </style>
        </head>
        <body>
            <div class='error-box'>
                <h2>⚠️ Token Inválido ou Expirado</h2>
                <p>O link de redefinição já foi usado ou não é mais válido.</p>
                <p>Por favor, solicite um novo link de recuperação.</p>
                <a href='login.html'>Voltar ao Login</a>
            </div>
        </body>
        </html>";
        exit;
    }

    // PASSO 2: Atualiza a senha do usuário
    $hashedPassword = password_hash($password1, PASSWORD_DEFAULT);
    $stmtUpdate = $pdo->prepare("
        UPDATE users 
        SET password = :password 
        WHERE email = :email
    ");
    $stmtUpdate->bindParam(':password', $hashedPassword, PDO::PARAM_STR);
    $stmtUpdate->bindParam(':email', $email, PDO::PARAM_STR);
    $stmtUpdate->execute();

    // PASSO 3: Marca o token como USADO (aqui é onde deve ser marcado!)
    $stmtMarkUsed = $pdo->prepare("
        UPDATE recuperar_senha 
        SET used = 1 
        WHERE token = :token
    ");
    $stmtMarkUsed->bindParam(':token', $token, PDO::PARAM_STR);
    $stmtMarkUsed->execute();

    // Confirma todas as operações
    $pdo->commit();

    // Sucesso! Redireciona para o login
    echo "<!DOCTYPE html>
    <html lang='pt-br'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Senha Redefinida com Sucesso</title>
        <style>
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background-color: #e9ecef;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
            }
            .success-box {
                background: #fff;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
                text-align: center;
                max-width: 400px;
            }
            .success-box h2 {
                color: #28a745;
                margin-bottom: 20px;
            }
            .success-box p {
                color: #343a40;
                margin-bottom: 25px;
                line-height: 1.6;
            }
            .success-box a {
                display: inline-block;
                padding: 12px 30px;
                background: #28a745;
                color: #fff;
                text-decoration: none;
                border-radius: 6px;
                font-weight: bold;
                transition: background 0.3s;
            }
            .success-box a:hover {
                background: #218838;
            }
        </style>
        <script>
            // Redireciona automaticamente após 3 segundos
            setTimeout(function() {
                window.location.href = 'login.html';
            }, 3000);
        </script>
    </head>
    <body>
        <div class='success-box'>
            <h2>✓ Senha Redefinida com Sucesso!</h2>
            <p>Sua senha foi alterada com sucesso.</p>
            <p>Você será redirecionado para a página de login em 3 segundos...</p>
            <a href='login.html'>Ir para o Login Agora</a>
        </div>
    </body>
    </html>";
    exit;

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Erro ao processar a solicitação: " . $e->getMessage());
}
?>