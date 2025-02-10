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
    if (isset($_POST['form_type']) && $_POST['form_type'] == "edit_partner") {
        $id = intval($_POST['id']); // Garantir que o ID seja um número inteiro
        $nome = $conn->real_escape_string($_POST['Nome']);
        $identificador = $conn->real_escape_string($_POST['name_partner']);

        // Query de atualização
        $sql = "UPDATE parceiros SET Nome='$nome', name_partner='$identificador' WHERE id=$id";

        if ($conn->query($sql) === TRUE) {
            echo "Registro atualizado com sucesso!";
        } else {
            echo "Erro ao atualizar registro: " . $conn->error;
        }
    }
}

// Buscar dados para passar para o template
$data = [
    'settingsdata' => getsettings($pdo),
    'pagesdata' => getSitepagesAll($pdo),
    'parceirosdata' => getParceiros($pdo),
];

?>
