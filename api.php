<?php
header('Content-Type: application/json'); // Define o conteúdo da resposta como JSON

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once './config/configbd.php'; // Configuração do banco de dados
    
    // Obtém o tipo de ação dos parâmetros GET
    $action = $_GET['action'] ?? null;

    switch ($action) {
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
                        $stmt = $pdo->prepare("SELECT 
                            DATE(date_received) AS alert_date,
                            COUNT(*) AS count
                            FROM alerts 
                            WHERE type = 'ACCIDENT'
                            AND date_received BETWEEN :start_date AND :end_date
                            GROUP BY alert_date
                            ORDER BY alert_date DESC");
                    } elseif ($groupBy == 'hour') {
                        $stmt = $pdo->prepare("SELECT 
                            HOUR(date_received) AS alert_hour,
                            COUNT(*) AS count
                            FROM alerts 
                            WHERE type = 'ACCIDENT'
                            AND date_received BETWEEN :start_date AND :end_date
                            GROUP BY alert_hour
                            ORDER BY alert_hour ASC"); // Ordena pela hora de forma ascendente                    
                    } elseif ($groupBy == 'street') {
                        $stmt = $pdo->prepare("SELECT 
                            street, 
                            COUNT(*) AS count
                            FROM alerts 
                            WHERE type = 'ACCIDENT' 
                            AND date_received BETWEEN :start_date AND :end_date
                            GROUP BY street
                            ORDER BY count DESC");
                    } else {
                        http_response_code(400);
                        echo json_encode(['error' => 'Parâmetro "group_by" inválido.']);
                        exit;
                    }
            
                    // Vincula os parâmetros de data
                    $stmt->bindValue(':start_date', $startDate);
                    $stmt->bindValue(':end_date', $endDate);
                    $stmt->execute();
            
                    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
                    if (!$alerts) {
                        echo json_encode(['error' => 'Nenhum alerta encontrado para as datas selecionadas.']);
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
                            

        case 'get_jams':
            // Busca congestionamentos (type = 'Jam')
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');

            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("
                    SELECT * FROM alerts 
                    WHERE type = 'Jam' 
                    AND date_received BETWEEN :start_date AND :end_date
                    ORDER BY pubMillis DESC
                ");
                $stmt->bindValue(':start_date', $startDate);
                $stmt->bindValue(':end_date', $endDate);
                $stmt->execute();

                $jams = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$jams) {
                    echo json_encode(['error' => 'Nenhum congestionamento encontrado para as datas selecionadas.']);
                    exit;
                }

                $labels = [];
                $data_counts = [];
                foreach ($jams as $jam) {
                    $date = substr($jam['date_received'], 0, 10);
                    if (!in_array($date, $labels)) {
                        $labels[] = $date;
                        $data_counts[] = 1;
                    } else {
                        $index = array_search($date, $labels);
                        $data_counts[$index]++;
                    }
                }

                echo json_encode([
                    'jams' => $jams,
                    'labels' => $labels,
                    'data_counts' => $data_counts,
                ]);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao acessar o banco de dados.', 'details' => $e->getMessage()]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Erro inesperado.', 'details' => $e->getMessage()]);
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
            function getStreetInfo($lat, $lon) {
                $token = '11682863520_cadcbae577c7f1a01263851644ea59827896751b'; // Insira seu token válido aqui
                $url = "https://www.waze.com/row-partnerhub-api/waze-map/streetsInfo?lat=" . urlencode($lat) . "&lon=" . urlencode($lon) . "&token=" . urlencode($token);
                
                // Usando cURL para obter os dados da API
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);  // Tempo limite de 30 segundos para a requisição
                $response = curl_exec($ch);
                
                // Verifique se ocorreu algum erro durante a requisição cURL
                if(curl_errno($ch)) {
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
            $id_parceiro = $_SESSION['usuario_id_parceiro'];
        
            try {
                if ($id_parceiro == 0) { // Verifica se o ID do parceiro é 0
                    // Consulta para retornar todos os nomes
                    $sql = "SELECT Nome FROM users";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute();
        
                    // Obter os resultados
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
                    if ($results) {
                        echo json_encode(['success' => true, 'nomes' => $results]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Nenhum usuário encontrado']);
                    }
                } else {
                    // Consulta para retornar o nome de um único parceiro
                    $sql = "SELECT Nome FROM users WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindParam(':id', $id_parceiro, PDO::PARAM_INT);
                    $stmt->execute();
        
                    // Obter o resultado
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
                    if ($result) {
                        echo json_encode(['success' => true, 'nome' => $result['Nome']]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Usuário não encontrado']);
                    }
                }
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Erro ao executar a consulta: ' . $e->getMessage()]);
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


        default:
            http_response_code(400);
            echo json_encode(['error' => 'Ação não reconhecida.']);
            break;
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Requisição inválida.']);
}
