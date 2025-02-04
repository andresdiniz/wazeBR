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
    // Inicia o array para armazenar os dados da página
    $data = [];
    // Consulta na tabela 'pages' com o parâmetro 'url' para pegar os dados da página
    try {
        // Preparar a consulta SQL para buscar os dados da página com base na URL
        $stmt = $pdo->prepare("SELECT * FROM pages");
        $stmt->execute();

        // Verifica se encontrou a página
        $pageData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pageData) {
            // Se encontrou, adiciona os dados da página ao array $data
            $data['pageData'] = $pageData;
            //logToFile('info','pages', $data); // Adicionado para depuração
            //var_dump($data); // Adicionado para depuração
        } else {
            // Se não encontrou, pode adicionar uma mensagem de erro ou página não encontrada
            $data['pageData'] = null;
        }
    } catch (PDOException $e) {
        // Caso ocorra erro na consulta
        $data['pageData'] = null;
        error_log("Erro ao consultar a tabela 'pages': " . $e->getMessage());
    }

    // Retorna o array com os dados da página ou null se não encontrada
    return $data;
}
// Uso da função
$settingsData = getsettings($pdo);


$data = [
    'settingsdata' => getsettings($pdo),
    'pagesdata'=>getSitepagesAll($pdo),
];
