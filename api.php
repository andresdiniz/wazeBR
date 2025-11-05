<?php

// Verifica se o arquivo .env existe no caminho especificado
$envPath = __DIR__ . '/.env';  // Corrigido o caminho

require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/functions/scripts.php'; // Funções de suporte

require_once __DIR__ . '/config/configbd.php'; // Configuração de dados basicos do sistema


use Dotenv\Dotenv;

if (!file_exists($envPath)) {
    die("Arquivo .env não encontrado no caminho: $envPath");
}

try {
    // Certifique-se de que o caminho do .env está correto
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    // Em caso de erro, logar o erro no arquivo de log
    error_log("Erro ao carregar o .env: " . $e->getMessage()); // Usando error_log para garantir que o erro seja registrado4
    logEmail("error", "Erro ao carregar o .env: " . $e->getMessage());
    die("Erro ao carregar o .env: " . $e->getMessage());
}

// Configura o ambiente de debug com base na variável DEBUG do .env
if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] == 'true') {
    // Configura as opções de log para ambiente de debug

    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/debug.log');

    // Cria o diretório de logs se não existir
    if (!is_dir(__DIR__ . '/../logs')) {
        mkdir(__DIR__ . '/../logs', 0777, true);
    }
}

header('Content-Type: application/json'); // Set the response content type to JSON

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once './config/configbd.php'; // Configuração do banco de dados

    // Obtém o tipo de ação dos parâmetros GET
    $action = $_GET['action'] ?? null;

    switch ($action) {

        case 'obter_usuario':
            session_start();

            // Verifica se o ID do usuário foi fornecido
            if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
                echo json_encode(['success' => false, 'message' => 'ID do usuário inválido.']);
                exit;
            }

            $userId = $_GET['id'];

            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
                $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
                $stmt->execute();

                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    echo json_encode(['success' => true, 'user' => $user]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Usuário não encontrado.']);
                }
            } catch (PDOException $e) {
                // Logar o erro em vez de mostrá-lo
                error_log("Erro no banco de dados: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Erro interno. Por favor, tente novamente.']);
            }

            break;

        case 'get_users':
            session_start();

            $search = $_GET['q'] ?? '';
            $search = trim($search);
            $id_parceiro = $_GET['id_parceiro'] ?? '';

            if (!$id_parceiro || !is_numeric($id_parceiro)) {
                echo json_encode(['success' => false, 'message' => 'ID do parceiro da sessão inválido.']);
                exit;
            }

            if (strlen($search) < 3) {
                echo json_encode(['success' => false, 'message' => 'Digite ao menos 3 caracteres para busca.']);
                exit;
            }

            try {
                $pdo = Database::getConnection();

                $sql = "
                    SELECT id, nome, username, email 
                    FROM users 
                    WHERE id_parceiro = :id_parceiro 
                    AND (username LIKE :search1 OR email LIKE :search2) 
                    LIMIT 100
                ";
                $stmt = $pdo->prepare($sql);
                $likeSearch = '%' . $search . '%';
                $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
                $stmt->bindParam(':search1', $likeSearch, PDO::PARAM_STR);
                $stmt->bindParam(':search2', $likeSearch, PDO::PARAM_STR);

                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if ($results) {
                    echo json_encode(['success' => true, 'users' => $results]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Nenhum usuário encontrado.']);
                }
            } catch (PDOException $e) {
                $logFile = $_SERVER['DOCUMENT_ROOT'] . '/logs/erros.log'; // Caminho absoluto seguro

                $timestamp = date('Y-m-d H:i:s');
                $message = "[$timestamp] Erro ao buscar usuário no sistema.\n";
                $message .= "[$timestamp] Erro PDO: " . $e->getMessage() . "\n";

                error_log($message, 3, $logFile);

                echo json_encode(['success' => false, 'message' => 'Erro interno. Tente novamente mais tarde.' . $e->getMessage()]);
            }


            break;

        case 'get_route_lines':
            // Verifica se o parâmetro 'route_id' foi fornecido
            if (!isset($_GET['route_id'])) {
                http_response_code(400); // Código HTTP 400: Bad Request
                echo json_encode(['error' => 'O parâmetro route_id é obrigatório.']);
                exit;
            }

            $routeId = trim($_GET['route_id']);
            if (empty($routeId)) {
                http_response_code(400);
                echo json_encode(['error' => 'O parâmetro route_id não pode estar vazio.']);
                exit;
            }

            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("SELECT * FROM `route_lines` WHERE `route_id` LIKE :route_id");
                $stmt->bindParam(':route_id', $routeId, PDO::PARAM_STR);
                $stmt->execute();
                $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$lines) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Nenhuma linha encontrada para o route_id fornecido.']);
                    exit;
                }

                echo json_encode($lines);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao acessar o banco de dados.', 'details' => $e->getMessage()]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Ocorreu um erro inesperado.', 'details' => $e->getMessage()]);
            }
            break;

        //Busca coordenadas de irrgularidades
        case 'get_route_lines_irre':
            // Verifica se o parâmetro 'route_id_irre' foi fornecido
            if (!isset($_GET['route_id_irre'])) {
                http_response_code(400); // Código HTTP 400: Bad Request
                echo json_encode(['error' => 'O parâmetro route_id_irre é obrigatório.']);
                exit;
            }

            $routeId = trim($_GET['route_id_irre']);
            if (empty($routeId)) {
                http_response_code(400);
                echo json_encode(['error' => 'O parâmetro route_id_irre não pode estar vazio.']);
                exit;
            }

            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("SELECT * FROM `route_lines` WHERE `irregularity_id` LIKE :route_id_irre");
                $stmt->bindParam(':route_id_irre', $routeId, PDO::PARAM_STR);
                $stmt->execute();
                $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$lines) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Nenhuma linha encontrada para o irregularity_id fornecido.']);
                    exit;
                }

                echo json_encode($lines);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao acessar o banco de dados.', 'details' => $e->getMessage()]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Ocorreu um erro inesperado.', 'details' => $e->getMessage()]);
            }
            break;

        case 'get_alerts':
            try {
                $pdo = Database::getConnection();
                // Agora, você pode acessar $_SESSION para obter o 'id_parceiro'
                $idParceiro = $_GET['id_parceiro'] ?? 99;

                // Recupera as datas e o agrupamento do parâmetro GET
                $startDate = $_GET['start_date'] ?? date('Y-m-01');
                $endDate = $_GET['end_date'] ?? date('Y-m-d');
                $groupBy = $_GET['group_by'] ?? 'date'; // Agrupamento pode ser 'date', 'hour' ou 'street'

                // Verifica se as datas estão no formato correto
                if (!strtotime($startDate) || !strtotime($endDate)) {
                    echo json_encode(['error' => 'Datas inválidas.']);
                    exit;
                }

                // Consulta SQL com base no agrupamento escolhido
                if ($groupBy == 'date') {
                    // Ajuste na consulta para considerar o id_parceiro apenas se não for 99
                    $sql = "SELECT 
                                        DATE(date_received) AS alert_date,
                                        COUNT(*) AS count
                                    FROM alerts 
                                    WHERE type = 'ACCIDENT'
                                    AND date_received BETWEEN :start_date AND :end_date";

                    if ($idParceiro != 99) {
                        $sql .= " AND id_parceiro = :id_parceiro"; // Filtra pelo id_parceiro se não for 99
                    }

                    $sql .= " GROUP BY alert_date
                                      ORDER BY alert_date DESC";

                    $stmt = $pdo->prepare($sql);
                } elseif ($groupBy == 'hour') {
                    $sql = "SELECT 
                                        HOUR(date_received) AS alert_hour,
                                        COUNT(*) AS count
                                    FROM alerts 
                                    WHERE type = 'ACCIDENT'
                                    AND date_received BETWEEN :start_date AND :end_date";

                    if ($idParceiro != 99) {
                        $sql .= " AND id_parceiro = :id_parceiro";
                    }

                    $sql .= " GROUP BY alert_hour
                                      ORDER BY alert_hour ASC"; // Ordena pela hora de forma ascendente                    

                    $stmt = $pdo->prepare($sql);
                } elseif ($groupBy == 'street') {
                    $sql = "SELECT 
                                        street, 
                                        COUNT(*) AS count
                                    FROM alerts 
                                    WHERE type = 'ACCIDENT' 
                                    AND date_received BETWEEN :start_date AND :end_date";

                    if ($idParceiro != 99) {
                        $sql .= " AND id_parceiro = :id_parceiro";
                    }

                    $sql .= " GROUP BY street
                                      ORDER BY count DESC";

                    $stmt = $pdo->prepare($sql);
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Parâmetro "group_by" inválido.']);
                    exit;
                }

                // Vincula os parâmetros de data
                $stmt->bindValue(':start_date', $startDate);
                $stmt->bindValue(':end_date', $endDate);

                if ($idParceiro != 99) {
                    // Vincula o id_parceiro, se necessário
                    $stmt->bindValue(':id_parceiro', $idParceiro, PDO::PARAM_INT);
                }

                $stmt->execute();

                $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$alerts) {
                    echo json_encode(['error' => 'Nenhum alerta encontrado para as datas selecionadas.', 'details' => $stmt->errorInfo(), 'query' => $stmt->queryString]);
                    exit;
                }

                // Organiza os dados para o frontend
                $labels = [];
                $data_counts = [];

                foreach ($alerts as $alert) {
                    if ($groupBy == 'date') {
                        $labels[] = $alert['alert_date']; // Data no formato 'YYYY-MM-DD'
                    } elseif ($groupBy == 'hour') {
                        // Formata a hora como 'YYYY-MM-DD HH:00'
                        $labels[] = $startDate . ' ' . str_pad($alert['alert_hour'], 2, '0', STR_PAD_LEFT) . ':00';
                    } elseif ($groupBy == 'street') {
                        $labels[] = $alert['street']; // Nome da rua
                    }
                    $data_counts[] = $alert['count'];
                }

                // Retorna os dados para o frontend
                echo json_encode(['alerts' => $alerts, 'labels' => $labels, 'data_counts' => $data_counts]);

            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao acessar o banco de dados.', 'details' => $e->getMessage()]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Erro inesperado.', 'details' => $e->getMessage()]);
            }
            break;

        case 'get_subroutes':
            // Verifica se o parâmetro 'route_id' foi fornecido
            if (!isset($_GET['route_id'])) {
                http_response_code(400); // Código HTTP 400: Bad Request
                echo json_encode(['error' => 'O parâmetro route_id é obrigatório.']);
                exit;
            }

            $routeId = trim($_GET['route_id']);
            if (empty($routeId)) {
                http_response_code(400);
                echo json_encode(['error' => 'O parâmetro route_id não pode estar vazio.']);
                exit;
            }

            try {
                $pdo = Database::getConnection();

                // Consulta para pegar as subrotas ativas (is_active = 1) para o route_id
                $stmt = $pdo->prepare("
                            SELECT 
                                id, 
                                route_id,
                                avg_speed,
                                bbox_min_x, 
                                bbox_min_y, 
                                bbox_max_x, 
                                bbox_max_y
                            FROM subroutes 
                            WHERE route_id = :route_id AND is_active = 1
                            ORDER BY bbox_min_x ASC  -- Ordena as subrotas pela coordenada X mínima em ordem crescente
                        ");
                $stmt->bindParam(':route_id', $routeId, PDO::PARAM_STR);
                $stmt->execute();

                $subroutes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$subroutes) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Nenhuma subrota ativa encontrada para o route_id fornecido.']);
                    exit;
                }

                // Array para armazenar os dados de retorno
                $response = [];

                // Itera sobre cada subrota
                foreach ($subroutes as $subroute) {
                    $bbox_min_x = $subroute['bbox_min_x'];
                    $bbox_min_y = $subroute['bbox_min_y'];
                    $bbox_max_x = $subroute['bbox_max_x'];
                    $bbox_max_y = $subroute['bbox_max_y'];

                    // Consulta para pegar os pontos dentro da caixa (bbox) da tabela route_lines
                    $stmtPoints = $pdo->prepare("
                                SELECT x, y
                                FROM route_lines
                                WHERE route_id = :route_id 
                                AND x BETWEEN :bbox_min_x AND :bbox_max_x
                                AND y BETWEEN :bbox_min_y AND :bbox_max_y
                            ");
                    $stmtPoints->bindParam(':route_id', $routeId, PDO::PARAM_STR);
                    $stmtPoints->bindParam(':bbox_min_x', $bbox_min_x, PDO::PARAM_STR);
                    $stmtPoints->bindParam(':bbox_min_y', $bbox_min_y, PDO::PARAM_STR);
                    $stmtPoints->bindParam(':bbox_max_x', $bbox_max_x, PDO::PARAM_STR);
                    $stmtPoints->bindParam(':bbox_max_y', $bbox_max_y, PDO::PARAM_STR);
                    $stmtPoints->execute();

                    $routePoints = $stmtPoints->fetchAll(PDO::FETCH_ASSOC);

                    // Adiciona os dados da subrota ao array de resposta
                    $response[] = [
                        'subroute_id' => $subroute['id'],
                        'route_id' => $subroute['route_id'],
                        'avg_speed' => $subroute['avg_speed'],
                        'bbox' => [
                            'min_x' => $bbox_min_x,
                            'min_y' => $bbox_min_y,
                            'max_x' => $bbox_max_x,
                            'max_y' => $bbox_max_y
                        ],
                        'route_points' => $routePoints // Pontos dentro da caixa
                    ];
                }

                // Retorna a resposta em formato JSON
                echo json_encode($response);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao acessar o banco de dados.', 'details' => $e->getMessage()]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Ocorreu um erro inesperado.', 'details' => $e->getMessage()]);
            }
            break;

        case 'get_roles':
            // Busca alertas de buracos na estrada (tipo 'HAZARD_ON_ROAD_POT_HOLE')
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');

            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("
                    SELECT street, COUNT(*) AS count
                    FROM alerts 
                    WHERE type = 'HAZARD' 
                    AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'
                    AND date_received BETWEEN :start_date AND :end_date
                    GROUP BY street
                    ORDER BY count DESC
                ");
                $stmt->bindValue(':start_date', $startDate);
                $stmt->bindValue(':end_date', $endDate);
                $stmt->execute();

                $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$roles) {
                    echo json_encode(['error' => 'Nenhum alerta de buraco encontrado para as datas selecionadas.']);
                    exit;
                }

                // Retorna os alertas agrupados por rua
                echo json_encode(['roles' => $roles]);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao acessar o banco de dados.', 'details' => $e->getMessage()]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Erro inesperado.', 'details' => $e->getMessage()]);
            }
            break;

        case 'get_street':
            // Função para obter informações da rua da API do Waze
            function getStreetInfo($lat, $lon)
            {
                $token = '11682863520_cadcbae577c7f1a01263851644ea59827896751b'; // Insira seu token válido aqui
                $url = "https://www.waze.com/row-partnerhub-api/waze-map/streetsInfo?lat=" . urlencode($lat) . "&lon=" . urlencode($lon) . "&token=" . urlencode($token);

                // Usando cURL para obter os dados da API
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);  // Tempo limite de 30 segundos para a requisição
                $response = curl_exec($ch);

                // Verifique se ocorreu algum erro durante a requisição cURL
                if (curl_errno($ch)) {
                    $error_msg = curl_error($ch);
                    curl_close($ch);
                    return ['error' => 'Erro na requisição cURL: ' . $error_msg];
                }

                curl_close($ch);

                // Decodifique o JSON da resposta
                return json_decode($response, true);
            }

            // Obtém as coordenadas do parâmetro da requisição
            $latInicio = isset($_GET['lat_inicio']) ? $_GET['lat_inicio'] : null;
            $lonInicio = isset($_GET['lon_inicio']) ? $_GET['lon_inicio'] : null;

            // Verifique se as coordenadas são válidas
            if (empty($latInicio) || empty($lonInicio)) {
                echo json_encode(["error" => "Coordenadas de início não fornecidas corretamente."]);
                exit();
            }

            // Obtenha os dados da rua para as coordenadas de início
            $streetDataInicio = getStreetInfo($latInicio, $lonInicio);

            // Verifique se houve erro na requisição
            if (isset($streetDataInicio['error'])) {
                echo json_encode($streetDataInicio);
                exit();
            }

            $streetsInicio = isset($streetDataInicio['result']) ? $streetDataInicio['result'] : [];

            // Retorne os resultados em JSON
            echo json_encode(["result" => $streetsInicio]);

            break;

        case 'get_parceiros':
            session_start();

            // Pega o valor de sessionType da URL
            $id_parceiro = $_GET['sessionType'] ?? null;

            if (!isset($id_parceiro) || !is_numeric($id_parceiro)) {
                echo json_encode(['success' => false, 'message' => 'Id do parceiro inválido ou não localizado']);
                exit;
            }

            try {
                $pdo = Database::getConnection();
                $response = [];

                if ($id_parceiro == 1) {
                    // Consulta para todos os parceiros com limite
                    $sql = "SELECT id, Nome FROM parceiros LIMIT 100";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute();

                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $response = $results ?
                        ['success' => true, 'nomes' => $results] :
                        ['success' => false, 'message' => 'Nenhum parceiro encontrado'];
                } else {
                    // Consulta para um parceiro específico
                    $sql = "SELECT id, Nome FROM parceiros WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindParam(':id', $id_parceiro, PDO::PARAM_INT);
                    $stmt->execute();

                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $response = $result ?
                        ['success' => true, 'nomes' => [$result]] :
                        ['success' => false, 'message' => 'Parceiro não encontrado'];
                }

                echo json_encode($response);
            } catch (PDOException $e) {
                // Logar o erro em vez de mostrá-lo
                error_log("Erro no banco de dados: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Erro interno. Por favor, tente novamente.']);
            }

            break;

        case 'update_user':
            session_start();
            $data = json_decode(file_get_contents('php://input'), true);

            $id = $data['id'] ?? null;
            $username = $data['username'] ?? null;
            $email = $data['email'] ?? null;

            if (!$id || !$username || !$email) {
                echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
                exit;
            }

            try {
                $pdo = Database::getConnection();
                $sql = "UPDATE users SET username = :username, email = :email WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt->bindParam(':username', $username, PDO::PARAM_STR);
                $stmt->bindParam(':email', $email, PDO::PARAM_STR);
                $stmt->execute();

                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                // Logar o erro em vez de mostrá-lo
                error_log("Erro no banco de dados: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Erro interno. Por favor, tente novamente.']);
            }

            break;

        case 'criar_evento':
            // Verifica se todos os parâmetros obrigatórios estão presentes
            if (
                empty($_GET['descricao']) || empty($_GET['tipo']) ||
                empty($_GET['subtipo']) || empty($_GET['coordenadas']) ||
                empty($_GET['rua']) || empty($_GET['starttime']) ||
                empty($_GET['endtime'])
            ) {
                http_response_code(400); // Código HTTP 400: Bad Request
                echo json_encode(['error' => 'Todos os campos obrigatórios devem ser preenchidos.']);
                exit;
            }

            $descricao = $_GET['descricao'];
            $tipo = $_GET['tipo'];
            $subtipo = $_GET['subtipo'];
            $coordenadas = $_GET['coordenadas']; // Coordenadas no formato "-20.676696, -44.058083"
            $rua = $_GET['rua'];
            $direcao = 'ONE_DIRECTION'; // Defina um padrão ou ajuste para aceitar da URL
            $starttime = $_GET['starttime']; // Data e hora de início
            $endtime = $_GET['endtime']; // Data e hora de fim

            // Converter coordenadas para JSON
            try {
                $coordParts = explode(',', $coordenadas);
                if (count($coordParts) != 2) {
                    throw new Exception('Formato de coordenadas inválido.');
                }
                $latitude = trim($coordParts[0]);
                $longitude = trim($coordParts[1]);
                $coordenadasJson = json_encode([['lat' => $latitude, 'lng' => $longitude]]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => 'Coordenadas inválidas.', 'details' => $e->getMessage()]);
                exit;
            }

            try {
                // Conecte-se ao banco de dados
                $pdo = Database::getConnection();

                // SQL para inserir o evento
                $stmt = $pdo->prepare("
                    INSERT INTO events (
                        parent_event_id, creationtime, updatetime, type, subtype, description, 
                        street, polyline, direction, starttime, endtime
                    ) VALUES (
                        NULL, NOW(), NOW(), :tipo, :subtipo, :descricao, 
                        :rua, :coordenadas, :direcao, :starttime, :endtime
                    )
                ");

                // Bind dos parâmetros
                $stmt->bindParam(':tipo', $tipo);
                $stmt->bindParam(':subtipo', $subtipo);
                $stmt->bindParam(':descricao', $descricao);
                $stmt->bindParam(':rua', $rua);
                $stmt->bindParam(':coordenadas', $coordenadasJson);
                $stmt->bindParam(':direcao', $direcao);
                $stmt->bindParam(':starttime', $starttime);
                $stmt->bindParam(':endtime', $endtime);

                // Executar a query
                $stmt->execute();

                // Se a inserção for bem-sucedida, retorna uma resposta de sucesso
                echo json_encode(['success' => 'Evento criado com sucesso!']);
            } catch (PDOException $e) {
                // Em caso de erro no banco de dados
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao salvar evento.', 'details' => $e->getMessage()]);
            } catch (Exception $e) {
                // Em caso de erro genérico
                http_response_code(500);
                echo json_encode(['error' => 'Ocorreu um erro inesperado.', 'details' => $e->getMessage()]);
            }
            break;

        case 'alterar':
            session_start();

            // Pega o valor da query da URL
            $query = $_GET['query'] ?? '';

            if (empty($query)) {
                echo json_encode(['success' => false, 'message' => 'Query não fornecida']);
                exit;
            }

            try {
                $pdo = Database::getConnection();
                $sql = "SELECT * FROM users WHERE username LIKE :query OR email LIKE :query LIMIT 10";
                $stmt = $pdo->prepare($sql);
                $searchQuery = '%' . $query . '%';
                $stmt->bindParam(':query', $searchQuery, PDO::PARAM_STR);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response = $results ?
                    ['success' => true, 'users' => $results] :
                    ['success' => false, 'message' => 'Nenhum usuário encontrado'];

                echo json_encode($response);
            } catch (PDOException $e) {
                // Logar o erro em vez de mostrá-lo
                error_log("Erro no banco de dados: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Erro interno. Por favor, tente novamente.']);
            }

            break;

        case 'traduzir':
            try {
                // Verificar se os parâmetros foram enviados
                if (!isset($_GET['tipo']) || !isset($_GET['subtipo'])) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Parâmetros tipo e subtipo são obrigatórios."
                    ]);
                    exit;
                }

                $tipo = $_GET['tipo'];
                $subtipo = $_GET['subtipo'];

                // Verificar se a função existe antes de chamá-la
                if (!function_exists('traduzirAlerta')) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Função traduzirAlerta não encontrada."
                    ]);
                    exit;
                }

                // Chamar a função para traduzir
                $alertaTraduzido = traduzirAlerta($tipo, $subtipo);

                // Verificar se obteve um resultado válido
                if (!$alertaTraduzido) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Nenhuma tradução encontrada para o alerta.",
                        "tipo" => $tipo,
                        "subtipo" => $subtipo
                    ]);
                    exit;
                }

                // Retornar a tradução em JSON
                echo json_encode([
                    "success" => true,
                    "tipo" => $alertaTraduzido["tipo"],
                    "subtipo" => $alertaTraduzido["subtipo"]
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    "success" => false,
                    "message" => "Erro interno no servidor: " . $e->getMessage()
                ]);
            }
            break;

        case 'get_jams_details':

            $routeId = $_GET['route_id'] ?? null;

            if (!isset($_GET['route_id']) || empty(trim($routeId))) {
                sendErrorResponse('O parâmetro route_id é obrigatório e não pode estar vazio.', 400);
            }

            $routeId = trim($routeId);

            try {
                $pdo = Database::getConnection();

                // Consulta principal do jam
                $stmtJam = $pdo->prepare("
                        SELECT * FROM jams WHERE uuid = :route_id
                    ");
                $stmtJam->execute([':route_id' => $routeId]);
                $jam = $stmtJam->fetch(PDO::FETCH_ASSOC);

                if (!$jam) {
                    sendErrorResponse('Nenhum congestionamento encontrado para a rota fornecida.', 404);
                }

                // Consulta de segmentos
                $stmtSegments = $pdo->prepare("
                        SELECT * FROM jam_segments WHERE jam_uuid = :route_id ORDER BY id
                    ");
                $stmtSegments->execute([':route_id' => $routeId]);
                $segments = $stmtSegments->fetchAll(PDO::FETCH_ASSOC);

                // Consulta de linhas
                $stmtLines = $pdo->prepare("
                        SELECT * FROM jam_lines WHERE jam_uuid = :route_id ORDER BY sequence
                    ");
                $stmtLines->execute([':route_id' => $routeId]);
                $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

                // Monta resposta
                $response = [
                    'jam' => $jam,
                    'segments' => $segments,
                    'lines' => $lines,
                ];

                sendSuccessResponse($response);

            } catch (Exception $e) {
                sendErrorResponse('Erro interno: ' . $e->getMessage(), 500);
            }

            break;

        case 'get_route_details':
            $routeId = $_GET['route_id'] ?? null;

            if (!$routeId || trim($routeId) === '') {
                sendErrorResponse('Parâmetro "route_id" é obrigatório', 400);
            }

            $routeId = trim($routeId);

            try {
                $pdo = Database::getConnection();

                // 1. Detalhes gerais da rota
                $stmtRoute = $pdo->prepare("
                        SELECT *, 
                            (historic_time - avg_time) AS delay_seconds,
                            (historic_speed - avg_speed) AS speed_diff
                        FROM routes 
                        WHERE id = :route_id
                    ");
                $stmtRoute->execute([':route_id' => $routeId]);
                $overallStats = $stmtRoute->fetch(PDO::FETCH_ASSOC);

                if (!$overallStats) {
                    sendErrorResponse('Rota não encontrada', 404);
                }

                // Conversão de valores numéricos
                $overallStats = array_map(function ($value) {
                    return is_numeric($value) ? (float) $value : $value;
                }, $overallStats);

                // 2. Geometria da rota
                $stmtGeometry = $pdo->prepare("
                        SELECT x, y 
                        FROM route_lines 
                        WHERE route_id = :route_id 
                        ORDER BY id
                    ");
                $stmtGeometry->execute([':route_id' => $routeId]);
                $routeGeometry = $stmtGeometry->fetchAll(PDO::FETCH_ASSOC);

                // 3. Histórico de desempenho da rota
                $stmtHistory = $pdo->prepare("
                        SELECT data, velocidade, tempo
                        FROM historic_routes
                        WHERE route_id = :route_id
                        ORDER BY data DESC
                    ");
                $stmtHistory->execute([':route_id' => $routeId]);
                $historicData = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

                // 4. Subrotas da rota
                $stmtSubroutes = $pdo->prepare("
                        SELECT * 
                        FROM subroutes 
                        WHERE route_id = :route_id
                    ");
                $stmtSubroutes->execute([':route_id' => $routeId]);
                $subroutes = $stmtSubroutes->fetchAll(PDO::FETCH_ASSOC);

                // 4. Subrotas ativas da rota
                $stmtSubroutes = $pdo->prepare("
                    SELECT * 
                    FROM subroutes 
                    WHERE route_id = :route_id
                    AND is_active = 1
                    ");
                $stmtSubroutes->execute([':route_id' => $routeId]);
                $subroutes = $stmtSubroutes->fetchAll(PDO::FETCH_ASSOC);


                // 5. Heatmap baseado em velocidades históricas
                $stmtHeatmap = $pdo->prepare("
                        SELECT 
                            DAYOFWEEK(data) AS day_of_week,
                            HOUR(data) AS hour,
                            AVG(velocidade) AS avg_speed,
                            COUNT(*) AS samples
                        FROM historic_routes
                        WHERE route_id = :route_id
                        GROUP BY day_of_week, hour
                    ");
                $stmtHeatmap->execute([':route_id' => $routeId]);
                $heatmap = $stmtHeatmap->fetchAll(PDO::FETCH_ASSOC);

                // Resposta final
                sendSuccessResponse([
                    'route' => $overallStats,
                    'geometry' => $routeGeometry,
                    'historic' => $historicData,
                    'subroutes' => $subroutes,
                    'heatmap' => $heatmap
                ]);

            } catch (Exception $e) {
                sendErrorResponse('Erro interno: ' . $e->getMessage(), 500);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Ação não reconhecida.']);
            break;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once './config/configbd.php'; // Configuração do banco de dados
    require_once './functions/scripts.php';

    // Obtém o tipo de ação dos parâmetros GET
    $action = $_GET['action'] ?? null;

    if (!$action) {
        http_response_code(400);
        echo json_encode(['error' => 'Parâmetro "action" não especificado.']);
        exit;
    }

    switch ($action) {

        case 'atualizaburaco':
            $id = $_POST['uuid'] ?? null;
            $statusInput = $_POST['status'] ?? null;

            // Verificação inicial
            if (!$id || !$statusInput) {
                http_response_code(400);
                echo json_encode(['error' => 'UUID e status são obrigatórios.']);
                exit;
            }

            // Validação de status permitido
            $statusMap = [
                'resolvido' => 'RESOLVED',
                'nao_existe' => 'NOT_EXIST',
                'nao_resolvido' => 'NOT_RESOLVED'
            ];

            if (!array_key_exists($statusInput, $statusMap)) {
                http_response_code(400);
                echo json_encode(['error' => 'Status inválido.']);
                exit;
            }

            $status = $statusMap[$statusInput];

            try {
                $pdo = Database::getConnection();
                $updateQuery = "UPDATE alerts SET confirmado = :status WHERE uuid = :uuid"; // Query corrigida
                $stmt = $pdo->prepare($updateQuery);
                $stmt->bindParam(':status', $status, PDO::PARAM_STR);
                $stmt->bindParam(':uuid', $id, PDO::PARAM_STR);
                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => 'Status atualizado com sucesso.']);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Nenhum alerta encontrado com o UUID fornecido.']);
                }
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Erro no banco de dados.', 'details' => $e->getMessage()]);
            }
            break;

        // Sua lógica do switch case
        case 'cadastrar_usuario':
            // Lógica para cadastrar usuário
            // Use trim() para remover espaços em branco no início/fim de strings
            // Use intval() para id_parceiro, pois é um número

            $email = trim($_POST['email'] ?? '');
            $nome = trim($_POST['nome'] ?? '');
            $username = trim($_POST['usuario'] ?? '');
            $id_parceiro = isset($_POST['id_parceiro']) ? intval($_POST['id_parceiro']) : null; // Converte para int e trata o null
            $password = $_POST['senha'] ?? ''; // Não use trim() em senha se o espaço for parte da senha
            $type = trim($_POST['type'] ?? '');

            // Define uma imagem padrão para o campo 'photo'
            $photo = 'https://via.placeholder.com/32'; // URL de imagem padrão

            // --- Validação Aprimorada e Mais Robusta ---
            $missing_fields = [];

            // Validar campos de texto (não podem ser vazios após trim)
            if (empty($email)) {
                $missing_fields[] = 'email';
            }
            if (empty($nome)) {
                $missing_fields[] = 'nome';
            }
            if (empty($username)) {
                $missing_fields[] = 'usuario';
            }
            if (empty($password)) { // Para senha, apenas verificar se está vazia, não se é 0
                $missing_fields[] = 'senha';
            }
            if (empty($type)) {
                $missing_fields[] = 'type';
            }

            // Validação específica para id_parceiro
            // Não podemos usar empty() para 0, pois 0 é um valor válido para um ID
            // Verificamos se foi enviado E se é um número inteiro válido maior ou igual a 0
            if ($id_parceiro === null || !is_int($id_parceiro) || $id_parceiro < 0) { // Pode ser 0, mas não pode ser null ou não-int
                $missing_fields[] = 'id_parceiro';
            }
            // Ou, se id_parceiro 0 NÃO FOR VÁLIDO e DEVE SER MAIOR QUE 0:
            // if ($id_parceiro === null || !is_int($id_parceiro) || $id_parceiro <= 0) {
            //     $missing_fields[] = 'id_parceiro';
            // }


            if (!empty($missing_fields)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Os seguintes campos são obrigatórios e não foram fornecidos ou são inválidos:',
                    'missing_fields' => $missing_fields
                ]);
                exit;
            }
            // --- Fim da Validação Aprimorada e Mais Robusta ---


            // Cadastrar no banco de dados
            try {
                $pdo = Database::getConnection();
                // Verifica se o e-mail já está registrado
                $sqlCheckEmail = "SELECT COUNT(*) FROM users WHERE email = :email";
                $stmtCheck = $pdo->prepare($sqlCheckEmail);
                $stmtCheck->bindParam(':email', $email, PDO::PARAM_STR);
                $stmtCheck->execute();
                $emailExists = $stmtCheck->fetchColumn();

                if ($emailExists > 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'O e-mail informado já está cadastrado.']);
                    exit;
                }
                // Prosseguir com a inserção do usuário
                $sql = "
            INSERT INTO users (email, nome, username, id_parceiro, password, photo, type)
            VALUES (:email, :nome, :username, :id_parceiro, :password, :photo, :type)
        ";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':email', $email, PDO::PARAM_STR);
                $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
                $stmt->bindParam(':username', $username, PDO::PARAM_STR);
                $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
                $stmt->bindParam(':password', password_hash($password, PASSWORD_DEFAULT), PDO::PARAM_STR);
                $stmt->bindParam(':photo', $photo, PDO::PARAM_STR);
                $stmt->bindParam(':type', $type, PDO::PARAM_STR);

                if ($stmt->execute()) {
                    // E-mail enviado após a criação do usuário
                    $userEmail = $email;
                    $userName = $nome;
                    $userPassword = $password; // ATENÇÃO: Ver observação de segurança sobre isso!
                    $loginLink = "https://wfcbrasil.com.br/";

                    $message = "
                <html>
                <head>
                    <title>Conta Criada com Sucesso</title>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            color: #333;
                            background-color: #f7f7f7;
                            margin: 0;
                            padding: 0;
                        }
                        .email-container {
                            width: 100%;
                            max-width: 600px;
                            margin: 0 auto;
                            background-color: #ffffff;
                            padding: 20px;
                            border-radius: 8px;
                            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
                        }
                        h2 {
                            color: #4CAF50;
                        }
                        p {
                            font-size: 16px;
                            line-height: 1.6;
                        }
                        a {
                            color: #4CAF50;
                            text-decoration: none;
                            font-weight: bold;
                        }
                        .button {
                            background-color: #4CAF50;
                            color: #ffffff;
                            padding: 10px 20px;
                            text-align: center;
                            border-radius: 5px;
                            text-decoration: none;
                            display: inline-block;
                        }
                        ul {
                            list-style-type: none;
                            padding: 0;
                        }
                        ul li {
                            margin-bottom: 8px;
                        }
                        footer {
                            margin-top: 20px;
                            font-size: 14px;
                            text-align: center;
                            color: #888;
                        }
                    </style>
                </head>
                <body>
                    <div class='email-container'>
                        <h2>Olá, $userName!</h2>
                        <p>Sua conta foi criada com sucesso! Agora você pode acessar a sua conta através do seguinte link:</p>
                        <p><a href='$loginLink' class='button'>Clique aqui para acessar sua conta</a></p>
                        <p><strong>Seus dados de login são:</strong></p>
                        <ul>
                            <li><strong>Email:</strong> $userEmail</li>
                            <li><strong>Senha:</strong> $userPassword</li>
                        </ul>
                        <p>Por favor, mantenha suas credenciais seguras.</p>
                        <p>Obrigado por se cadastrar conosco!</p>
                        <footer>
                            <p>&copy; " . date('Y') . " Sua Empresa. Todos os direitos reservados.</p>
                        </footer>
                    </div>
                </body>
                </html>
                ";
                    $subject = "Bem vindo!";
                    sendEmail($userEmail, $message, $subject);

                    http_response_code(200);
                    echo json_encode([
                        'success' => true,
                        'message' => 'Usuário cadastrado com sucesso. Um e-mail de boas-vindas foi enviado.',
                    ]);
                } else {
                    throw new Exception('Erro ao executar a inserção no banco de dados.');
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Erro interno do servidor: ' . $e->getMessage(),
                ]);
            }
            break;

        case 'cadastrar_evento':
            // Recebe os dados
            $description = $_POST['nome'] ?? null;
            $tipo = $_POST['tipo'] ?? null;
            $subtipo = $_POST['subtipo'] ?? null;
            $starttime = $_POST['starttime'] ?? null;
            $endtime = $_POST['endtime'] ?? null;
            $coordenadas = $_POST['coordenadas'] ?? null;         // AQUI: Lê 'coordenadas' (a chave que o PHP espera)
            $rua = $_POST['rua'] ?? null;                         // AQUI: Lê 'rua' (a chave que o PHP espera)
            $streetSegment = $_POST['streetSegment'] ?? null;     // AQUI: Lê 'streetSegment' (a chave que o PHP espera)
            $segmentDirection = $_POST['segmentDirection'] ?? null;// AQUI: Lê 'segmentDirection' (a chave que o PHP espera)
            $id_parceiro = $_POST['id_parceiro'] ?? null;

            if (empty($streetSegment)) {
                $streetSegment = $coordenadas;
            }
            if ($id_parceiro == null) {
                echo json_encode(['error' => 'Um parceiro deve ser escolhido.']);
                die();
            }

            // LISTAGEM ESPECÍFICA DOS CAMPOS FALTANTES
            $missingFields = [];
            if (!$description) $missingFields[] = 'description ($nome)';
            if (!$tipo) $missingFields[] = 'tipo';
            if (!$subtipo) $missingFields[] = 'subtipo'; // Subtipo é obrigatório, mas não estava na sua validação original.
            if (!$starttime) $missingFields[] = 'starttime';
            if (!$endtime) $missingFields[] = 'endtime';
            
            // VARIÁVEIS COM CHAVES ERRADAS (QUE FICARÃO NULL)
            if (!$coordenadas) $missingFields[] = 'coordenadas (Lido de $_POST[\'coordenadas\'])'; 
            if (!$rua) $missingFields[] = 'rua (Lido de $_POST[\'rua\'])';
            if (!$streetSegment) $missingFields[] = 'streetSegment (Lido de $_POST[\'streetSegment\'])';
            if (!$segmentDirection) $missingFields[] = 'segmentDirection (Lido de $_POST[\'segmentDirection\'])';

            if (!empty($missingFields)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Os seguintes campos obrigatórios estão faltando no POST ou estão vazios no PHP: ',
                    'missing' => $missingFields
                ]);
                exit;
            }

            // === Formatação da polyline ===
            // A coluna polyline deverá receber as coordenadas formatadas no padrão:
            // latitude1, longitude1, latitude2, longitude2, ... 
            // (Note que no JSON, cada par vem na ordem [longitude, latitude])
            $polylineFormatted = '';
            $coordsArray = json_decode($streetSegment, true);
            if (is_array($coordsArray) && !empty($coordsArray) && is_array($coordsArray[0])) { 
                // Lógica de JSON Decode (para segmento tipo 6)
                $formattedCoords = [];
                foreach ($coordsArray as $coord) {
                    if (is_array($coord) && count($coord) >= 2) {
                        // Inverte a ordem para [latitude, longitude]
                        $formattedCoords[] = $coord[1];
                        $formattedCoords[] = $coord[0];
                    }
                }
                $polylineFormatted = implode(', ', $formattedCoords);
            } elseif (is_string($streetSegment) && !empty($streetSegment)) {
                // Lógica para string simples 'lat,lon' (para ponto tipo 3)
                // ESTE BLOCO AGORA É ALCANÇADO COM SUCESSO DEVIDO AO FALLBACK
                $polylineFormatted = $streetSegment;
            } else {
                // Se, por alguma razão, o formato ainda estiver inválido
                http_response_code(400);
                echo json_encode(['error' => 'Formato inválido ou ausente para o segmento/polyline.']);
                exit;
            }

            // === Mapeamento do campo de direção ===
            // Se o valor de segmentDirection for "both" (ou similar), envia "BOTH_DIRECTION"
            // Caso contrário, utiliza "ONE_DIRECTION". Assim, "reversed" será mapeado para "ONE_DIRECTION".
            $directionValue = (strtolower($segmentDirection) === 'both') ? 'BOTH_DIRECTION' : 'ONE_DIRECTION';

            try {
                $pdo = Database::getConnection();

                $sqlconsult = "SELECT subtype_value FROM alert_subtype Where subtype_id=:subtipo";

                $stmtSubtype = $pdo->prepare($sqlconsult);
                // O valor de $subtipo aqui é o ID enviado pelo formulário (ex: '4')
                $stmtSubtype->bindParam(':subtipo', $subtipo, PDO::PARAM_STR); 
                if (!$stmtSubtype->execute()) {
                throw new Exception('Erro ao buscar o valor do subtipo no banco de dados.');
                }

                $subtypeResult = $stmtSubtype->fetch(PDO::FETCH_ASSOC);

                if (!$subtypeResult || !isset($subtypeResult['subtype_value'])) {
                // Trata o erro se o ID for inválido ou não existir
                throw new Exception('Subtipo (ID: ' . $subtipo . ') não encontrado ou inválido.');
                }
                //Substitui o ID pelo valor da string (ex: 'ROAD_CLOSURE')
                $subtipo = $subtypeResult['subtype_value'];

                // Inserção na tabela events (conforme o DESCRIBE fornecido)
                $sqlEvent = "
                        INSERT INTO events (
                            parent_event_id, creationtime, updatetime, description, type, subtype, street, polyline, direction, starttime, endtime, is_active,id_parceiro
                        )
                        VALUES (
                            NULL, NOW(), NOW(), :description, :type, :subtype, :street, :polyline, :direction, :starttime, :endtime, '1', :id_parceiro
                        )
                    ";
                $stmtEvent = $pdo->prepare($sqlEvent);
                $stmtEvent->bindParam(':type', $tipo, PDO::PARAM_STR);
                $stmtEvent->bindParam(':subtype', $subtipo, PDO::PARAM_STR);
                $stmtEvent->bindParam(':street', $rua, PDO::PARAM_STR);
                $stmtEvent->bindParam(':polyline', $polylineFormatted, PDO::PARAM_STR);
                $stmtEvent->bindParam(':starttime', $starttime, PDO::PARAM_STR);
                $stmtEvent->bindParam(':endtime', $endtime, PDO::PARAM_STR);
                $stmtEvent->bindParam(':direction', $directionValue, PDO::PARAM_STR);
                $stmtEvent->bindParam(':description', $description, PDO::PARAM_STR);
                $stmtEvent->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_STR);

                if ($stmtEvent->execute()) {
                    $eventId = $pdo->lastInsertId();

                    // ... (inserção de horários ou outras operações, se necessário)

                    http_response_code(200);
                    echo json_encode([
                        'success' => true,
                        'message' => 'Evento e horários cadastrados com sucesso.',
                    ]);
                    // Redireciona, se necessário
                    //header('Location: https://fenixsmm.store/wazeportal/create_alerts');
                    exit;
                } else {
                    throw new Exception('Erro ao inserir dados na tabela events.');
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Erro interno do servidor: ' . $e->getMessage(),
                ]);
            }
            break;


        // Após definir o tipo de conteúdo, envie o JSON de resposta
        case 'confirm_alert':
            // Antes de qualquer saída, defina o tipo de conteúdo
            header('Content-Type: application/json');
            // Recebe os dados do alerta
            $uuid = isset($_POST['uuid']) ? $_POST['uuid'] : '';
            $km = isset($_POST['km']) ? $_POST['km'] : '';  // KM é opcional
            $status = 1;  // Status do alerta (confirmado)
            $data_confirmado = date('Y-m-d H:i:s');  // Data e hora atual para a confirmação
            $pdo = Database::getConnection();

            // Verifica se o UUID foi enviado
            if (!empty($uuid)) {
                try {
                    // Prepara a query de atualização
                    if (!empty($km)) {
                        // Se o valor de $km foi enviado, inclui na query
                        $stmt = $pdo->prepare("UPDATE alerts SET confirmado = :confirmado, data_confirmado = :data_confirmado, km = :km WHERE uuid = :uuid");
                        $stmt->bindParam(':km', $km, PDO::PARAM_STR);  // Vincula o parâmetro km
                    } else {
                        // Se o valor de $km não foi enviado, não atualiza o campo km
                        $stmt = $pdo->prepare("UPDATE alerts SET confirmado = :confirmado, data_confirmado = :data_confirmado WHERE uuid = :uuid");
                    }

                    // Vincula os outros parâmetros
                    $stmt->bindParam(':confirmado', $status, PDO::PARAM_INT);
                    $stmt->bindParam(':data_confirmado', $data_confirmado, PDO::PARAM_STR);
                    $stmt->bindParam(':uuid', $uuid, PDO::PARAM_STR);

                    // Executa a query
                    if ($stmt->execute()) {
                        //logToFile('success', "Alerta confirmado com sucesso: $uuid");
                        http_response_code(200);  // Retorna o código 200 (sucesso)
                        echo json_encode(["success" => true, "message" => "Alerta confirmado com sucesso!"]);
                    } else {
                        logToFile('error', "Alerta não confirmado: $uuid");
                        http_response_code(400);  // Retorna o código 400 (erro)
                        echo json_encode(["success" => false, "message" => "Erro ao confirmar o alerta. Tente novamente."]);
                    }
                } catch (PDOException $e) {
                    // Se ocorrer erro, exibe a mensagem
                    http_response_code(500);  // Retorna o código 500 (erro interno)
                    echo json_encode(["success" => false, "message" => "Erro ao confirmar o alerta: " . $e->getMessage()]);
                }
            } else {
                http_response_code(400);  // Retorna o código 400 (erro)
                echo json_encode(["success" => false, "message" => "UUID não fornecido."]);
            }
            break;

        // Após definir o tipo de conteúdo, envie o JSON de resposta
        case 'recuperar_senha':
            // Antes de qualquer saída, defina o tipo de conteúdo
            header('Content-Type: application/json');
            // Recebe os dados do alerta
            $email = $_POST['email'];  // Data e hora atual para a confirmação
            $pdo = Database::getConnection();

            if (!empty($email)) {
                // Conectar ao banco de dados
                $pdo = Database::getConnection();

                try {
                    // Gerar um token único para o usuário
                    $token = bin2hex(random_bytes(16)); // Gerar um token de 32 caracteres

                    // Inserir o token e o email na tabela de recuperação de senha
                    $stmt = $pdo->prepare("INSERT INTO recuperar_senha (email, token, valid) VALUES (:email, :token, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
                    $stmt->execute([
                        ':email' => $email,
                        ':token' => $token
                    ]);

                    // Preparar a URL de recuperação de senha com o token
                    $recoveryUrl = "https://wfcbrasil.com.br/redefinir_senha.php?token=" . $token;

                    // Mensagem do e-mail
                    $message = "
                        <html>
                            <head>
                                <title>Recuperação de Senha</title>
                                <style>
                                    body {
                                        font-family: Arial, sans-serif;
                                        background-color: #f4f4f9;
                                        color: #333;
                                        margin: 0;
                                        padding: 0;
                                    }
                                    .container {
                                        width: 100%;
                                        max-width: 600px;
                                        margin: 0 auto;
                                        background-color: #ffffff;
                                        padding: 20px;
                                        border-radius: 8px;
                                        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                                    }
                                    h2 {
                                        color: #007bff;
                                        font-size: 24px;
                                        margin-bottom: 20px;
                                    }
                                    p {
                                        font-size: 16px;
                                        line-height: 1.6;
                                        color: #555;
                                    }
                                    .button {
                                        display: inline-block;
                                        padding: 12px 25px;
                                        font-size: 16px;
                                        color: #fff;
                                        background-color: #007bff;
                                        text-decoration: none;
                                        border-radius: 4px;
                                        margin-top: 20px;
                                    }
                                    .footer {
                                        margin-top: 30px;
                                        font-size: 14px;
                                        color: #888;
                                        text-align: center;
                                    }
                                </style>
                            </head>
                            <body>
                                <div class='container'>
                                    <h2>Recuperação de Senha</h2>
                                    <p>Olá,</p>
                                    <p>Recebemos uma solicitação para a recuperação de sua senha. Para continuar, basta clicar no link abaixo:</p>
                                    <p style='text-align: center;'>
                                        <a href='" . $recoveryUrl . "' class='button'>Recuperar Senha</a>
                                    </p>
                                    <p>Se você não solicitou a recuperação de senha, por favor, ignore este e-mail.</p>
                                    <p>Este link de recuperação de senha expirará em 24 horas.</p>
                                    <div class='footer'>
                                        <p>Se você tiver alguma dúvida ou precisar de ajuda, entre em contato com o nosso suporte.</p>
                                        <p>Atenciosamente, <br>Equipe de Suporte</p>
                                    </div>
                                </div>
                            </body>
                        </html>
                        ";


                    // Assunto do e-mail
                    $subject = "Recuperação de Senha";

                    // Enviar o e-mail
                    sendEmail($email, $message, $subject);

                    // Retornar uma resposta de sucesso
                    echo json_encode(['status' => 'success', 'message' => 'Instruções para recuperação de senha foram enviadas por e-mail.']);
                } catch (Exception $e) {
                    // Em caso de erro
                    echo json_encode(['status' => 'error', 'message' => 'Ocorreu um erro ao processar sua solicitação.']);
                }
            } else {
                // Caso o e-mail não seja informado
                echo json_encode(['status' => 'error', 'message' => 'Por favor, forneça um e-mail válido.']);
            }
            break;

        default:
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Ação desconhecida',
            ]);
            break;
    }
} else {
    http_response_code(402);
    echo json_encode([
        'success' => false,
        'message' => 'Requisição invalida',
    ]);
}
