<?php
// Inclui o arquivo de configuração do banco de dados e autoload do Composer
require_once './config/configbd.php'; // Classe Database para conexão ao banco
require_once './vendor/autoload.php'; // Autoloader do Composer

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Inicia a sessão (se ainda não estiver iniciada)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$id_parceiro = $_SESSION['usuario_id_parceiro'] ?? null; // Adiciona verificação se a sessão está definida


// Configura o carregador do Twig para buscar templates na pasta "frontend"
$loader = new FilesystemLoader(__DIR__ . '/../frontend'); // Caminho para a pasta frontend
$twig = new Environment($loader);

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
        'id_parceiro' => $id_parceiro,
        'parceiros' => getParceiros($pdo, $id_parceiro),
    ];

    // Renderiza o template com os dados
    echo $twig->render('seu_template.twig', $data);

} catch (PDOException $e) {
    // Logando o erro em um arquivo
    error_log($e->getMessage(), 3, '/path/to/error.log'); // Altere o caminho conforme necessário
    
    // Exibindo uma mensagem amigável ao usuário
    die("Ocorreu um erro ao processar sua solicitação. Tente novamente mais tarde.");
}
?>
