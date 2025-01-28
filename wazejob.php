<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set(error_log_level(E_ALL | E_STRICT));
ini_set('error_log', __DIR__ . '/../logs/debug.log');

require_once './vendor/autoload.php';
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

// Verifica se o arquivo .env existe no caminho especificado
$envPath = __DIR__ . '/.env';  // Corrigido o caminho

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
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/debug.log');
    
    // Cria o diretório de logs se não existir
    if (!is_dir(__DIR__ . '/../logs')) {
        mkdir(__DIR__ . '/../logs', 0777, true);
    }

// Executando os scripts com verificação
try {
    echo "Iniciando wazealerts.php<br>";
    executeScript('wazealerts.php', '/wazealerts.php');
    echo "Finalizando wazealerts.php<br>";
} catch (Exception $e) {
    echo 'Erro em wazealerts.php: ' . $e->getMessage() . "<br>";
    logExecution('wazealerts.php', 'error', 'Erro: ' . $e->getMessage());
}

try {
    echo "Iniciando wazejobtraficc.php<br>";
    executeScript('wazejobtraficc.php', '/wazejobtraficc.php');
    echo "Finalizando wazejobtraficc.php<br>";
} catch (Exception $e) {
    echo 'Erro em wazejobtraficc.php: ' . $e->getMessage() . "<br>";
    logExecution('wazejobtraficc.php', 'error', 'Erro: ' . $e->getMessage());
}

try {
    echo "Iniciando dadoscemadem.php<br>";
    executeScript('dadoscemadem.php', '/dadoscemadem.php');
    echo "Finalizando dadoscemadem.php<br>";
} catch (Exception $e) {
    echo 'Erro em dadoscemadem.php: ' . $e->getMessage() . "<br>";
    logExecution('dadoscemadem.php', 'error', 'Erro: ' . $e->getMessage());
}

try {
    echo "Iniciando hidrologicocemadem.php<br>";
    executeScript('hidrologicocemadem.php', '/hidrologicocemadem.php');
    echo "Finalizando hidrologicocemadem.php<br>";
} catch (Exception $e) {
    echo 'Erro em hidrologicocemadem.php: ' . $e->getMessage() . "<br>";
    logExecution('hidrologicocemadem.php', 'error', 'Erro: ' . $e->getMessage());
}

try {
    echo "Iniciando gerar_xml.php<br>";
    executeScript('gerar_xml.php', '/gerar_xml.php');
    echo "Finalizando gerar_xml.php<br>";
} catch (Exception $e) {
    echo 'Erro em gerar_xml.php: ' . $e->getMessage() . "<br>";
    logExecution('gerar_xml.php', 'error', 'Erro: ' . $e->getMessage());
}

?>
