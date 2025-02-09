<?php
// Inclui o arquivo de configuração do banco de dados e autoload do Composer
require_once './config/configbd.php'; // Classe Database para conexão ao banco
require_once './vendor/autoload.php'; // Autoloader do Composer

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Configura o carregador do Twig para buscar templates na pasta "frontend"
$loader = new FilesystemLoader(__DIR__ . '/../frontend'); // Caminho para a pasta frontend
$twig = new Environment($loader);

echo $id_parceiro;

try {
    // Conexão com o banco de dados usando a classe Database
    $pdo = Database::getConnection();
// Consultas para tipos e subtipos
$sqlTipos = "SELECT id, name, value FROM alert_type";
$sqlSubtipos = "SELECT id, alert_type_id, subtype_value, name FROM alert_subtype";

$tipos = $pdo->query($sqlTipos)->fetchAll(PDO::FETCH_ASSOC);
$subtipos = $pdo->query($sqlSubtipos)->fetchAll(PDO::FETCH_ASSOC);

// Organiza os subtipos por tipo
$subtiposPorTipo = [];
foreach ($subtipos as $subtipo) {
    $subtiposPorTipo[$subtipo['alert_type_id']][] = $subtipo;
}

// Dados enviados ao Twig
$data = [
    'tipos' => $tipos,
    'subtiposPorTipo' => $subtiposPorTipo,
];


} catch (PDOException $e) {
    die("Erro de conexão com o banco de dados: " . $e->getMessage());
}
?>
