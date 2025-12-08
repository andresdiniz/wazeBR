<?php
// profile.php - Controlador de Perfil com Modelo Integrado, Logger e ErrorHandler

// =========================================================================
// 0. MODELO DE DADOS INTEGRADO (CLASSE USER)
// =========================================================================

class User {
    private $pdo;
    private $tableName = 'users';

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Busca os dados de um usuário pelo ID.
     */
    public function getById($id) {
        if (!is_numeric($id)) return false;
        
        $sql = "SELECT id, email, phone_number, nome, username, photo, password, type, locale, receber_email 
                FROM {$this->tableName} 
                WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Atualiza os dados pessoais do usuário.
     */
    public function updateProfile($id, array $data) {
        if (!is_numeric($id) || empty($data)) return false;

        $allowedFields = ['nome', 'username', 'phone_number', 'locale', 'receber_email', 'photo']; 
        $updateData = [];
        $fields = [];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "{$key} = :{$key}";
                $updateData[$key] = $value;
            }
        }
        
        if (empty($fields)) return false;

        $sql = "UPDATE {$this->tableName} SET " . implode(', ', $fields) . " WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        $updateData['id'] = $id;
        
        return $stmt->execute($updateData);
    }
    
    /**
     * Atualiza a senha do usuário.
     */
    public function updatePassword($id, $hashedPassword) {
        if (!is_numeric($id)) return false;

        $sql = "UPDATE {$this->tableName} SET password = :password WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        
        return $stmt->execute([
            'password' => $hashedPassword,
            'id' => $id
        ]);
    }
    
    /**
     * Verifica se o username já está em uso por outro usuário.
     */
     public function isUsernameTaken($username, $currentId) {
        $sql = "SELECT id FROM {$this->tableName} WHERE username = :username AND id != :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['username' => $username, 'id' => $currentId]);
        
        return $stmt->fetchColumn() !== false;
    }
}


// =========================================================================
// 1. CONFIGURAÇÃO INICIAL E INCLUDES (CONTROLADOR)
// =========================================================================

session_start();
// var_dump($_SESSION); // Descomente apenas para debug

// Variáveis de Ambiente
$isDebug = true; 
$logDir = __DIR__ . '/logs';
// Use a chave correta. Se for 'usuario_id' como você tentou, mantenha.
$loggedInUserId = $_SESSION['usuario_id'] ?? null; 

// echo $loggedInUserId; // Descomente apenas para debug

// 2. INCLUDES E AUTOLOAD
require_once './vendor/autoload.php';      // Autoloader do Composer
require_once './config/configbd.php';      // Conexão com o banco de dados
// REMOVEMOS: require_once 'User.php'; 
require_once './classes/Logger.php';       
require_once './classes/ErrorHandler.php'; 

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// 3. INICIALIZAÇÃO DE LOG E ERRO
$logger = Logger::getInstance($logDir, $isDebug);

// Configura o Twig
$loader = new FilesystemLoader(__DIR__ . '/../frontend');
$twig = new Environment($loader);

// Inicializa o ErrorHandler
$errorHandler = new ErrorHandler($logger, $twig, $isDebug);
$errorHandler->register();


// 4. AUTENTICAÇÃO
if (!$loggedInUserId) {
    // Logamos o acesso não autorizado
    $logger->warning("Acesso 401 negado. Sessão inativa ou chave 'usuario_id' não definida.", [
        'session_id' => session_id()
    ]);
    
    // Mostramos o erro 401 e encerramos
    $errorHandler->showError(401, 
        'Acesso Não Autorizado', 
        'Você precisa estar logado para acessar esta página.',
        'Redirecionando para a página de login...'
    );
    exit;
}

// 5. CONEXÃO COM O BANCO DE DADOS
try {
    $pdo = Database::getConnection(); 
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Relançamos para que o ErrorHandler capture, logue (CRITICAL) e mostre o erro 500.
    throw $e;
}

// Inicializa o Modelo (AGORA A CLASSE JÁ ESTÁ DEFINIDA ACIMA)
$userModel = new User($pdo);
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


// =========================================================================
// 6. TRATAMENTO DE REQUISIÇÕES POST (LOGIC DE ATUALIZAÇÃO)
// =========================================================================

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
                    $user = $userModel->getById($loggedInUserId); 
                    $logger->info("Perfil do usuário ID {$loggedInUserId} atualizado com sucesso.");
                } else {
                    $errors[] = "Nenhuma alteração foi detectada ou ocorreu um erro na atualização.";
                    $logger->warning("Falha ao atualizar perfil do ID: {$loggedInUserId}. Nenhum dado modificado.", $updateData);
                }
            } catch (Throwable $e) {
                throw $e; 
            }
        } else {
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
                } else {
                    $errors[] = "Erro ao alterar a senha. Tente novamente.";
                    $logger->error("Falha fatal ao alterar senha do ID: {$loggedInUserId}. (Erro DB).");
                }
            } catch (Throwable $e) {
                throw $e; 
            }
        }
    }
}

// 7. PREPARAÇÃO DOS DADOS PARA RENDERIZAÇÃO
$user['photo_url'] = !empty($user['photo']) 
    ? '/caminho/para/fotos/' . $user['photo'] 
    : '/caminho/para/default-avatar.png';


// 8. PASSAGEM DOS DADOS PARA A VIEW

$data = [
    'user' => $user,
    'messages' => $messages, 
    'errors' => $errors
];