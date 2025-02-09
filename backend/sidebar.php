// Recupera o usuário logado e configurações globais
$data = [
    'user' => getSiteUsers($pdo, $_SESSION['usuario_id']),  // Usuário logado
    'settings' => $settings,  // Configurações do site
    'session' => $_SESSION,
    'pagedata' => $pageData,  // Passando o título para o template
];

// Defina as variáveis globais
global $globalUser, $globalSettings, $globalSession, $globalPageData;

$globalUser = $data['user'];
$globalSettings = $data['settings'];
$globalSession = $data['session'];
$globalPageData = $data['pagedata'];

echo $globalUser;
