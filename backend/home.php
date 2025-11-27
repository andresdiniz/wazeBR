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

function getdrivers(PDO $pdo, ?int $id_parceiro = null): array
{
    $query = "SELECT
        u.id_parceiro,
        SUM(u.wazers_count) AS total_wazers_impactados,
        u.created_at AS ultima_coleta_dados
    FROM
        users_on_jams u
    INNER JOIN (
        SELECT
            id_parceiro,
            MAX(created_at) AS max_created_at
        FROM
            users_on_jams
        GROUP BY
            id_parceiro
    ) AS ultimos_registros
    ON
        u.id_parceiro = ultimos_registros.id_parceiro AND u.created_at = ultimos_registros.max_created_at";

    // Adiciona a condição WHERE antes do GROUP BY e ORDER BY
    if ($id_parceiro !== 99 && $id_parceiro !== null) {
        $query .= " WHERE u.id_parceiro = :id_parceiro";
    }

    $query .= " GROUP BY u.id_parceiro, u.created_at
    ORDER BY
        u.id_parceiro, u.created_at DESC";

    try {
        $stmt = $pdo->prepare($query);

        if ($id_parceiro !== 99 && $id_parceiro !== null) {
            $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        }

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);   
        // Filtra os resultados para remover aqueles onde total_wazers_impactados é zero
        $filteredResults = array_filter($results, function($row) {
            return $row['total_wazers_impactados'] > 0;
        });

        // Reindexa o array para garantir que ele seja um array numérico contínuo
        return array_values($filteredResults);
    } catch (PDOException $e) {
        echo "Erro na função getdrivers: " . $e->getMessage() . "<br>";
        echo "Query: " . $query . "<br>";
        if ($id_parceiro !== 99 && $id_parceiro !== null) {
            echo "id_parceiro: " . $id_parceiro . "<br>";
        }
        return [];
    }
}

// Adicionar esta função em home.php
/**
 * Busca o resumo total de congestionamento em km por nível.
 * @param PDO $pdo Conexão PDO.
 * @param int|null $id_parceiro ID do parceiro.
 * @return array Resumo do congestionamento (nível e total_km).
 */
function getCongestionSummary(PDO $pdo, ?int $id_parceiro = null): array {
    // Esta query assume que existe uma tabela 'jams' com as colunas 'level' e 'length_km'
    $query = "
        SELECT
            jams.level,
            COALESCE(ROUND(SUM(jams.length_km), 2), 0) AS total_km
        FROM
            jams
        WHERE
            jams.is_archived = 0
            " . (($id_parceiro !== 99 && $id_parceiro !== null) ? " AND jams.id_parceiro = :id_parceiro " : "") . "
        GROUP BY
            jams.level
        ORDER BY
            jams.level DESC;
    ";

    try {
        $stmt = $pdo->prepare($query);

        if ($id_parceiro !== 99 && $id_parceiro !== null) {
            $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        }

        $stmt->execute();
        $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Garante que todos os níveis (1 a 5) estejam presentes com 0 km se não houver dados
        $full_summary = [];
        for ($i = 5; $i >= 1; $i--) {
            $found = false;
            foreach ($summary as $row) {
                if ((int)$row['level'] === $i) {
                    $full_summary[] = $row;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $full_summary[] = ['level' => (string)$i, 'total_km' => 0.00];
            }
        }
        
        return $full_summary;

    } catch (PDOException $e) {
        // Log de erro
        return [];
    }
}

function getKms(PDO $pdo, ?int $id_parceiro = null): float {
    // 1. TRATAMENTO DE CASO: Se o ID do parceiro for nulo ou inválido,
    // podemos retornar 0.0 ou buscar o KM total global (se existir).
    if (is_null($id_parceiro) || $id_parceiro <= 0) {
        // Se este for o KM TOTAL FIXO de todas as vias (baseado no seu problema GeoJSON),
        // e você tiver uma linha na tabela 'parceiros' com id=1 para representar o total, use 1.
        // Caso contrário, retorne 0.0.
        return 0.0; 
    }

    $sqlkms = "SELECT 
                 total_kms AS total_km 
               FROM 
                 parceiros 
               WHERE 
                 id_parceiro = :id_parceiro";

    // 2. PREPARAR: Prepara a instrução SQL
    $stmt = $pdo->prepare($sqlkms);

    // 3. VINCULAR PARÂMETRO: Associa o valor de $id_parceiro ao placeholder :id_parceiro
    // PDO::PARAM_INT garante que o valor é tratado como um inteiro
    $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);

    // 4. EXECUTAR: Roda a consulta no banco de dados
    if (!$stmt->execute()) {
        // Opcional: Se a execução falhar, você pode logar o erro
        // error_log("Erro ao executar a consulta de KMs: " . print_r($stmt->errorInfo(), true));
        return 0.0;
    }

    // 5. BUSCAR O RESULTADO: Recupera o valor da primeira coluna (total_km)
    // PDO::FETCH_COLUMN é a forma mais eficiente para buscar um único valor
    $kmTotal = $stmt->fetchColumn();
    
    // O fetchColumn retorna 'false' se não houver resultado.
    // Usamos o operador de coalescência nula (??) ou a verificação 'false'
    // para garantir que sempre retornamos um float (0.0 se não encontrado).
    return $kmTotal !== false ? (float) $kmTotal : 0.0;
}

$data = [
    'accidentAlerts' => getAccidentAlerts($pdo, $id_parceiro),
    'jamAlerts' => getJamAlerts($pdo, $id_parceiro),
    'jamLive' => getLive($pdo, $id_parceiro),
    'activeDrivers' => getdrivers($pdo, $id_parceiro), // Agora esta função utiliza a consulta para wazers impactados
    'congestion_summary' => getCongestionSummary($pdo, $id_parceiro), // <--- NOVO
    'total_kms' => getKms($pdo, $id_parceiro), // <--- NOVO
];

// Você pode passar $data para o seu template Twig aqui
// echo $twig->render('dashboard.html.twig', $data);

// Para fins de demonstração, vamos apenas imprimir o array de dados
//print_r($data['activeDrivers']);
?>
