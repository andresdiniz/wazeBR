<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', 'php_error.log');  // Defina o caminho do arquivo de log


// Inclui o arquivo de configuração do banco de dados e autoload do Composer
require_once './config/configbd.php'; // Conexão ao banco de dados
require_once './vendor/autoload.php'; // Autoloader do Composer
require_once './functions/scripts.php'; // Funções e scripts adicionais
require_once './config/configs.php'; // Configurações gerais do site

// Obter a data e hora atual no formato Y-m-d H:i:s
$now = date('Y-m-d H:i:s');  // Exemplo de formato: 2025-02-10 15:30:0

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Configura o carregador do Twig para buscar templates na pasta "frontend"
$loader = new FilesystemLoader(__DIR__ . '/../frontend'); // Caminho para a pasta frontend
$twig = new Environment($loader);

// Conexão com o banco de dados
$pdo = Database::getConnection();

// Função para buscar configurações do site
function getsettings(PDO $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM settings");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar todas as páginas do site
function getSitepagesAll($pdo) {
    $data = []; // Array para armazenar os dados das páginas

    try {
        // Preparar e executar a consulta para buscar todas as páginas
        $stmt = $pdo->prepare("SELECT * FROM pages");
        $stmt->execute();

        // Buscar todas as páginas e armazenar no array
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data['pageData'] = $pages ? $pages : []; // Armazena as páginas ou um array vazio se não houver
    } catch (PDOException $e) {
        // Captura e registra qualquer erro
        $data['pageData'] = [];
        error_log("Erro ao consultar a tabela 'pages': " . $e->getMessage());
    }

    return $data; // Retorna todas as páginas
}

// Lidar com as ações de formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type'])) {
    // Processa as ações com base no valor de 'form_type'
    switch ($_POST['form_type']) {
        
        // Caso de edição de parceiro
        case 'edit_partner':
            $id = intval($_POST['id']); // Garantir que o ID seja um número inteiro
            $nome = trim($_POST['Nome']);
            $identificador = trim($_POST['name_partner']);

            if (!empty($id) && !empty($nome) && !empty($identificador)) {
                try {
                    // Query de atualização com Prepared Statement
                    $sql = "UPDATE parceiros SET Nome = :nome, name_partner = :identificador WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':nome', $nome, PDO::PARAM_STR);
                    $stmt->bindValue(':identificador', $identificador, PDO::PARAM_STR);
                    $stmt->bindValue(':id', $id, PDO::PARAM_INT);

                    if ($stmt->execute()) {
                        echo json_encode(["success" => true, "message" => "Registro atualizado com sucesso!"]);
                    } else {
                        echo json_encode(["success" => false, "message" => "Erro ao atualizar o registro."]);
                    }
                } catch (PDOException $e) {
                    error_log("Erro ao atualizar parceiro: " . $e->getMessage());
                    echo json_encode(["success" => false, "message" => "Erro ao processar a atualização."]);
                }
            } else {
                echo json_encode(["success" => false, "message" => "Dados inválidos fornecidos."]);
            }
            break;

        // Caso de criação de nova página
        case 'new_page':
            // Verifica apenas os campos obrigatórios
            if (
                !empty($_POST['title']) &&
                !empty($_POST['url']) &&
                !empty($_POST['description'])
            ) {
                // Dados do formulário
                $title = $_POST['title'];
                $url = 'wazeportal/' . $_POST['url'];
                $description = $_POST['description'];

                // Atribuindo valores aos campos opcionais (caso não estejam vazios)
                $image = !empty($_POST['featured_image']) ? $_POST['featured_image'] : null;
                $status = !empty($_POST['status']) ? $_POST['status'] : null;
                $meta_title = !empty($_POST['meta_title']) ? $_POST['meta_title'] : null;
                $meta_description = !empty($_POST['meta_description']) ? $_POST['meta_description'] : null;
                $show_in_nav = isset($_POST['show_in_nav']) ? (int)$_POST['show_in_nav'] : null;

                // A data e hora atual que será salva no banco
                $created_at = date('Y-m-d H:i:s');  // Data e hora no formato 'Y-m-d H:i:s'

                // Dados padrão caso algum campo opcional esteja vazio
                $user_id = 1; // Definir um usuário padrão, se necessário
                $template = "default"; // Definir um template padrão

                try {
                    // Query de inserção com Prepared Statement
                    $sql = "INSERT INTO pages (title, url, description, featured_image, status, meta_title, meta_description, show_in_nav, user_id, template, created_at) 
                            VALUES (:title, :url, :description, :image, :status, :meta_title, :meta_description, :show_in_nav, :user_id, :template, :created_at)";

                    $stmt = $pdo->prepare($sql);
                    $stmt->bindParam(':title', $title);
                    $stmt->bindParam(':url', $url);
                    $stmt->bindParam(':description', $description);
                    $stmt->bindParam(':image', $image);
                    $stmt->bindParam(':status', $status);
                    $stmt->bindParam(':meta_title', $meta_title);
                    $stmt->bindParam(':meta_description', $meta_description);
                    $stmt->bindParam(':show_in_nav', $show_in_nav, PDO::PARAM_INT);
                    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                    $stmt->bindParam(':template', $template);
                    $stmt->bindParam(':created_at', $created_at);

                    // Executar o INSERT
                    if ($stmt->execute()) {
                        echo "Nova página adicionada com sucesso!";
                    } else {
                        echo "Erro ao adicionar a página: " . implode(", ", $stmt->errorInfo());
                    }
                } catch (PDOException $e) {
                    // Caso ocorra um erro com o banco, captura e exibe
                    echo "Erro no banco de dados: " . $e->getMessage();
                }
            } else {
                echo "Campos obrigatórios não preenchidos.";
            }
            break;
        
            case 'delete_partner':
                // Lógica para deletar parceiro
                $id = $_POST['id'];
    
                if (!empty($id)) {
                    try {
                        // Excluir o parceiro com o ID
                        $sql = "DELETE FROM parceiros WHERE id = :id";
                        $stmt = $pdo->prepare($sql);
                        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    
                        if ($stmt->execute()) {
                            echo json_encode(["success" => true, "message" => "Parceiro com ID {$id} removido."]);
                        } else {
                            echo json_encode(["success" => false, "message" => "Erro ao remover o parceiro."]);
                        }
                    } catch (PDOException $e) {
                        error_log("Erro ao excluir parceiro: " . $e->getMessage());
                        echo json_encode(["success" => false, "message" => "Erro ao processar a exclusão."]);
                    }
                } else {
                    echo json_encode(["success" => false, "message" => "ID inválido."]);
                }
            break;

        // Caso de exclusão da pagina
        case 'delete_page':
            $id = $_POST['id'];
            if (!empty($id)) {
                try {
                    // Excluir o parceiro com o ID
                    $sql = "DELETE FROM pages WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':id', $id, PDO::PARAM_INT);

                    if ($stmt->execute()) {
                        echo json_encode(["success" => true, "message" => "Pagina com ID {$id} removido."]);
                    } else {
                        echo json_encode(["success" => false, "message" => "Erro ao remover a pagina."]);
                    }
                } catch (PDOException $e) {
                    error_log("Erro ao excluir parceiro: " . $e->getMessage());
                    echo json_encode(["success" => false, "message" => "Erro ao processar a exclusão."]);
                }
            } else {
                echo json_encode(["success" => false, "message" => "ID inválido."]);
            }
            break;

        // Caso de ação não reconhecida
        default:
            echo json_encode(["success" => false, "message" => "Ação não reconhecida."]);
    }
}


// Buscar dados para passar para o template
$data = [
    'settingsdata' => getsettings($pdo),
    'pagesdata' => getSitepagesAll($pdo),
    'parceirosdata' => getParceiros($pdo),
];

?>
