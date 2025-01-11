<?php

require_once __DIR__ . '/config/configbd.php';

// Verifica se o método é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "Acesso inválido.";
    exit;
}

// Captura os dados do formulário
$userId = $_POST['user_id'] ?? null;
$password1 = $_POST['password1'] ?? '';
$password2 = $_POST['password2'] ?? '';

// Verifica se as senhas conferem
if ($password1 !== $password2) {
    echo "<script>
        alert('As senhas não coincidem. Tente novamente.');
        window.history.back();
    </script>";
    exit;
}

// Verifica se os requisitos de senha são atendidos
if (
    strlen($password1) < 8 ||
    !preg_match('/[A-Z]/', $password1) ||
    !preg_match('/[0-9]/', $password1) ||
    !preg_match('/[\W_]/', $password1)
) {
    echo "<script>
        alert('A senha não atende aos requisitos mínimos.');
        window.history.back();
    </script>";
    exit;
}

// Aplica o hash na senha
$hashedPassword = password_hash($password1, PASSWORD_DEFAULT);

try {
    // Usa a conexão PDO existente
    $pdo = Database::getConnection();

    // Atualiza a senha no banco de dados
    $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :user_id");
    $stmt->bindParam(':password', $hashedPassword, PDO::PARAM_STR);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();

    // Confirma a redefinição
    echo "<script>
        alert('Senha redefinida com sucesso. Você será redirecionado para a página de login.');
        window.location.href = 'login.html';
    </script>";
} catch (PDOException $e) {
    die("Erro ao atualizar a senha: " . $e->getMessage());
}
?>
