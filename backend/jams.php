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

// Função para buscar todas as rotas (dados básicos)
function getJamsBasic(PDO $pdo) {
    $stmt = $pdo->prepare("
        SELECT 
            id, name, from_name, to_name, historic_speed, historic_time, avg_speed, avg_time 
        FROM 
            jams
        WHERE
            STATUS = 1
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Busca os dados das rotas para o template
$data = [
    'jams' => getJamsBasic($pdo), // Envia apenas dados básicos
];
