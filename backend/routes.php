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

session_start();

$id_parceiro = $_SESSION['usuario_id_parceiro'] ?? 99; // Pega o valor ou usa um padrão (99)

// Função para buscar todas as rotas (dados básicos)
function getRoutesBasic(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT 
            id, name, from_name, to_name, historic_speed, historic_time, avg_speed, avg_time, length
        FROM 
            routes
    ";

    // Adiciona o filtro apenas se for diferente de 99
    if ($id_parceiro !== null && $id_parceiro != 99) {
        $query .= " WHERE id_parceiro = :id_parceiro";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    } else {
        $stmt = $pdo->prepare($query);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}



// Busca os dados das rotas para o template
$data = [
    'routes' => getRoutesBasic($pdo, $id_parceiro), // Envia apenas dados básicos
];
