<?php

require_once __DIR__ . '/config/configbd.php';

// Verifica se o parâmetro 'token' foi enviado na URL
if (!isset($_GET['token']) || empty($_GET['token'])) {
    echo "token nao lido";
    echo "<script>
        alert('Token não fornecido ou inválido. Você será redirecionado para a página de login.');
        window.location.href = 'login.html';
    </script>";
    exit;
}

// Captura o valor do token
$token = htmlspecialchars($_GET['token'], ENT_QUOTES, 'UTF-8');

try {
    // Usa a conexão PDO existente
    $pdo = Database::getConnection();

    // Prepara a consulta para verificar o token
    $stmt = $pdo->prepare("
        SELECT email 
        FROM recuperar_senha 
        WHERE token = :token 
        AND valid >= NOW() 
        AND used = 1
    ");
    $stmt->bindParam(':token', $token, PDO::PARAM_STR);
    $stmt->execute();

    // Verifica se o token é válido
    if ($stmt->rowCount() > 0) {
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $email = $result['email']; // Captura o email associado ao token

        // Marca o token como usado no banco de dados
        $stmtUpdate = $pdo->prepare("UPDATE recuperar_senha SET used = 2 WHERE token = :token");
        $stmtUpdate->bindParam(':token', $token, PDO::PARAM_STR);
        $stmtUpdate->execute();

    } else {
        echo "<script>
            alert('Token inválido, expirado ou já utilizado. Você será redirecionado para a página de login.');
            window.location.href = 'login.html';
        </script>";
        exit;
    }
} catch (PDOException $e) {
    die("Erro ao conectar ou consultar o banco de dados: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waze Portal - Redefinição de Senha</title>
    <style>
        /* Reset básico */
        body, h2, form, ul, li, input, button {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Corpo e fonte */
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        /* Contêiner */
        form {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        /* Título */
        h2 {
            text-align: center;
            margin-bottom: 20px;
            color: #4CAF50;
        }

        /* Campos de entrada */
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }

        input:focus {
            border-color: #4CAF50;
            outline: none;
        }

        /* Lista de requisitos */
        ul {
            list-style: none;
            margin-bottom: 20px;
        }

        li {
            font-size: 14px;
            margin: 5px 0;
        }

        .error {
            color: red;
        }

        .success {
            color: green;
        }

        /* Botão */
        button {
            width: 100%;
            padding: 10px;
            background: #4CAF50;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        button:hover {
            background: #45a049;
        }

        button:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <form action="save_password.php" method="POST" id="passwordForm">
        <h2>Criar Senha</h2>
        <label for="password1">Senha:</label>
        <input type="password" id="password1" name="password1" placeholder="Digite sua senha">
        
        <label for="password2">Confirmar Senha:</label>
        <input type="password" id="password2" name="password2" placeholder="Confirme sua senha">

        <input type="hidden" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
        
        <ul id="requirements">
            <li id="length" class="error">Mínimo de 8 caracteres</li>
            <li id="uppercase" class="error">Pelo menos uma letra maiúscula</li>
            <li id="number" class="error">Pelo menos um número</li>
            <li id="symbol" class="error">Pelo menos um símbolo</li>
            <li id="match" class="error">As senhas devem ser iguais</li>
        </ul>
        
        <button type="submit" id="submitButton" disabled>Salvar Senha</button>
    </form>

    <script>
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

            // Verificar requisitos
            requirements.length.className = pwd1.length >= 8 ? 'success' : 'error';
            requirements.uppercase.className = /[A-Z]/.test(pwd1) ? 'success' : 'error';
            requirements.number.className = /[0-9]/.test(pwd1) ? 'success' : 'error';
            requirements.symbol.className = /[\W_]/.test(pwd1) ? 'success' : 'error';
            requirements.match.className = pwd1 === pwd2 ? 'success' : 'error';

            // Habilitar botão apenas se tudo estiver válido
            const allValid = Object.values(requirements).every(req => req.className === 'success');
            submitButton.disabled = !allValid;
        }

        password1.addEventListener('input', validatePasswords);
        password2.addEventListener('input', validatePasswords);
    </script>
</body>
</html>
