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
try {
    $pdo = Database::getConnection();
    // Defina o modo de erro do PDO para exceções para ajudar no debug
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Erro na conexão com o banco de dados: " . $e->getMessage();
    die();
}

// Recupera o ID do parceiro da sessão
$id_parceiro = $_SESSION['usuario_id_parceiro'] ?? null;
$mostrar_todos = ($id_parceiro === 99);

/**
 * Busca alertas com base no tipo e filtro de parceiro.
 *
 * @param PDO $pdo Conexão PDO.
 * @param string $type O tipo de alerta ('ACCIDENT' ou 'JAM').
 * @param int|null $id_parceiro ID do parceiro, null para todos.
 * @param string|null $orderBy Cláusula ORDER BY opcional.
 * @return array Array associativo dos alertas encontrados.
 */
function getAlertsByType(PDO $pdo, string $type, ?int $id_parceiro = null, ?string $orderBy = null): array
{
    $query = "SELECT * FROM alerts WHERE type = :type AND status = 1 ";

    if ($id_parceiro !== 99 && $id_parceiro !== null) {
        $query .= "AND id_parceiro = :id_parceiro ";
    }

    if ($orderBy) {
        $query .= "ORDER BY " . $orderBy;
    }

    try {
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':type', $type, PDO::PARAM_STR);

        if ($id_parceiro !== 99 && $id_parceiro !== null) {
            $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo "Erro na função getAlertsByType: " . $e->getMessage() . "<br>";
        echo "Query: " . $query . "<br>";
        if ($id_parceiro !== 99 && $id_parceiro !== null) {
            echo "id_parceiro: " . $id_parceiro . "<br>";
        }
        return [];
    }
}

// Função para buscar alertas de acidentes (ordenados pelos mais recentes)
function getAccidentAlerts(PDO $pdo, ?int $id_parceiro = null): array
{
    return getAlertsByType($pdo, 'ACCIDENT', $id_parceiro, 'pubMillis DESC');
}

// Função para buscar alertas de congestionamento (ordenados pelos mais recentes)
function getJamAlerts(PDO $pdo, ?int $id_parceiro = null): array
{
    return getAlertsByType($pdo, 'JAM', $id_parceiro, 'pubMillis DESC');
}

function getLive(PDO $pdo, ?int $id_parceiro = null): array
{
    $query = "SELECT
                jams.*,
                COALESCE(
                    (SELECT JSON_ARRAYAGG(
                        JSON_OBJECT(
                            'id', js.id,
                            'fromNode', js.fromNode,
                            'ID_segment', js.ID_segment,
                            'toNode', js.toNode,
                            'isForward', js.isForward
                        )
                    )
                    FROM jam_segments js
                    WHERE js.jam_uuid = jams.uuid),
                    '[]'
                ) AS segments,
                COALESCE(
                    (SELECT JSON_ARRAYAGG(
                        JSON_OBJECT(
                            'sequence', jl.sequence,
                            'latitude', jl.y,
                            'longitude', jl.x
                        )
                        ORDER BY jl.sequence
                    )
                    FROM jam_lines jl
                    WHERE jl.jam_uuid = jams.uuid),
                    '[]'
                ) AS jam_lines_data -- Alias alterado para evitar conflito com palavra reservada
            FROM jams
            WHERE jams.status = 1";

    if ($id_parceiro !== 99 && $id_parceiro !== null) {
        $query .= " AND jams.id_parceiro = :id_parceiro";
    }

    $query .= " ORDER BY jams.date_received DESC LIMIT 1000";

    try {
        $stmt = $pdo->prepare($query);

        if ($id_parceiro !== 99 && $id_parceiro !== null) {
            $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        }

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decodifica os JSONs para arrays
        foreach ($results as &$row) {
            $row['segments'] = json_decode($row['segments'], true) ?: [];
            // Acesse os dados das linhas usando o novo alias
            $row['jam_lines_data'] = json_decode($row['jam_lines_data'], true) ?: [];
            // Para manter a compatibilidade com o código que espera 'lines', você pode fazer:
            $row['lines'] = $row['jam_lines_data'];
            unset($row['jam_lines_data']);
        }

        return $results;

    } catch (PDOException $e) {
        echo "Erro na função getLive: " . $e->getMessage() . "<br>";
        echo "Query: " . $query . "<br>";
        if ($id_parceiro !== 99 && $id_parceiro !== null) {
            echo "id_parceiro: " . $id_parceiro . "<br>";
        }
        return [];
    }
}

// Exemplo em backend/dashboard.php
$data = [
    'accidentAlerts' => getAccidentAlerts($pdo, $id_parceiro),
    'jamAlerts' => getJamAlerts($pdo, $id_parceiro),
    'jamLive' => getLive($pdo, $id_parceiro),
    -//'activeDrivers' => getdrivers($pdo, $id_parceiro), // Você pode implementar a lógica para buscar motoristas ativos aqui
];

// Você pode passar $data para o seu template Twig aqui
// echo $twig->render('dashboard.html.twig', $data);

// Para fins de demonstração, vamos apenas imprimir o array de dados
//print_r($data);
?>