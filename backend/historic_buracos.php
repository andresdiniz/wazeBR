<?php
require_once './config/configbd.php';
require_once './vendor/autoload.php';

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

    // Inicializar o array de parâmetros
    $params = [];
    
    // Base da query
    $sql = "SELECT * FROM alerts WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'";

    // Filtro de parceiro (verificar se não é parceiro "99")
    if ($id_parceiro != 99) {
        $sql .= " AND id_parceiro = :id_parceiro";
        $params['id_parceiro'] = $id_parceiro;
    }

    // Filtro por data
    if (!empty($_GET['date'])) {
        $sql .= " AND DATE(FROM_UNIXTIME(pubMillis / 1000)) = :date";
        $params['date'] = $_GET['date'];
    }

    // Filtro por cidade
    if (!empty($_GET['city'])) {
        $sql .= " AND city LIKE :city";
        $params['city'] = '%' . $_GET['city'] . '%';
    }

    // Filtro por rua
    if (!empty($_GET['street'])) {
        $sql .= " AND street LIKE :street";
        $params['street'] = '%' . $_GET['street'] . '%';
    }

    // Filtro por período (últimos X dias)
    if (!empty($_GET['period'])) {
        $sql .= " AND pubMillis >= :periodStart";
        $daysAgo = (int)$_GET['period'];
        $params['periodStart'] = (time() - ($daysAgo * 86400)) * 1000;
    }

    // Ordenação
    $sql .= " ORDER BY pubMillis DESC";

    // Preparar a query
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        if ($key === 'periodStart') {
            $stmt->bindValue(":$key", $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(":$key", $value);
        }
    }

    // Executar a consulta
    $stmt->execute();

    // Recuperar os resultados
    $buracos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Passar os dados para o template
    $data = [
        'buracos' => $buracos,
        'filters' => $_GET,
    ];
} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}

// Marcar o tempo de fim
$endTime = microtime(true);

// Calcular o tempo de execução
$executionTime = $endTime - $startTime;

logToFile('info', "Consulta de buracos realizada em $executionTime segundos");
?>
