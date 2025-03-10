<?php
// Inclui o arquivo de configuração do banco de dados e autoload do Composer
require_once './config/configbd.php'; // Conexão ao banco de dados
require_once './vendor/autoload.php'; // Autoloader do Composer

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Configura o Twig
$loader = new FilesystemLoader(__DIR__ . '/../frontend'); // Caminho para templates
$twig = new Environment($loader);

// Conexão com o banco de dados
$pdo = Database::getConnection();
session_start();

$id_parceiro = $_SESSION['usuario_id_parceiro'] ?? null;

// Se for uma atualização (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = $_POST['id'];
    $description = $_POST['description'];
    $is_active = $_POST['is_active'];

    $sql = "UPDATE events SET description = ?, is_active = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$description, $is_active, $id]);
}

// Buscar todos os eventos do parceiro logado
$sql = "SELECT * FROM events WHERE id_parceiro = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id_parceiro]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Retorno no $data
$data = [
    'events' => $events
];

