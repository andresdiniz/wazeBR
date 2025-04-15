<?php

// Verifica se o arquivo .env existe no caminho especificado
$envPath = __DIR__ . '/.env';  // Corrigido o caminho

require_once __DIR__ . '/vendor/autoload.php';
// Inclui os arquivos de configuração e funções
require_once './config/configbd.php';
require_once './functions/scripts.php';

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
    logEmail("error", "Erro ao carregar o .env: ". $e->getMessage());
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
date_default_timezone_set('America/Sao_Paulo');

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Configurações básicas
session_start();

// Verifica se o usuário está logado
if (empty($_SESSION['usuario_id'])) {
    header("Location: login.html");
    exit();
}

// Configura o Twig
$loader = new FilesystemLoader('./frontend');
$twig = new Environment($loader, [
    'debug' => true, // Ativar depuração do Twig (apenas para desenvolvimento)
    'cache' => false, // Evita problemas de cache em desenvolvimento

]);
$twig->addExtension(new \Twig\Extension\DebugExtension()); // Extensão para debug

// Conexão com o banco de dados
$pdo = Database::getConnection();

// Recupera dados gerais para o template
$settings = getSiteSettings($pdo);

// Verifica se o site está em manutenção
if ($settings['manutencao'] ?? false) {
    header('Location: manutencao.html');
    exit();
}

$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$page = $uri ?: 'home'; // Se vazio, vai para home

$pages = getSitepages($pdo, $page);
$pageData = $pages['pageData'] ?? '';
$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$page = $uri ?: 'home'; // Se vazio, vai para home

$pages = getSitepages($pdo, $page);
$pageData = $pages['pageData'] ?? '';

$dados = [ //Manter como dados, devido interferencia com a variavel data passada
    'user' => getSiteUsers($pdo, $_SESSION['usuario_id']),  // Usuário logado
    'settings' => $settings,  // Configurações do site
    'session' => $_SESSION,
    'pagedata' => $pageData,  // Passando o título para o template
];

// Renderiza os componentes fixos
echo $twig->render('header.twig', $dados);
echo $twig->render('sidebar.twig', $dados);

// Remove o basePath da URI, se necessário
if (!empty($basePath) && strpos($uri, $basePath) === 0) {
    $uri = substr($uri, strlen($basePath));
}

$uri = trim($uri, '/'); // Remove barras extras
$page = $uri ?: 'home'; // Define 'home' como padrão se vazio

// Caminhos do controlador e template
$controllerPath = "./backend/{$page}.php";
$templatePath = "{$page}.twig";

try {
    // Carrega o controlador, se existir
    if (file_exists($controllerPath)) {
        require_once $controllerPath; // O controlador pode manipular $data
    }
    $data = $data ?? [];
echo $twig->render($templatePath, $data);
/* Tesye para tornar mais modular
$combinedData = [
    'data' => $data,
    'dados' => $dados,
    ];

var_dump($combinedData);*/

} catch (\Twig\Error\LoaderError $e) {
    // Renderiza página 404 caso o template não seja encontrado
    http_response_code(404);
    echo $twig->render('404.twig', $dados);
} catch (Exception $e) {
    // Lida com outros erros gerais e exibe página de erro
    http_response_code(500);
    $data['errorMessage'] = $e->getMessage();
    echo $twig->render('error.twig', $dados);
}

// Renderiza o footer
echo $twig->render('footer.twig', $dados);
?>
