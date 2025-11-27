<?php
// ----------------------------------------------------
// 1. CONFIGURAÇÕES E INCLUSÕES
// ----------------------------------------------------

// **AJUSTE AQUI:** Inclua seus arquivos de configuração e conexão com o BD
// include_once 'config/database.php'; 
// include_once 'models/User.php'; 

// Define a URL para redirecionamento após o processamento (o próprio formulário)
//$redirect_url = '/insert_user.twig'; // Mude para o caminho real do seu template

// ----------------------------------------------------
// 2. VERIFICAÇÃO DO MÉTODO DE REQUISIÇÃO
// ----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Redireciona para a página do formulário se o acesso não for via POST
    header("Location: $redirect_url");
    exit;
}

// ----------------------------------------------------
// 3. COLETA E SANITIZAÇÃO DE DADOS
// ----------------------------------------------------

// Coleta dados, usando o operador de coalescência null (??) para evitar erros
$name = $_POST['name'] ?? null;
$email = $_POST['email'] ?? null;
$password = $_POST['password'] ?? null;
$permission = $_POST['permission'] ?? null;

// Sanear (limpar) os dados
$name = filter_var(trim($name), FILTER_SANITIZE_STRING); // Remove tags e espaços
$email = filter_var(trim($email), FILTER_SANITIZE_EMAIL); // Remove caracteres inválidos de e-mail
$permission = filter_var($permission, FILTER_VALIDATE_INT); // Garante que seja um inteiro

// ----------------------------------------------------
// 4. VALIDAÇÃO BÁSICA
// ----------------------------------------------------

if (empty($name) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($password) || $permission === false) {
    $status = 'error';
    $message = 'Erro na validação: Por favor, preencha todos os campos corretamente.';
    header("Location: $redirect_url?status=$status&message=" . urlencode($message));
    exit;
}

// ----------------------------------------------------
// 5. HASH DA SENHA E INSERÇÃO NO BD
// ----------------------------------------------------

// Cria o hash da senha (MUITO IMPORTANTE para segurança)
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

try {
    // **LOGIC PLACEHOLDER:** Conexão e Inserção no Banco de Dados

    // Exemplo de como a inserção deve ser feita (ADAPTE)
    /*
    $db = new Database();
    $user = new User($db);

    $insert_data = [
        'name' => $name,
        'email' => $email,
        'password' => $hashed_password,
        'permission' => $permission
    ];

    if ($user->create($insert_data)) {
        $success = true; // Se o método create retornar true
    } else {
        throw new Exception("Falha ao executar a inserção no banco de dados.");
    }
    */
    
    // Simulação de sucesso (Remover esta linha após implementar o BD)
    $success = true; 

    // Define o status da resposta
    if ($success) {
        $status = 'success';
        $message = 'Usuário "' . $name . '" cadastrado com sucesso!';
    } else {
        // Isso será capturado pelo bloco catch se a inserção falhar
        throw new Exception("A inserção falhou por um motivo desconhecido.");
    }

} catch (Exception $e) {
    // Captura exceções do banco de dados ou erros de lógica
    $status = 'error';
    $message = 'Erro no servidor: ' . $e->getMessage();
}

// ----------------------------------------------------
// 6. REDIRECIONAMENTO COM FEEDBACK
// ----------------------------------------------------

// Redireciona de volta para a página do formulário com o status
header("Location: $redirect_url?status=$status&message=" . urlencode($message));
exit;

?>