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

$id_parceiro = $_SESSION['usuario_id_parceiro'];

// Função para buscar URLs de tráfego
function getUrlsTraffic(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT * 
        FROM urls 
    ";
    
    // Se não for o parceiro administrador (99), adiciona o filtro de parceiro
    if ($id_parceiro != 99) {
        $query .= "WHERE id_parceiro = :id_parceiro ";
    }

    $stmt = $pdo->prepare($query);

    // Se necessário, vincula o parâmetro do parceiro
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getUrlsEvents(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT * 
        FROM urls_events 
    ";
    
    // Se não for o parceiro administrador (99), adiciona o filtro de parceiro
    if ($id_parceiro != 99) {
        $query .= "WHERE id_parceiro = :id_parceiro ";
    }

    $stmt = $pdo->prepare($query);

    // Se necessário, vincula o parâmetro do parceiro
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getUrlsAlerts(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT * 
        FROM urls_alerts 
    ";
    
    // Se não for o parceiro administrador (99), adiciona o filtro de parceiro
    if ($id_parceiro != 99) {
        $query .= "WHERE id_parceiro = :id_parceiro ";
    }

    $stmt = $pdo->prepare($query);

    // Se necessário, vincula o parâmetro do parceiro
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getParceiros(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT * 
        FROM parceiros 
    ";
    
    // Se não for o parceiro administrador (99), adiciona o filtro de parceiro
    if ($id_parceiro != 99) {
        $query .= "WHERE id_parceiro = :id_parceiro ";
    }

    $stmt = $pdo->prepare($query);

    // Se necessário, vincula o parâmetro do parceiro
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$data = [
    'urls_traffic' => getUrlsTraffic($pdo, $id_parceiro),
    'urls_alerts' => getUrlsAlerts($pdo, $id_parceiro),
    'urls_events' => getUrlsEvents($pdo, $id_parceiro),
    'parceiros' => getParceiros($pdo, $id_parceiro),

];

