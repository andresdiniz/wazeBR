// Verifica se o formulário foi enviado
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Obtendo dados do formulário
    $nome = trim($_POST['nome']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    $senha = trim($_POST['senha']);
    $confirmar_senha = trim($_POST['confirmar_senha']);

    // Verifica se todos os campos foram preenchidos
    if (empty($nome) || empty($email) || empty($username) || empty($senha) || empty($confirmar_senha)) {
        header("Location: cadastro.php?erro=Todos os campos devem ser preenchidos.");
        exit();
    }

    // Verifica se as senhas são iguais
    if ($senha !== $confirmar_senha) {
        header("Location: cadastro.php?erro=As senhas não coincidem.");
        exit();
    }

    // Verifica a força da senha
    if (strlen($senha) < 6) {
        header("Location: cadastro.php?erro=A senha deve ter pelo menos 6 caracteres.");
        exit();
    }

    // Validação do formato de email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("Location: cadastro.php?erro=E-mail inválido.");
        exit();
    }

    // Hash da senha
    $senha_hash = password_hash($senha, PASSWORD_DEFAULT);

    try {
        $pdo = Database::getConnection();

        // Verifica se o email ou username já existe
        $sql = "SELECT * FROM users WHERE email = :email OR username = :username";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->bindParam(':username', $username, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            header("Location: cadastro.php?erro=Email ou nome de usuário já existe.");
            exit();
        }

        // Inserir novo usuário
        $sql_insert = "INSERT INTO users (nome, email, username, password) VALUES (:nome, :email, :username, :password)";
        $stmt = $pdo->prepare($sql_insert);
        $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->bindParam(':username', $username, PDO::PARAM_STR);
        $stmt->bindParam(':password', $senha_hash, PDO::PARAM_STR);

        if ($stmt->execute()) {
            header("Location: login.php?sucesso=Cadastro realizado com sucesso.");
            exit();
        } else {
            header("Location: cadastro.php?erro=Erro ao cadastrar usuário. Tente novamente.");
            exit();
        }
    } catch (PDOException $e) {
        error_log("Erro ao cadastrar usuário: " . $e->getMessage());
        header("Location: cadastro.php?erro=Erro ao conectar com o banco de dados.");
        exit();
    }
}
