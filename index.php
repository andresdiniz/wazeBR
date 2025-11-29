<?php
/**
 * Index.php - Ponto de Entrada da Aplicação Otimizada
 * Sistema de logging robusto, Twig e manipulação de erros/exceções.
 */

// --- Configuração e Autoload ---
require_once __DIR__ . '/vendor/autoload.php';

// --- Inicialização do Sistema (Bootstrap) ---
$bootstrap = require_once __DIR__ . '/bootstrap.php';

// Extrai serviços e configurações
$isDebug = $bootstrap['isDebug'];
$logger = $bootstrap['logger'];
$twig = $bootstrap['twig'];
$profile = $bootstrap['profile'] ?? null;

// --- CSRF Token (Mantido no index para início da sessão) ---
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =============================================================================
// LÓGICA DA APLICAÇÃO PRINCIPAL
// =============================================================================

// Flag para controlar a renderização de layout (evita renderizar layout no erro)
$renderLayout = true;

try {
    // --- 1. Autenticação e Conexão ---
    if (empty($_SESSION['usuario_id'])) {
        $logger->info('Usuário não autenticado, redirecionando para login', [
            'url_requested' => $_SERVER['REQUEST_URI'] ?? '/'
        ]);
        header("Location: /login_page.php");
        exit();
    }
    
    // Conexão com banco de dados
    $pdo = Database::getConnection();
    $logger->debug('Conexão com banco de dados estabelecida');
    
    // --- 2. Configurações e Manutenção ---
    $settings = getSiteSettings($pdo); // Assume que esta função é eficiente
    
    if (($settings['manutencao'] ?? false) && $_SERVER['REQUEST_URI'] !== '/manutencao.html') {
        $logger->info('Site em manutenção, redirecionando');
        header('Location: /manutencao.html');
        exit();
    }
    
    // --- 3. Roteamento e Preparação de Dados Globais ---
    $uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $page = $uri ?: 'home';
    
    $logger->info('Página solicitada', ['page' => $page, 'user_id' => $_SESSION['usuario_id']]);

    $pages = getSitepages($pdo, $page); // Assume que esta função é eficiente
    
    $dadosGlobais = [
        'user' => getSiteUsers($pdo, $_SESSION['usuario_id']),
        'settings' => $settings,
        'session' => $_SESSION,
        'pagedata' => $pages['pageData'] ?? null,
        'atualizacao' => getLatestExecutionLogByStatus($pdo, 'success'),
        'is_debug' => $isDebug
    ];
    
    // --- 4. Renderização do Layout (Início) ---
    // Otimização: Renderiza header/sidebar (componentes estáticos)
    echo $twig->render('header.twig', $dadosGlobais);
    echo $twig->render('sidebar.twig', $dadosGlobais);
    
    // --- 5. Execução do Controlador e Template ---
    $controllerPath = __DIR__ . "/backend/{$page}.php";
    $templatePath = "{$page}.twig";
    $data = [];
    
    if (file_exists($controllerPath)) {
        $logger->debug('Carregando controlador', ['controller' => $controllerPath]);
        // O controlador deve popular a variável $data
        require_once $controllerPath;
    }
    
    $combinedData = array_merge($dadosGlobais, $data);
    
    try {
        echo $twig->render($templatePath, $combinedData);
        $logger->debug('Página renderizada com sucesso', ['template' => $templatePath]);
    } catch (\Twig\Error\LoaderError $e) {
        $logger->warning('Template não encontrado (404)', ['template' => $templatePath]);
        throw new Exception('Página não encontrada', 404);
    }
    
    // --- 6. Renderização do Layout (Fim) ---
    echo $twig->render('footer.twig', $dadosGlobais);
    
} catch (Exception $e) {
    // --- Tratamento de Erros da Aplicação ---
    $renderLayout = false;
    
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
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Otimização: Renderiza página de erro sem carregar o layout principal
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

    // Usa a função auxiliar para renderizar o erro standalone
    // Assumindo que a função 'renderErrorPage' está disponível no escopo (definida no bootstrap ou em scripts.php)
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
        // Otimização: Cálculo preciso do tempo de execução
        'execution_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms'
    ]);
}