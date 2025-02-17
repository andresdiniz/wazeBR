<?php
require_once './config/configbd.php';
require_once './vendor/autoload.php';

if (!isset($_SESSION['usuario_id_parceiro'])) {
    header('Location: login.php');
    exit;
}

$id_parceiro = $_SESSION['usuario_id_parceiro'];

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../frontend');
$twig = new \Twig\Environment($loader);

try {
    $pdo = Database::getConnection();

    $sql = "SELECT * FROM alerts WHERE type = 'HAZARD' AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'";
    $params = [];

    if ($id_parceiro != 99) {
        $sql .= " AND id_parceiro = :id_parceiro";
        $params['id_parceiro'] = $id_parceiro;
    }

    if (!empty($_GET['date'])) {
        $sql .= " AND DATE(FROM_UNIXTIME(pubMillis / 1000)) = :date";
        $params['date'] = $_GET['date'];
    }

    if (!empty($_GET['city'])) {
        $sql .= " AND city LIKE :city";
        $params['city'] = '%' . $_GET['city'] . '%';
    }

    if (!empty($_GET['street'])) {
        $sql .= " AND street LIKE :street";
        $params['street'] = '%' . $_GET['street'] . '%';
    }

    if (!empty($_GET['period'])) {
        $sql .= " AND pubMillis >= :periodStart";
        $daysAgo = (int)$_GET['period'];
        $params['periodStart'] = (time() - ($daysAgo * 86400)) * 1000;
    }

    $sql .= " ORDER BY pubMillis DESC";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        if ($key === 'periodStart') {
            $stmt->bindValue(":$key", $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(":$key", $value);
        }
    }

    $stmt->execute();
    $buracos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [
        'buracos' => $buracos,
        'filters' => $_GET,
    ];
} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}
