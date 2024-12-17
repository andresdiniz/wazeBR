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

// Função para buscar as irregularidades
function getIrregularities(PDO $pdo) {
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
            rl.y 
        FROM irregularities ir
        LEFT JOIN route_lines rl ON rl.route_id = ir.id
        WHERE ir.is_active = 1
        ORDER BY ir.name ASC
    ");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar coordenadas por irregularidade
    $groupedResults = [];
    foreach ($results as $row) {
        $irregularityId = $row['id'];
        if (!isset($groupedResults[$irregularityId])) {
            $groupedResults[$irregularityId] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'from_name' => $row['from_name'],
                'to_name' => $row['to_name'],
                'avg_speed' => $row['avg_speed'],
                'avg_time' => $row['avg_time'],
                'historic_speed' => $row['historic_speed'],
                'historic_time' => $row['historic_time'],
                'jam_level' => $row['jam_level'],
                'coordinates' => []
            ];
        }

        if ($row['x'] !== null && $row['y'] !== null) {
            $groupedResults[$irregularityId]['coordinates'][] = [
                'x' => $row['x'],
                'y' => $row['y']
            ];
        }
    }

    return array_values($groupedResults); // Retornar apenas os valores
}

// Obter dados de irregularidades
$irregularities = getIrregularities($pdo);
// Exemplo em backend/dashboard.php
$data = [
    'irregularities' => $irregularities
];
