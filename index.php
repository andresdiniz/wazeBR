<?php

// --- Configuração Inicial ---

// Inicia a sessão
session_start();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Define o caminho para o arquivo .env (assumindo que está na mesma pasta do script)
$envPath = __DIR__ . '/.env';

// Inclui o autoloader do Composer
require_once __DIR__ . '/vendor/autoload.php';

// Inclui os arquivos de configuração e funções
// Verifique se esses caminhos estão corretos em relação ao local deste script
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

use Dotenv\Dotenv; // Importa a classe Dotenv
use Twig\Environment; // Importa a classe Twig Environment
use Twig\Loader\FilesystemLoader; // Importa a classe Twig FilesystemLoader
use Twig\Extension\DebugExtension; // Importa a extensão de debug
use Twig\Extension\ProfilerExtension; // Importa a extensão de profiler
use Twig\Profiler\Profile; // Importa a classe Profile para o profiler
use Symfony\Component\Stopwatch\Stopwatch; // Opcional: Para medição de tempo mais granular

// Verifica se o arquivo .env existe
if (!file_exists($envPath)) {
    die("Arquivo .env não encontrado no caminho: $envPath");
}

// Carrega as variáveis de ambiente do arquivo .env
try {
    // Certifique-se de que o caminho do .env está correto
    // Dotenv::createImmutable(__DIR__) procura o .env na pasta atual
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    // Em caso de erro, logar o erro
    error_log("Erro ao carregar o .env: " . $e->getMessage());
    // Assumindo que logEmail é uma função customizada definida em functions/scripts.php
    if (function_exists('logEmail')) {
       logEmail("error", "Erro ao carregar o .env: ". $e->getMessage());
    }
    // Para ambiente de desenvolvimento, você pode querer exibir o erro.
    // Em produção, é melhor apenas logar e mostrar uma mensagem genérica.
    die("Erro ao carregar o .env: " . $e->getMessage());
}

// Configura o ambiente de debug com base na variável DEBUG do .env
// ATENÇÃO: Configurações de log e debug devem ser rigorosamente controladas
// e desabilitadas em produção.
if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true') { // Use === 'true' para comparação estrita
    ini_set('display_errors', 1); // Exibe erros diretamente (APENAS EM DESENVOLVIMENTO)
    ini_set('display_startup_errors', 1); // Exibe erros de inicialização (APENAS EM DESENVOLVIMENTO)
    error_reporting(E_ALL); // Reporta todos os tipos de erros (APENAS EM DESENVOLVIMENTO)

    // Configura as opções de log para ambiente de debug
    ini_set('log_errors', 1);
    // Certifique-se de que o diretório de logs tem permissão de escrita
    $logDir = __DIR__ . '/../logs'; // Caminho relativo: um nível acima da pasta deste script, dentro de 'logs'
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0775, true)) { // Permissões 0775 são geralmente mais seguras que 0777
             // Se não conseguir criar o diretório, logar onde for possível ou dar die
             error_log("Não foi possível criar o diretório de logs: " . $logDir);
             // O script pode continuar, mas sem logar em arquivo
        }
    }
    ini_set('error_log', $logDir . '/debug.log');

} else {
     // Configurações para ambiente de produção (ou quando DEBUG não é 'true')
     ini_set('display_errors', 0);
     ini_set('display_startup_errors', 0);
     error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED); // Nível de reporte de erro adequado para produção

     ini_set('log_errors', 1);
     // Defina um caminho de log seguro e fora do diretório web acessível
     // Exemplo: $logDir = __DIR__ . '/../var/logs';
     // ini_set('error_log', $logDir . '/production.log');
}

// Define o fuso horário
date_default_timezone_set($_ENV['TIMEZONE'] ?? 'America/Sao_Paulo'); // Use fuso horário do .env ou padrão

// --- Configuração do Twig ---

// Configura o loader do Twig (assumindo templates estão na pasta 'frontend')
$loader = new FilesystemLoader(__DIR__ . '/frontend'); // Caminho absoluto é mais seguro

// Configura o ambiente Twig
// Desative debug e cache em produção
$twig = new Environment($loader, [
    'debug' => isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true',
    'cache' => isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true' ? false : __DIR__ . '/../cache/twig', // Cache em produção
]);

// Cria o diretório de cache do Twig se necessário
if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] !== 'true') {
     $cacheDir = __DIR__ . '/../cache/twig';
     if (!is_dir($cacheDir)) {
        if (!mkdir($cacheDir, 0775, true)) {
             error_log("Não foi possível criar o diretório de cache do Twig: " . $cacheDir);
             // O script pode continuar, mas sem cache de template
        }
     }
}


// Adiciona a extensão de debug (útil com debug: true)
if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true') {
    $twig->addExtension(new DebugExtension());

    // Adiciona a extensão de profiler (APENAS EM DESENVOLVIMENTO)
    // Requer a instância de Twig\Profiler\Profile
    $profile = new Profile();
    // Opcional: Integre com Symfony Stopwatch se estiver usando
    // $stopwatch = new Stopwatch();
    // $twig->addExtension(new ProfilerExtension($profile, $stopwatch));
    $twig->addExtension(new ProfilerExtension($profile));
}


// --- Lógica da Aplicação ---
// Verifica se o usuário está logado
// Assumindo que 'login.html' é a página de login
//echo $_SESSION['usuario_id'] ?? 'não logado'; // Para debug, remova em produção
if (empty($_SESSION['usuario_id'])) {
    header("Location: login.php"); // Redireciona para a página de login
    exit(); // Encerra o script após o redirecionamento
}

// Conexão com o banco de dados
// Assumindo que Database::getConnection() está definido em configbd.php
try {
   $pdo = Database::getConnection();
} catch (PDOException $e) {
    error_log("Erro de conexão com o banco de dados: " . $e->getMessage());
    if (function_exists('logEmail')) {
       logEmail("critical", "Erro de conexão com o banco de dados: ". $e->getMessage());
    }
    // Em produção, mostrar uma página de erro amigável ou mensagem genérica
    http_response_code(500);
    die("Erro interno do servidor ao conectar ao banco de dados.");
}


// Recupera dados gerais para os templates fixos (header, sidebar, footer)
// Assumindo que getSiteSettings e getSiteUsers estão definidos em functions/scripts.php
$settings = getSiteSettings($pdo);

// Verifica se o site está em manutenção
// Assumindo que manutencao.html é a página de manutenção
if (($settings['manutencao'] ?? false) && $_SERVER['REQUEST_URI'] !== '/manutencao.html') {
    header('Location: manutencao.html');
    exit();
}

// Determina a página atual baseada na URL
$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$page = $uri ?: 'home'; // Define 'home' como padrão se a URI for vazia

// Recupera dados específicos da página
// Assumindo que getSitepages está definido em functions/scripts.php
$pages = getSitepages($pdo, $page); // Este nome de variável 'pages' pode ser confuso com o plural
$pageData = $pages['pageData'] ?? null; // Use null como padrão se a chave não existir
$atualizacao = getLatestExecutionLogByStatus($pdo, 'success'); // Atualização dos dados do Waze

// Prepara dados que serão passados para todos os templates fixos (header, sidebar, footer)
$dados = [
    'user' => getSiteUsers($pdo, $_SESSION['usuario_id']), // Usuário logado
    'settings' => $settings, // Configurações do site
    'session' => $_SESSION, // Dados da sessão
    'pagedata' => $pageData, // Dados da página específica (título, etc.)
    'atualizacao' => $atualizacao, // Dados de atualização (se necessário)
    // Adicione aqui outros dados comuns se necessário
];

// Renderiza os componentes fixos
// NOTA: Renderizar header/sidebar/footer separadamente pode impactar o profiler
// que verá cada renderização como um evento separado.
// Uma abordagem comum é ter um layout base que inclua esses componentes.
echo $twig->render('header.twig', $dados);
echo $twig->render('sidebar.twig', $dados);

// --- Lógica da Página Atual ---

// Define os caminhos do controlador e template para a página atual
$controllerPath = __DIR__ . "/backend/{$page}.php"; // Caminho absoluto é mais seguro
$templatePath = "{$page}.twig"; // Caminho relativo ao Twig Loader ('./frontend')

// Inicializa a variável $data que será passada para o template da página principal
// O controlador carregado abaixo pode popular esta variável.
$data = [];

try {
    // Carrega e executa o controlador, se existir
    // O controlador DEVE popular a variável $data (ou outra variável que será usada no template)
    if (file_exists($controllerPath)) {
        require_once $controllerPath;
        // O arquivo do controlador agora foi executado e pode ter modificado $data
    }

    // Verifica se a variável $data existe após incluir o controlador.
    // Se o controlador não existir ou não definir $data, usará o array vazio inicial.
    // Não há necessidade de reatribuir $data = $data ?? []; pois já foi inicializada.

    // Combina os dados fixos ($dados) com os dados específicos da página ($data)
    // Isso permite que o template da página principal acesse ambos os conjuntos de dados
    $combinedData = array_merge($dados, $data);


    // Renderiza o template da página principal
    // Se o template não existir, cairá no catch Twig\Error\LoaderError
    echo $twig->render($templatePath, $combinedData);

} catch (\Twig\Error\LoaderError $e) {
    // Renderiza página 404 caso o template não seja encontrado
    http_response_code(404);
    // Verifica se o template 404.twig existe, caso contrário, mostra uma mensagem simples
    if ($loader->exists('404.twig')) {
         echo $twig->render('404.twig', $dados);
    } else {
         echo "<h1>404 Not Found</h1><p>A página solicitada não foi encontrada.</p>";
    }

} catch (Exception $e) {
    // Lida com outros erros gerais (erros no controlador, no banco, etc.)
    http_response_code(500);
    error_log("Erro na página {$page}: " . $e->getMessage());
     if (function_exists('logEmail')) {
       logEmail("error", "Erro na página {$page}: ". $e->getMessage());
    }

    // Prepara dados para a página de erro
    $errorData = $dados; // Começa com os dados gerais
    $errorData['errorCode'] = 500;
    $errorData['errorTitle'] = 'Erro interno do servidor';
    $errorData['errorMessage'] = isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true' ? $e->getMessage() : 'Ocorreu um erro inesperado.'; // Mostra detalhes apenas em debug
    $errorData['errorDescription'] = 'Ocorreu um erro ao processar sua requisição. Tente novamente mais tarde.';
    $errorData['pagina_retorno'] = '/'; // Define uma página para retorno

    // Renderiza a página de erro
     // Verifica se o template error.twig existe
    if ($loader->exists('error.twig')) {
         echo $twig->render('error.twig', $errorData);
    } else {
         echo "<h1>500 Internal Server Error</h1><p>Ocorreu um erro interno.</p>";
         // Em debug, mostrar o erro detalhado se o template de erro não existir
         if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true') {
             echo "<pre>" . $e->getMessage() . "\n" . $e->getTraceAsString() . "</pre>";
         }
    }
}

// Renderiza o footer
echo $twig->render('footer.twig', $dados);

// --- Exibição do Profiler (APENAS EM DESENVOLVIMENTO) ---
// Despeja os dados de profiling se a extensão foi adicionada e estamos em debug
if (isset($profile) && isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true') {
    // Verifica se a classe do Dumper de texto existe
    if (class_exists(\Twig\Profiler\Dumper\Text::class)) {
        $dumper = new \Twig\Profiler\Dumper\Text();
        echo '<hr><h2>Twig Profiler Data</h2>'; // Adiciona um título
        echo '<pre>'; // Formata a saída para ser legível no navegador
        echo $dumper->dump($profile);
        echo '</pre>';
    } else {
        echo '<hr><p>Twig Profiler Extension enabled, but Text Dumper not found.</p>';
    }
}

// --- Fim do Script ---
?>