<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('error_reporting',1);
session_start();

require_once './config/configbd.php';
require_once './vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Verifica autenticação
if (!isset($_SESSION['usuario_id_parceiro'])) {
    header('Location: login.php');
    exit;
}

$id_parceiro = filter_var($_SESSION['usuario_id_parceiro'], FILTER_SANITIZE_NUMBER_INT);

// Configuração segura do Twig
$loader = new FilesystemLoader(__DIR__ . '/../frontend');
$twig = new Environment($loader, [
    'cache' => __DIR__ . '/../cache',
    'debug' => false
]);

try {
    $pdo = Database::getConnection();
    
    $data = [
        'bburacos' => getBuracoAlerts($pdo, $id_parceiro)
    ];
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    die("Erro no sistema. Por favor tente mais tarde.");
} catch (Throwable $e) {
    error_log("General error: " . $e->getMessage());
    die("Ocorreu um erro inesperado.");
}

function getBuracoAlerts(PDO $pdo, int $id_parceiro): array
{
    $query = "SELECT uuid, country, city, reportRating as confidence, 
                     type, subtype, street, pubMillis 
              FROM alerts 
              WHERE type = 'HAZARD' 
                AND subtype = 'HAZARD_ON_ROAD_POT_HOLE' 
                AND status = 1";

    if ($id_parceiro !== 99) {
        $query .= " AND id_parceiro = :id_parceiro";
    }

    $query .= " ORDER BY confidence DESC";

    $stmt = $pdo->prepare($query);
    
    if ($id_parceiro !== 99) {
        $stmt->bindValue(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    try {
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erro na query: " . $e->getMessage());
        return [];
    }
}