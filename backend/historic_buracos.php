
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
function getburacosAlerts(PDO $pdo, $id_parceiro) {
    $query = "
        SELECT uuid, country, city, reportRating, confidence, type, subtype, street, location_x, location_y, pubMillis 
        FROM alerts 
        WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE' AND status = 1
    ";

    // Se não for o parceiro administrador (99), adiciona o filtro de parceiro
    if ($id_parceiro != 99) {
        $query .= " AND id_parceiro = :id_parceiro ";
    }

    $query .= " ORDER BY confidence DESC"; // Ordenação correta

    $stmt = $pdo->prepare($query);

    // Se necessário, vincula o parâmetro do parceiro
    if ($id_parceiro != 99) {
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// Exemplo em backend/dashboard.php
$data = [
    'bburacos' => getburacosAlerts($pdo, $id_parceiro)
];