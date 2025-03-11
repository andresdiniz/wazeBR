<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once './config/configbd.php';
require_once './vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Configurar o Twig
$loader = new FilesystemLoader(__DIR__ . '/../frontend'); // Caminho para a pasta frontend
$twig = new Environment($loader);

// Conexão com o banco de dados
$pdo = Database::getConnection();

// Define valores padrão de datas
$startDate = date('Y-m-01');
$endDate = date('Y-m-d');

$id_parceiro = $_SESSION['usuario_id_parceiro'] ?? 99;

// Buscar dados históricos
$sql = "SELECT data, velocidade, tempo 
        FROM historic_routes 
        WHERE id_parceiro = :id_parceiro
        AND data BETWEEN :start_date AND :end_date
        ORDER BY data";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':id_parceiro' => $id_parceiro,
    ':start_date' => $startDate,
    ':end_date' => $endDate
]);

$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Formatar dados numéricos
foreach ($data as &$item) {
    $item['velocidade'] = (float)$item['velocidade'];
    $item['tempo'] = (float)$item['tempo'];
    $item['data'] = date('Y-m-d H:i:s', strtotime($item['data'])); // Formatação opcional da data
}

// Passa os dados para o Twig
echo $twig->render('historic_routes.twig', ['dados' => ['historic_routes' => $data]]);
