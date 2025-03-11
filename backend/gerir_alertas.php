<?php
// Inclui o arquivo de configuração do banco de dados e autoload do Composer
require_once './config/configbd.php'; // Conexão ao banco de dados
require_once './vendor/autoload.php'; // Autoloader do Composer

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

date('Y-m-d H:i:s');
date_default_timezone_set('America/Sao_Paulo');

// Configura o Twig
$loader = new FilesystemLoader(__DIR__ . '/../frontend'); // Caminho para templates
$twig = new Environment($loader);

// Conexão com o banco de dados
$pdo = Database::getConnection();
session_start();

$id_parceiro = $_SESSION['usuario_id_parceiro'];

// Se for uma atualização (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    // Recebe os dados do POST
    $id = $_POST['id'];
    $description = $_POST['description'];
    $is_active = $_POST['is_active'];
    $endtime = $_POST['endtime'];

    switch ($id) {
        case $id:
            $sql = "UPDATE events SET description = ?, is_active = ?, endtime = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$description, $is_active, $endtime, $id]);
            header('Location: gerir_alertas.php');
            break;
        
        default:
            echo json_encode(['status' => 'error', 'message' => 'Evento não encontrado']);
            break;
    }
    exit;
}

// Buscar eventos
if ($id_parceiro == 99) {
    $sql = "SELECT * FROM events";
    $params = [];
} else {
    $sql = "SELECT * FROM events WHERE id_parceiro = ?";
    $params = [$id_parceiro];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Retorno no $data
$data = [
    'events' => $events
];
?>
