<?php
// Inclui o arquivo de configuração do banco de dados e autoload do Composer
require_once './config/configbd.php'; // Conexão ao banco de dados
require_once './vendor/autoload.php'; // Autoloader do Composer

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id_parceiro']) || !isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

$id_parceiro = $_SESSION['usuario_id_parceiro'];

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Configura o carregador do Twig para buscar templates na pasta "frontend"
$loader = new FilesystemLoader(__DIR__ . '/../frontend'); // Caminho para a pasta frontend
$twig = new Environment($loader);

// Conexão com o banco de dados
$pdo = Database::getConnection();

// Função para buscar as irregularidades
function getIrregularities(PDO $pdo, $id_parceiro) {
    $stmt = $pdo->prepare("
        SELECT 
            ir.id, 
            ir.name, 
            ir.from_name, 
            ir.to_name, 
            ir.avg_speed, 
            ir.avg_time, 
            ir.historic_speed, 
            ir.historic_time, 
            ir.jam_level, 
            rl.x, 
            rl.y,
            ir.leadtype,
            ir.position,
            ir.num_thumbs_up,
            ir.city
        FROM irregularities ir
        LEFT JOIN route_lines rl ON rl.route_id = ir.id
        WHERE ir.is_active = 1 AND ir.id_parceiro = :id_parceiro
        ORDER BY ir.name ASC
    ");
    // Adiciona o filtro de parceiro
    $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar coordenadas e dados por irregularidade
    $groupedResults = [];
    foreach ($results as $row) {
        $irregularityId = $row['id'];

        // Verificar se a irregularidade já foi inicializada no array de resultados agrupados
        if (!isset($groupedResults[$irregularityId])) {
            $groupedResults[$irregularityId] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'from_name' => $row['from_name'] ?? '',  // Se for null, atribui string vazia
                'to_name' => $row['to_name'] ?? '',  // Se for null, atribui string vazia
                'avg_speed' => $row['avg_speed'] ?? 0,  // Se for null, atribui 0
                'avg_time' => $row['avg_time'] ?? 0,  // Se for null, atribui 0
                'historic_speed' => $row['historic_speed'] ?? 0,  // Se for null, atribui 0
                'historic_time' => $row['historic_time'] ?? 0,  // Se for null, atribui 0
                'jam_level' => $row['jam_level'] ?? 0,  // Se for null, atribui 0
                'leadtype' => $row['leadtype'] ?? '',  // Se for null, atribui string vazia
                'position' => $row['position'] ?? '',  // Se for null, atribui string vazia
                'num_thumbs_up' => $row['num_thumbs_up'] ?? 0,  // Se for null, atribui 0
                'city' => $row['city'] ?? '',  // Se for null, atribui string vazia
                'coordinates' => []  // Inicializando o array de coordenadas
            ];
        }

        // Adicionar coordenadas se existirem
        if ($row['x'] !== null && $row['y'] !== null) {
            $groupedResults[$irregularityId]['coordinates'][] = [
                'x' => $row['x'],
                'y' => $row['y']
            ];
        }
    }

    // Retornar apenas os valores agrupados (sem a chave associativa)
    return array_values($groupedResults); 
}

// Obter dados de irregularidades
$irregularities = getIrregularities($pdo, $id_parceiro);

// Dados a serem passados para o Twig
$data = [
    'irregularities' => $irregularities
];

