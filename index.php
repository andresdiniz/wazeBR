<?php
/**
 * Index.php - Ponto de Entrada da Aplicação
 * Sistema de logging robusto, Twig e manipulação de erros/exceções.
 */

// --- Configuração e Inicialização ---

// Garante que o autoloader e as funções essenciais estão carregados
require_once __DIR__ . '/vendor/autoload.php';

// O arquivo 'bootstrap.php' agora lida com Dotenv, Logger, ErrorHandler e configurações PHP
$bootstrap = require_once __DIR__ . '/bootstrap.php';

// Extrai variáveis globais da inicialização
$isDebug = $bootstrap['isDebug'];
$logger = $bootstrap['logger'];
$twig = $bootstrap['twig'];
$profile = $bootstrap['profile'] ?? null;
// $pdo é inicializado no fluxo principal, se a autenticação for bem-sucedida

// =============================================================================
// LÓGICA DA APLICAÇÃO PRINCIPAL
// =============================================================================

// Flag para controlar a renderização de header/sidebar/footer
$renderLayout = true;

try {
    // Verifica autenticação (sem alteração)
    if (empty($_SESSION['usuario_id'])) {
        $logger->info('Usuário não autenticado, redirecionando para login', [
            'url_requested' => $_SERVER['REQUEST_URI'] ?? '/'
        ]);
        header("Location: /login_page.php");
        exit();
    }
    
    // --- Conexão com Banco de Dados e Configurações ---
    $pdo = Database::getConnection(); // Assumindo que getConnection usa as variáveis de ambiente carregadas
    $logger->debug('Conexão com banco de dados estabelecida');
    
    $settings = getSiteSettings($pdo);
    
    // Verifica manutenção (sem alteração)
    if (($settings['manutencao'] ?? false) && $_SERVER['REQUEST_URI'] !== '/manutencao.html') {
        $logger->info('Site em manutenção, redirecionando');
        header('Location: /manutencao.html');
        exit();
    }
    
    // --- Roteamento e Processamento da Página ---
    $uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $page = $uri ?: 'home';
    
    $logger->info('Página solicitada', ['page' => $page, 'user_id' => $_SESSION['usuario_id']]);

    // Carrega dados globais
    $dadosGlobais = [
        'user' => getSiteUsers($pdo, $_SESSION['usuario_id']),
        'settings' => $settings,
        'session' => $_SESSION,
        'atualizacao' => getLatestExecutionLogByStatus($pdo, 'success'),
        'is_debug' => $isDebug
    ];
    
    // Carrega dados específicos da página
    $pages = getSitepages($pdo, $page);
    $dadosGlobais['pagedata'] = $pages['pageData'] ?? null;
    
    // Renderiza componentes fixos (header/sidebar)
    echo $twig->render('header.twig', $dadosGlobais);
    echo $twig->render('sidebar.twig', $dadosGlobais);
    
    // Carrega e executa controlador
    $controllerPath = __DIR__ . "/backend/{$page}.php";
    $templatePath = "{$page}.twig";
    $data = [];
    
    if (file_exists($controllerPath)) {
        $logger->debug('Carregando controlador', ['controller' => $controllerPath]);
        // O controlador deve popular a variável $data
        require_once $controllerPath;
    }
    
    // Combina e renderiza a página principal
    $combinedData = array_merge($dadosGlobais, $data);
    echo $twig->render($templatePath, $combinedData);
    $logger->debug('Página renderizada com sucesso', ['template' => $templatePath]);

    // Renderiza footer
    echo $twig->render('footer.twig', $dadosGlobais);
    
} catch (\Twig\Error\LoaderError $e) {
    // Erro específico para template não encontrado (404)
    $logger->warning('Template não encontrado (404)', ['template' => $templatePath ?? 'unknown']);
    throw new Exception('Página não encontrada', 404);
} catch (Exception $e) {
    // Captura exceções da aplicação (incluindo erros PDO)
    
    // O ErrorHandler::handleException ou um bloco try/catch global lida com a renderização de erros.
    // O bloco 'handleException' não é invocado aqui, pois estamos capturando a exceção. 
    // É necessário re-lançar a exceção OU tratar a renderização aqui.
    
    $renderLayout = false; // Não renderiza layout em caso de erro

    // O ErrorHandler está registrado para capturar exceções, mas ao usar try/catch, 
    // a exceção deve ser tratada aqui para renderização de página de erro.
    
    $code = $e->getCode() ?: 500;
    
    if (!is_int($code) || $code < 400 || $code > 599) {
        $code = 500;
    }
    
    http_response_code($code);
    
    $logger->error('Erro na aplicação', [
        'page' => $page ?? 'unknown',
        'code' => $code,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);

    // O método 'renderErrorPage' está no escopo global (função).
    // Seria melhor mover essa lógica para o ErrorHandler ou um serviço de renderização.
    
    $errorTitles = [
        400 => 'Requisição Inválida', 401 => 'Não Autorizado', 403 => 'Acesso Proibido',
        404 => 'Página Não Encontrada', 500 => 'Erro Interno do Servidor', 503 => 'Serviço Indisponível'
    ];
    $errorTitle = $errorTitles[$code] ?? 'Erro';
    $errorMessage = ($code >= 400 && $code < 500) 
        ? ($e->getMessage() ?: 'Verifique as informações e tente novamente.')
        : ($isDebug ? $e->getMessage() : 'Ocorreu um erro inesperado. Tente novamente mais tarde.');
    $errorDescription = $isDebug ? $e->getTraceAsString() : 'O erro foi registrado e será analisado.';
    $errorId = uniqid('err_');

    // Usa a função auxiliar global para renderizar o erro standalone
    renderErrorPage(
        $code, $errorTitle, $errorMessage, $errorDescription, $errorId, 
        $isDebug ? $e->getTraceAsString() : '', $isDebug
    );
}

// --- Finalização e Debug ---
if (isset($profile) && $isDebug && $renderLayout) {
    if (class_exists(\Twig\Profiler\Dumper\Text::class)) {
        $dumper = new \Twig\Profiler\Dumper\Text();
        echo '<hr><h2>Twig Profiler</h2><pre>';
        echo $dumper->dump($profile);
        echo '</pre>';
    }
}

if ($renderLayout) {
    $logger->info('Requisição finalizada', [
        'page' => $page ?? 'unknown',
        'execution_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms'
    ]);
}