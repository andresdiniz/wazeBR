<?php
require_once __DIR__ . '/config/configbd.php';

// --- VALIDAÇÃO DE TOKEN (PHP - Lado do Servidor) ---

// 1. Verificação Inicial
if (!isset($_GET['token']) || empty($_GET['token'])) {
    // Redireciona imediatamente se o token não estiver presente
    echo "<script>
        alert('Token não fornecido ou inválido. Você será redirecionado para a página de login.');
        window.location.href = 'login.html';
    </script>";
    exit;
}

// Captura e sanitiza o valor do token
$token = htmlspecialchars($_GET['token'], ENT_QUOTES, 'UTF-8');
$email = null; // Variável para armazenar o email validado

try {
    $pdo = Database::getConnection();

    // **INÍCIO DA TRANSAÇÃO PARA ATOMICIDADE E SEGURANÇA**
    $pdo->beginTransaction(); 

    // 2. Consulta e Bloqueio da Linha (SELECT ... FOR UPDATE)
    // Verifica se o token existe, não expirou (valid >= NOW()) e não foi usado (used = 0)
    $stmt = $pdo->prepare("
        SELECT email 
        FROM recuperar_senha 
        WHERE token = :token 
        AND valid >= NOW() 
        AND used = 0
        FOR UPDATE
    ");
    $stmt->bindParam(':token', $token, PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $email = $result['email']; // Captura o email associado

        // 3. Marca o token como usado (Usando 1 para 'usado')
        $stmtUpdate = $pdo->prepare("
            UPDATE recuperar_senha 
            SET used = 1, valid = NOW() 
            WHERE token = :token
        ");
        $stmtUpdate->bindParam(':token', $token, PDO::PARAM_STR);
        $stmtUpdate->execute();
        
        $pdo->commit(); // **CONFIRMA** as duas operações (SELECT/UPDATE) como uma única unidade
        
        // Função auxiliar para mascarar o email para exibição (melhora UX)
        function maskEmail($email) {
            $parts = explode('@', $email);
            $name = $parts[0];
            $domain = $parts[1];
            $maskedName = substr($name, 0, 2) . str_repeat('*', strlen($name) - 2);
            return $maskedName . '@' . $domain;
        }
        $maskedEmail = maskEmail($email);

    } else {
        $pdo->rollBack(); // **DESFAZ** em caso de token inválido/expirado/já usado
        
        // Redireciona se o token não for válido
        echo "<script>
            alert('Token inválido, expirado ou já utilizado. Você será redirecionado para a página de login.');
            window.location.href = 'login.html';
        </script>";
        exit;
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack(); // Garante rollback em caso de erro de DB
    }
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
        /* Melhorias de Layout: Foco em Centralização e Clareza */
        body, h2, form, ul, li, input, button {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #e9ecef; /* Fundo mais suave */
            color: #343a40;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        form {
            background: #fff;
            padding: 35px; /* Mais padding */
            border-radius: 12px; /* Bordas mais arredondadas */
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15); /* Sombra mais destacada */
            width: 100%;
            max-width: 450px; /* Ligeiramente maior */
        }

        h2 {
            text-align: center;
            margin-bottom: 10px; /* Menos espaço antes do título */
            color: #28a745; /* Cor de sucesso/primária */
        }
        
        .info-box {
            text-align: center;
            margin-bottom: 20px;
            padding: 10px;
            border: 1px solid #ffc107; /* Cor de aviso/alerta */
            background-color: #fff3cd;
            border-radius: 5px;
            color: #856404;
            font-size: 14px;
        }

        label {
            display: block;
            margin-bottom: 8px; /* Mais espaço entre label e input */
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 12px; /* Padding maior */
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
            margin-bottom: 25px; /* Mais espaço antes do botão */
        }

        li {
            font-size: 14px;
            padding-left: 20px; /* Espaço para ícones de status */
            position: relative;
            margin: 7px 0;
        }

        .error::before {
            content: '✗'; /* Símbolo de erro */
            position: absolute;
            left: 0;
            color: #dc3545;
            font-weight: bold;
        }

        .success::before {
            content: '✓'; /* Símbolo de sucesso */
            position: absolute;
            left: 0;
            color: #28a745;
            font-weight: bold;
        }
        
        .error, .success {
            color: #343a40; /* Mantém a cor do texto neutra, o símbolo indica status */
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
        // --- VALIDAÇÃO CLIENT-SIDE (UX) ---
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

            // Verificação dos requisitos
            requirements.length.className = pwd1.length >= 8 ? 'success' : 'error';
            requirements.uppercase.className = /[A-Z]/.test(pwd1) ? 'success' : 'error';
            requirements.number.className = /[0-9]/.test(pwd1) ? 'success' : 'error';
            // Regex para símbolos: [\W_] significa qualquer coisa que não seja letra ou número (incluindo _)
            requirements.symbol.className = /[\W_]/.test(pwd1) ? 'success' : 'error';
            requirements.match.className = pwd1 === pwd2 ? 'success' : 'error';

            // Habilitar botão apenas se todos os requisitos forem 'success'
            const allValid = Object.values(requirements).every(req => req.className === 'success');
            
            submitButton.disabled = !allValid;
        }

        // Event Listeners
        password1.addEventListener('input', validatePasswords);
        password2.addEventListener('input', validatePasswords);
        
        // Executa uma vez ao carregar para checar se há algo digitado (improvável, mas seguro)
        validatePasswords();
    </script>
</body>
</html>