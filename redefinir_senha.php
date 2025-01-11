<?php

require_once __DIR__ . '/config/configbd.php';

// Verifica se o parâmetro 'token' foi enviado na URL
if (!isset($_GET['token'])) {
    echo "<script>
        alert('Token não fornecido na URL. Você será redirecionado para a página de login.');
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
        SELECT rs.email, u.id AS user_id
        FROM recuperar_senha rs
        JOIN users u ON u.email = rs.email
        WHERE rs.token = :token
        AND rs.valid >= NOW()
        AND rs.used = 1
    ");
    $stmt->bindParam(':token', $token, PDO::PARAM_STR);
    $stmt->execute();

    // Verifica se o token é válido
    if ($stmt->rowCount() > 0) {
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $userId = $result['user_id'];

        // Marca o token como em processo de uso (opcional)
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
    <title>Redefinir Senha</title>
    <style>
        /* Seu CSS existente */
    </style>
</head>
<body>
    <form action="save_password.php" method="POST" id="passwordForm">
        <h2>Criar Senha</h2>
        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($userId, ENT_QUOTES, 'UTF-8'); ?>">
        <label for="password1">Senha:</label>
        <input type="password" id="password1" name="password1" placeholder="Digite sua senha" required>
        
        <label for="password2">Confirmar Senha:</label>
        <input type="password" id="password2" name="password2" placeholder="Confirme sua senha" required>
        
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
        // Seu JavaScript existente para validar senha
    </script>
</body>
</html>
