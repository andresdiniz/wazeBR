<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

ini_set('log_errors', 1);
ini_set('error_log', 'error_log.log');

try {
    require_once './config/configbd.php';
    require_once './functions/scripts.php';
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
            $_SESSION['usuario_id_parceiro'] = $user['id_parceiro'];
            $_SESSION['type'] = $user['type'];

            setcookie('usuario_id_parceiro', $user['id_parceiro'], time() + (86400 * 30), "/");

            $ip = getIp();

            try {
                // Prepara a consulta SQL para inserção
                $sql = "
                    INSERT INTO historic_login (user, data_hora, ip)
                    VALUES (:user, :data_hora, :ip)
                ";
                $stmt = $pdo->prepare($sql);

                // Define os valores a serem inseridos
                $dateTime = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
                $dataHora = $dateTime->format('Y-m-d H:i:s'); // Formata a data no padrão Y-m-d H:i:s
                $stmt->bindParam(':user', $_SESSION['usuario_id']);
                $stmt->bindParam(':data_hora', $dataHora);
                $stmt->bindParam(':ip', $ip);

                // Executa o comando SQL
                $stmt->execute();
            } catch (PDOException $e) {
                // Lida com erros de execução e loga
                error_log("Erro ao inserir histórico de login: " . $e->getMessage());
                $_SESSION['login_error'] = "Erro ao registrar o login.";
                header("Location: login.html");
                exit();
            }

            // Redireciona para a página inicial
            header("Location: /");
            exit();
        } else {
            // Caso a senha esteja incorreta
            header("Location: login.html?erro=Senha%20incorreta");
            exit();
        }
    } else {
        // Caso o email não exista no banco
        header("Location: login.html?erro=Email%20incorreto");
        exit();
    }
} catch (PDOException $e) {
    // Logar o erro no banco
    error_log("Erro ao acessar o banco: " . $e->getMessage());
    $_SESSION['login_error'] = "Erro ao acessar o banco.";
    header("Location: login.html");
    exit();
}