<?php
try {
    require_once './config/configbd.php';
    session_start(); // Iniciar a sessão

    // Obter conexão com o banco
    $pdo = Database::getConnection();

    // Consulta por email
    $sql = "SELECT * FROM users WHERE email = :email";
    $stmt = $pdo->prepare($sql);

    // Pega o email enviado via POST
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    $stmt->bindParam(':email', $email, PDO::PARAM_STR);

    // Executa a query
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        // Obtém os dados do usuário
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verifica a senha usando password_verify
        if (password_verify($password, $user['password'])) {
            // Salva dados do usuário na sessão (exceto senha)
            session_regenerate_id(true); // Prevenir fixação de sessão
            $_SESSION['usuario_id'] = $user['id'];
            $_SESSION['usuario_nome'] = $user['nome'];
            $_SESSION['usuario_email'] = $user['email'];
            $_SESSION['usuario_username'] = $user['username'];
            $_SESSION['usuario_photo'] = $user['photo'];

            // Redireciona para a página inicial
            header("Location: /wazeportal");
            exit();
        } else {
            // Caso a senha esteja incorreta
            header("Location: login.php?erro=Senha incorretos.");
            exit();
        }
    } else {
        // Caso o email não exista no banco
        header("Location: login.php?erro=Email incorretos.");
        exit();
    }
} catch (PDOException $e) {
    // Logar o erro no banco
    error_log("Erro ao acessar o banco: " . $e->getMessage());
    header("Location: login.php?erro=Erro ao acessar o banco.");
    exit();
}
?>
