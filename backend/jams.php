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
function getJamsBasic(PDO $pdo, $id_parceiro) {
    // Base da query
    $query = "
        SELECT *
        FROM jams
        WHERE status = 1
    ";

    // Adiciona filtro por parceiro, se necessário
    if ($id_parceiro !== null && $id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro";
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
    'jams' => getJamsBasic($pdo, $id_parceiro),
];
