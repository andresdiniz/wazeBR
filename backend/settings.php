<?php

// Inclui o arquivo de configuração do banco de dados e autoload do Composer
require_once './config/configbd.php'; // Conexão ao banco de dados
require_once './vendor/autoload.php'; // Autoloader do Composer

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Configura o carregador do Twig para buscar templates na pasta "frontend"
$loader = new FilesystemLoader(__DIR__ . '/../frontend'); // Caminho para a pasta frontend
$twig = new Environment($loader);

// Conexão com o banco de dados
$pdo = Database::getConnection();

// Função para buscar alertas de acidentes (ordenados pelos mais recentes)
function getsettings(PDO $pdo) {
    $stmt = $pdo->prepare("
        SELECT * 
        FROM settings
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSitepagesAll($pdo) {
    $data = []; // Array para armazenar os dados das páginas

    try {
        // Preparar e executar a consulta para buscar todas as páginas
        $stmt = $pdo->prepare("SELECT * FROM pages");
        $stmt->execute();

        // Buscar todas as páginas e armazenar no array
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($pages) {
            $data['pageData'] = $pages; // Armazena todas as páginas no array
        } else {
            $data['pageData'] = []; // Retorna um array vazio se não houver páginas
        }
    } catch (PDOException $e) {
        // Captura e registra qualquer erro
        $data['pageData'] = [];
        error_log("Erro ao consultar a tabela 'pages': " . $e->getMessage());
    }

    return $data; // Retorna todas as páginas
}


$data = [
    'settingsdata' => getsettings($pdo),
    'pagesdata'=>getSitepagesAll($pdo),
];
