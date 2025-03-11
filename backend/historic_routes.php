<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

require_once './config/configbd.php';
require_once './vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Iniciar sessão
session_start();

// Configurar o Twig
$loader = new FilesystemLoader(__DIR__ . '/../frontend'); // Caminho correto da pasta frontend
$twig = new Environment($loader, [
    'cache' => false, // Desativar cache para desenvolvimento
    'debug' => true
]);

// Conexão com o banco de dados
try {
    $pdo = Database::getConnection();
} catch (Exception $e) {
    error_log("Erro ao conectar ao banco de dados: " . $e->getMessage());
    die("Erro de conexão com o banco de dados.");
}

// Definir período de consulta
$startDate = date('Y-m-01');
$endDate = date('Y-m-d');

// Buscar dados históricos (SEM id_parceiro)
$sql = "SELECT data, velocidade, tempo 
        FROM historic_routes
        WHERE data BETWEEN :start_date AND :end_date
        ORDER BY data";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatar dados numéricos
    foreach ($data as &$item) {
        $item['velocidade'] = (float)$item['velocidade'];
        $item['tempo'] = (float)$item['tempo'];
        $item['data'] = date('Y-m-d H:i:s', strtotime($item['data'])); // Ajusta formato de data
    }

} catch (Exception $e) {
    error_log("Erro ao buscar dados: " . $e->getMessage());
    die("Erro ao recuperar dados históricos.");
}

// Renderizar o template com os dados
echo $twig->render('historic_routes.twig', ['dados' => ['historic_routes' => $data]]);
