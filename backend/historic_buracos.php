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

// Caminho do arquivo de cache
$cacheFile = __DIR__ . '/cache/buracos_cache.json';
$cacheLifetime = 2 * 60; // 2 minutos em segundos

try {
    // Verificar se o arquivo de cache existe e se está dentro do tempo de vida
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheLifetime) {
        // Cache ainda válido, carregar os dados
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        $buracos = $cacheData['buracos'];
        $filters = $cacheData['filters'];
    } else {
        // Cache inválido ou inexistente, realizar a consulta ao banco
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

        // Salvar os dados em cache
        $cacheData = [
            'buracos' => $buracos,
            'filters' => $_GET,
            'generated_at' => time(),
        ];
        file_put_contents($cacheFile, json_encode($cacheData));
    }

    // Passar os dados para o template
    $data = [
        'buracos' => $buracos,
        'filters' => $filters ?? $_GET, // Usar filtros do cache ou os atuais
    ];
} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}
