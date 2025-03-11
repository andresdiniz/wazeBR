<?php
require_once './config/configbd.php';
require_once './vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$loader = new FilesystemLoader(__DIR__ . '/../frontend');
$twig = new Environment($loader);

// Conexão com o banco de dados
$pdo = Database::getConnection();

// Define valores padrão de datas
$startDate = date('Y-m-01'); // Primeiro dia do mês atual
$endDate = date('Y-m-d'); // Data de hoje

session_start();

$id_parceiro = $_SESSION['usuario_id_parceiro'] ?? 99; // Pega o valor ou usa um padrão (99)

// Buscar dados históricos
$sql = "SELECT data, velocidade, tempo FROM historic_routes ORDER BY data";
$result = $conn->query($sql);

$dados = [];
while($row = $result->fetch_assoc()) {
    $dados[] = [
        'data' => $row['data'],
        'velocidade' => (float)$row['velocidade'],
        'tempo' => (float)$row['tempo']
    ];
}

$data = [
    'data' =>  $row['data'],
    'velocidade' => (float)$row['velocidade'],
    'tempo' => (float)$row['tempo'], 
];

