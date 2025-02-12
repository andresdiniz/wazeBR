<?php
session_start();
require_once './config/configbd.php';
require_once './vendor/autoload.php';

// Verificação básica de autenticação
if (!isset($_SESSION['usuario_id_parceiro'])) {
    header('Location: login.php');
    exit;
}

$id_parceiro = $_SESSION['usuario_id_parceiro'];

// Configuração básica do Twig
$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../frontend');
$twig = new \Twig\Environment($loader);

try {
    $pdo = Database::getConnection();
    
    // Query simplificada
    $sql = "SELECT uuid, city, street, reportRating as confidence, pubMillis 
            FROM alerts 
            WHERE type = 'HAZARD' 
            AND subtype = 'HAZARD_ON_ROAD_POT_HOLE' 
            AND status = 1";
    
    if ($id_parceiro != 99) {
        $sql .= " AND id_parceiro = :id_parceiro";
    }
    
    $stmt = $pdo->prepare($sql);
    
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    $buracos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [
        'buracos' => $buracos
        ];

} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}