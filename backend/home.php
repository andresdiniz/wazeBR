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

$id_parceiro = $_SESSION['usuario_id_parceiro'];
// Se id_parceiro for igual a 99, mostrar todos os alertas; caso contrário, mostrar apenas os alertas do parceiro

// Função para buscar alertas de acidentes (ordenados pelos mais recentes)
function getAccidentAlerts(PDO $pdo, $id_parceiro) { // Removido "ffunction" (erro de digitação)
    $query = "SELECT * FROM alerts WHERE type = 'ACCIDENT' AND status = 1 ";
    
    if ($id_parceiro != 99) {
        $query .= "AND id_parceiro = :id_parceiro ";
    }
    
    $query .= "ORDER BY pubMillis DESC";

    $stmt = $pdo->prepare($query);
    
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar alertas de congestionamento (ordenados pela confiabilidade do maior para o menor)
function getJamAlerts(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT uuid, country, city, reportRating, subtype, confidence, type, street, location_x, location_y, pubMillis, status, date_received
        FROM alerts 
        WHERE type = 'JAM' AND status = 1 ";

    if ($id_parceiro != 99) {
        $query .= "AND id_parceiro = :id_parceiro ";
    }

    $query .= "ORDER BY pubMillis DESC";

    $stmt = $pdo->prepare($query);
    
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Exemplo em backend/dashboard.php
$dados = [
    'accidentAlerts' => getAccidentAlerts($pdo, $id_parceiro),
    'jamAlerts' => getJamAlerts($pdo, $id_parceiro)
];
