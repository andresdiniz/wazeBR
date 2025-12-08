<?php
// profile.php - Controlador de Perfil com Logger e ErrorHandler

/* 1. CONFIGURAÇÃO INICIAL
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}*/

session_start(); // Garantir que a sessão esteja iniciada
var_dump($_SESSION);

// Variáveis de Ambiente
$isDebug = true; // Mantenha TRUE para desenvolvimento. Mude para FALSE em produção.
$logDir = __DIR__ . '/logs';
$loggedInUserId = $_SESSION['usuario_id'] ?? null; // CHAVE: Ajuste a chave da sessão conforme seu sistema!

echo $loggedInUserId;

// 2. INCLUDES E AUTOLOAD
require_once './vendor/autoload.php';      // Autoloader do Composer
require_once './config/configbd.php';      // Conexão com o banco de dados
require_once 'User.php';                   // Modelo de Usuário (User.php)
require_once './classes/Logger.php';       // Classe Logger
require_once './classes/ErrorHandler.php'; // Classe ErrorHandler

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// 3. INICIALIZAÇÃO DE LOG E ERRO
$logger = Logger::getInstance($logDir, $isDebug);

// Configura o Twig
$loader = new FilesystemLoader(__DIR__ . '/../frontend');
$twig = new Environment($loader);

// Inicializa o ErrorHandler e o registra para capturar todos os erros/exceções
$errorHandler = new ErrorHandler($logger, $twig, $isDebug);
$errorHandler->register();


// 4. AUTENTICAÇÃO
if (!$loggedInUserId) {
    // Em vez de usar header() e exit;, usamos o método showError do ErrorHandler 
    // para renderizar uma página de erro 401 (Não Autorizado) de forma limpa.
    $errorHandler->showError(401, 
        'Acesso Não Autorizado', 
        'Você precisa estar logado para acessar esta página.',
        'Redirecionando para a página de login...'
    );
    // Nota: O showError já executa exit, mas mantenha para clareza
    exit;
}

// 5. CONEXÃO COM O BANCO DE DADOS
try {
    // Usa a sua classe de conexão com o banco de dados
    $pdo = Database::getConnection(); 
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Qualquer falha aqui (ex: PDOException) será capturada pelo ErrorHandler.
    // Apenas relançamos para que o handleException faça seu trabalho (logar CRITICAL e mostrar 500).
    throw $e;
}

// Inicializa o Modelo e Variáveis
//$userModel = new User($pdo);
$messages = [];
$errors = [];

// Busca os dados atuais do usuário antes de qualquer requisição POST
$user = $userModel->getById($loggedInUserId);

if (!$user) {
    $logger->critical("Tentativa de acesso de ID de usuário não existente: {$loggedInUserId}");
    session_destroy();
    header('Location: /login.php'); 
    exit;
}


// 6. TRATAMENTO DE REQUISIÇÕES POST (ATUALIZAÇÃO DE DADOS)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $action = $_POST['action'] ?? null;

    // A) ATUALIZAR PERFIL (Dados Pessoais)
    if ($action === 'update_profile') {
        
        $nome           = filter_input(INPUT_POST, 'nome', FILTER_SANITIZE_SPECIAL_CHARS);
        $username       = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS);
        $phone_number   = filter_input(INPUT_POST, 'phone_number', FILTER_SANITIZE_SPECIAL_CHARS);
        $locale         = filter_input(INPUT_POST, 'locale', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'pt-BR';
        $receber_email  = filter_input(INPUT_POST, 'receber_email', FILTER_VALIDATE_INT) ? '1' : '0'; 

        if (empty($nome) || empty($username)) {
            $errors[] = "Nome e Username são obrigatórios.";
        }
        
        if ($user['username'] !== $username && $userModel->isUsernameTaken($username, $loggedInUserId)) {
            $errors[] = "Este Username já está em uso por outro usuário.";
        }

        if (empty($errors)) {
            $updateData = [
                'nome' => $nome,
                'username' => $username,
                'phone_number' => $phone_number,
                'locale' => $locale,
                'receber_email' => $receber_email,
            ];

            try {
                if ($userModel->updateProfile($loggedInUserId, $updateData)) {
                    $messages[] = "Seu perfil foi atualizado com sucesso!";
                    // Recarrega os dados atualizados
                    $user = $userModel->getById($loggedInUserId); 
                    $logger->info("Perfil do usuário ID {$loggedInUserId} atualizado com sucesso.");
                } else {
                    $errors[] = "Nenhuma alteração foi detectada ou ocorreu um erro na atualização.";
                    $logger->warning("Falha ao atualizar perfil do ID: {$loggedInUserId}. Nenhum dado modificado.", $updateData);
                }
            } catch (Throwable $e) {
                // Deixa o ErrorHandler global pegar e logar a exceção
                throw $e; 
            }
        } else {
            // Log de Erros de Validação do Usuário
            $logger->notice("Falha na validação de perfil para ID: {$loggedInUserId}", ['errors' => $errors]);
        }
    }
    
    // B) ATUALIZAR SENHA
    elseif ($action === 'update_password') {
        
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $errors[] = "Todos os campos de senha são obrigatórios.";
        } elseif ($new_password !== $confirm_password) {
            $errors[] = "A nova senha e a confirmação não coincidem.";
        } elseif (strlen($new_password) < 8) { 
            $errors[] = "A nova senha deve ter pelo menos 8 caracteres.";
        }
        elseif (!password_verify($current_password, $user['password'])) {
            $errors[] = "A Senha Atual informada está incorreta.";
            $logger->warning("Tentativa falha de alteração de senha para ID: {$loggedInUserId} (Senha atual incorreta).");
        } 
        
        if (empty($errors)) {
            $newHashedPassword = password_hash($new_password, PASSWORD_BCRYPT);
            
            try {
                if ($userModel->updatePassword($loggedInUserId, $newHashedPassword)) {
                    $messages[] = "Sua senha foi alterada com sucesso! Você deve fazer login novamente.";
                    $logger->info("Senha do usuário ID {$loggedInUserId} alterada com sucesso.");
                    // Redirecionamento de segurança após alteração de senha
                    // header('Location: /logout.php'); exit; 
                } else {
                    $errors[] = "Erro ao alterar a senha. Tente novamente.";
                    $logger->error("Falha fatal ao alterar senha do ID: {$loggedInUserId}. (Erro DB).");
                }
            } catch (Throwable $e) {
                 // Deixa o ErrorHandler global pegar e logar a exceção
                throw $e; 
            }
        }
    }
}

// 7. PREPARAÇÃO DOS DADOS PARA RENDERIZAÇÃO
$user['photo_url'] = !empty($user['photo']) 
    ? '/caminho/para/fotos/' . $user['photo'] 
    : '/caminho/para/default-avatar.png';


// 8. RENDERIZAÇÃO
echo $twig->render('profile.twig', [
    'user' => $user,
    'messages' => $messages, 
    'errors' => $errors,     
]);