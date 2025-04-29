<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Caminho e carregamento de dependências
$envPath = __DIR__ . '/.env';
require_once __DIR__ . '/vendor/autoload.php';
require_once './config/configbd.php';
require_once './functions/scripts.php';

use Dotenv\Dotenv;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

if (!file_exists($envPath)) {
    die("Arquivo .env não encontrado no caminho: $envPath");
}

try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    error_log("Erro ao carregar o .env: " . $e->getMessage());
    logEmail("error", "Erro ao carregar o .env: " . $e->getMessage());
    die("Erro ao carregar o .env: " . $e->getMessage());
}

// Ativa log de erros em modo debug
if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true') {
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/debug.log');
    if (!is_dir(__DIR__ . '/../logs')) {
        mkdir(__DIR__ . '/../logs', 0777, true);
    }
}

date_default_timezone_set('America/Sao_Paulo');
session_start();

// Captura a URI e define página
$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$page = $uri ?: 'home';

// Definição de rotas públicas (sem necessidade de login)
$publicRoutes = ['blog', 'login', 'recuperar-senha'];

// Redireciona se não estiver logado e for rota protegida
if (!in_array($uri, $publicRoutes) && empty($_SESSION['usuario_id'])) {
    header("Location: /login");
    exit();
}

// Configuração do Twig
$loader = new FilesystemLoader('./frontend');
$twig = new Environment($loader, [
    'debug' => true,
    'cache' => false,
]);
$twig->addExtension(new \Twig\Extension\DebugExtension());

// Conexão com o banco de dados
$pdo = Database::getConnection();

// Dados gerais do site
$settings = getSiteSettings($pdo);

// Verifica modo manutenção
if ($settings['manutencao'] ?? false) {
    header('Location: manutencao.html');
    exit();
}

// Página atual
$pages = getSitepages($pdo, $page);
$pageData = $pages['pageData'] ?? '';

// Dados globais disponíveis para todos os templates
$dados = [
    'user' => isset($_SESSION['usuario_id']) ? getSiteUsers($pdo, $_SESSION['usuario_id']) : null,
    'settings' => $settings,
    'session' => $_SESSION,
    'pagedata' => $pageData,
];

// Renderiza header e sidebar
echo $twig->render('header.twig', $dados);
echo $twig->render('sidebar.twig', $dados);

// Controlador e template da página solicitada
$controllerPath = "./backend/{$page}.php";
$templatePath = "{$page}.twig";

try {
    // Executa o controlador, se existir
    if (file_exists($controllerPath)) {
        require_once $controllerPath; // Este pode definir $data
    }

    $data = $data ?? [];
    echo $twig->render($templatePath, $data);
} catch (\Twig\Error\LoaderError $e) {
    http_response_code(404);
    echo $twig->render('404.twig', $dados);
} catch (Exception $e) {
    http_response_code(500);
    $dados['errorMessage'] = $e->getMessage();
    echo $twig->render('error.twig', $dados);
}

// Renderiza o footer (corrigido espaço no nome do template)
echo $twig->render('footer.twig', $dados);
?>
