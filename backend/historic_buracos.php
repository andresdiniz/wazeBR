<?php
require_once './config/configbd.php';
require_once './vendor/autoload.php';

// Iniciar sessão se ainda não estiver iniciada (melhor prática)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id_parceiro'])) {
    header('Location: login.php');
    exit;
}

$id_parceiro = $_SESSION['usuario_id_parceiro'];

// Marcar o tempo de início
$startTime = microtime(true);

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../frontend');
$twig = new \Twig\Environment($loader);

try {
    $pdo = Database::getConnection();

    // --- Construção da Base da Query e Parâmetros ---
    // Esta base será usada para a query principal e para as queries de contagem filtradas
    $baseSql = "SELECT * FROM alerts WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'";
    $baseParams = [];

    // Filtro de parceiro (verificar se não é parceiro "99")
    if ($id_parceiro != 99) {
        $baseSql .= " AND id_parceiro = :id_parceiro";
        $baseParams['id_parceiro'] = $id_parceiro;
    }

    // Adicionar filtros de data, cidade, rua e período à base da query
    if (!empty($_GET['date'])) {
        $baseSql .= " AND DATE(FROM_UNIXTIME(pubMillis / 1000)) = :date";
        $baseParams['date'] = $_GET['date'];
    }

    if (!empty($_GET['city'])) {
        $baseSql .= " AND city LIKE :city";
        $baseParams['city'] = '%' . $_GET['city'] . '%';
    }

    if (!empty($_GET['street'])) {
        $baseSql .= " AND street LIKE :street";
        $baseParams['street'] = '%' . $_GET['street'] . '%';
    }

    if (!empty($_GET['period'])) {
        $baseSql .= " AND pubMillis >= :periodStart";
        $daysAgo = (int)$_GET['period'];
        $baseParams['periodStart'] = (time() - ($daysAgo * 86400)) * 1000;
    }

    // --- Query Principal para Obter os Registros da Tabela ---
    $mainSql = $baseSql;
    $mainParams = $baseParams;

    // Adicionar filtro de status 'confirmado' se presente
    $allowedStatuses = ['RESOLVED', 'NOT_EXIST', 'NOT_RESOLVED']; // Status internos do DB
    $requestedStatus = $_GET['status'] ?? null;

    if (!empty($requestedStatus)) {
        // Mapear status de input (frontend) para status do DB
        $statusFilterMap = [
            'resolvido' => 'RESOLVED',
            'nao_existe' => 'NOT_EXIST',
            'nao_resolvido' => 'NOT_RESOLVED',
            'nao_confirmado' => 'NULL' // Adiciona opção para filtrar por NULL
        ];

        if (array_key_exists($requestedStatus, $statusFilterMap)) {
            $dbStatus = $statusFilterMap[$requestedStatus];
            if ($dbStatus === 'NULL') {
                 $mainSql .= " AND confirmado IS NULL";
                 // Não adiciona parâmetro, pois IS NULL não usa placeholder
            } else {
                $mainSql .= " AND confirmado = :confirmado_filter";
                $mainParams['confirmado_filter'] = $dbStatus;
            }
        }
        // Se o status solicitado não for válido, simplesmente ignora o filtro de status
    }


    // Ordenação para a query principal
    $mainSql .= " ORDER BY pubMillis DESC";

    // Preparar e executar a query principal
    $stmt = $pdo->prepare($mainSql);
    foreach ($mainParams as $key => $value) {
        if ($key === 'periodStart') {
            $stmt->bindValue(":$key", $value, PDO::PARAM_INT);
        } elseif ($key === 'date') {
             $stmt->bindValue(":$key", $value, PDO::PARAM_STR); // Bind como string para datas
        } else {
             $stmt->bindValue(":$key", $value); // PDO::PARAM_STR é o default
        }
    }
    $stmt->execute();

    // Recuperar os resultados para a tabela
    $buracos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Queries para Contagens (Usando a Base de Filtros) ---

    // Função auxiliar para executar queries de contagem
    $executeCountQuery = function($pdo, $baseSql, $baseParams, $statusCondition = null) {
        $countSql = "SELECT COUNT(*) FROM alerts WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'";
        $countParams = [];

         // Adicionar filtro de parceiro à query de contagem
        if ($_SESSION['usuario_id_parceiro'] != 99) { // Usar $_SESSION diretamente aqui
            $countSql .= " AND id_parceiro = :id_parceiro";
            $countParams['id_parceiro'] = $_SESSION['usuario_id_parceiro'];
        }


        // Adicionar filtros de data, cidade, rua e período à query de contagem
        if (!empty($_GET['date'])) {
            $countSql .= " AND DATE(FROM_UNIXTIME(pubMillis / 1000)) = :date";
            $countParams['date'] = $_GET['date'];
        }

        if (!empty($_GET['city'])) {
            $countSql .= " AND city LIKE :city";
            $countParams['city'] = '%' . $_GET['city'] . '%';
        }

        if (!empty($_GET['street'])) {
            $countSql .= " AND street LIKE :street";
            $countParams['street'] = '%' . $_GET['street'] . '%';
        }

        if (!empty($_GET['period'])) {
            $countSql .= " AND pubMillis >= :periodStart";
            $daysAgo = (int)$_GET['period'];
            $countParams['periodStart'] = (time() - ($daysAgo * 86400)) * 1000;
        }


        // Adicionar condição de status específico se fornecida
        if ($statusCondition !== null) {
             if ($statusCondition === 'IS NULL') {
                 $countSql .= " AND confirmado IS NULL";
             } else {
                $countSql .= " AND confirmado = :status_count";
                $countParams['status_count'] = $statusCondition;
             }
        }


        $stmtCount = $pdo->prepare($countSql);
        foreach ($countParams as $key => $value) {
             if ($key === 'periodStart') {
                $stmtCount->bindValue(":$key", $value, PDO::PARAM_INT);
            } elseif ($key === 'date') {
                 $stmtCount->bindValue(":$key", $value, PDO::PARAM_STR);
            } else {
                 $stmtCount->bindValue(":$key", $value);
            }
        }
        $stmtCount->execute();
        return $stmtCount->fetchColumn();
    };

    // Contagem Total de Registros (Ignorando filtros de data/cidade/rua/período/status)
    $totalSqlBase = "SELECT COUNT(*) FROM alerts WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'";
    $totalParamsBase = [];
     if ($id_parceiro != 99) {
        $totalSqlBase .= " AND id_parceiro = :id_parceiro";
        $totalParamsBase['id_parceiro'] = $id_parceiro;
    }
    $stmtTotalBase = $pdo->prepare($totalSqlBase);
     if ($id_parceiro != 99) {
         $stmtTotalBase->bindParam(':id_parceiro', $id_parceiro);
     }
    $stmtTotalBase->execute();
    $countTotal = $stmtTotalBase->fetchColumn();


    // Contagens Filtradas por Status (Aplicando os mesmos filtros da query principal, exceto o filtro de status)
    $countResolved = $executeCountQuery($pdo, $baseSql, $baseParams, 'RESOLVED');
    $countNotResolved = $executeCountQuery($pdo, $baseSql, $baseParams, 'NOT_RESOLVED');
    $countNotExist = $executeCountQuery($pdo, $baseSql, $baseParams, 'NOT_EXIST');
    // Contagem para registros não confirmados (confirmado IS NULL)
    $countNotConfirmed = $executeCountQuery($pdo, $baseSql, $baseParams, 'IS NULL');


    // Passar os dados para o template
    $data = [
        'buracos' => $buracos,
        'filters' => $_GET,
        'counts' => [
            'total' => $countTotal, // Total geral de buracos para o parceiro
            'filtered' => count($buracos), // Total de registros na tabela após todos os filtros PHP
            'confirmed' => $countResolved,
            'not_resolved' => $countNotResolved,
            'not_exist' => $countNotExist,
            'not_confirmed' => $countNotConfirmed // Contagem de registros com confirmado IS NULL
        ]
    ];

} catch (Exception $e) {
    // Em produção, logar o erro e mostrar uma mensagem genérica ao usuário
    error_log("Erro na consulta de buracos: " . $e->getMessage());
    die("Ocorreu um erro ao carregar os dados. Por favor, tente novamente mais tarde.");
}

// Marcar o tempo de fim
$endTime = microtime(true);

// Calcular o tempo de execução
$executionTime = $endTime - $startTime;

// logToFile('info', "Consulta de buracos realizada em $executionTime segundos"); // Certifique-se que logToFile está definido

// Renderizar o template (assumindo que você tem um template Twig para o dashboard)
// echo $twig->render('dashboard.twig', $data); // Exemplo de como renderizar
?>
