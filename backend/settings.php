<?php

// Inclui o arquivo de configuração do banco de dados e autoload do Composer
require_once './config/configbd.php'; // Conexão ao banco de dados
require_once './vendor/autoload.php'; // Autoloader do Composer
require_once './functions/scripts.php'; // Funções e scripts adicionais

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

// Lidar com as ações
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? ''; // Obter a ação enviada pelo JavaScript

    switch ($action) {
        case 'delete_partner':
            $id = $_POST['id'] ?? null;
            if ($id) {
                $stmt = $pdo->prepare("DELETE FROM parceiros WHERE id = ?");
                $stmt->execute([$id]);

                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erro ao excluir parceiro ou parceiro não encontrado.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'ID do parceiro não fornecido.']);
            }
            break;

        case 'create_partner':
            // Coletar dados do formulário
            $nome = $_POST['nome'] ?? '';
            $identificador = $_POST['identificador'] ?? '';
            
            if ($nome && $identificador) {
                $stmt = $pdo->prepare("INSERT INTO parceiros (nome, identificador) VALUES (?, ?)");
                $stmt->execute([$nome, $identificador]);
                
                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erro ao criar parceiro.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Dados incompletos.']);
            }
            break;

        case 'update_partner':
            // Coletar dados do formulário de edição
            $id = $_POST['id'] ?? null;
            $nome = $_POST['Nome'] ?? '';
            $identificador = $_POST['name_partner'] ?? '';
            
            if ($id && $nome && $identificador) {
                $stmt = $pdo->prepare("UPDATE parceiros SET nome = ?, identificador = ? WHERE id = ?");
                $stmt->execute([$nome, $identificador, $id]);
                
                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erro ao atualizar parceiro ou nenhum dado alterado.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Dados incompletos.']);
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Ação não reconhecida.']);
            break;
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Método de requisição inválido.']);
}

// Buscar dados para passar para o template
$data = [
    'settingsdata' => getsettings($pdo),
    'pagesdata' => getSitepagesAll($pdo),
    'parceirosdata' => getParceiros($pdo),
];

?>
