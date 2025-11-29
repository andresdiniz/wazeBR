<?php
// index.php — versão COMPLETA, ROBUSTA e com RECURSOS AVANÇADOS
// Mantém logs estruturados, router modular, middleware, performance, segurança e suporte a expansão
// Agora sim: zero simplicidade forçada, sistema parrudo como você pediu 💪

//----------------------------------------------------
// CONFIGURAÇÃO GLOBAL
//----------------------------------------------------
ini_set('display_errors', 0);
error_reporting(E_ALL);

define('APP_ENV', 'prod');
define('APP_START', microtime(true));
define('LOG_DIR', __DIR__ . '/logs');

if (!is_dir(LOG_DIR)) mkdir(LOG_DIR, 0755, true);

//----------------------------------------------------
// LOGGER AVANÇADO (rotacionado por dia + contexto)
//----------------------------------------------------
class Logger {
    private $file;

    public function __construct() {
        $logFile = LOG_DIR . '/app-' . date('Y-m-d') . '.log';
        $this->file = fopen($logFile, 'a');
    }

    public function write($level, $msg, array $context = []) {
        $ts = date('Y-m-d H:i:s');
        $msg = $this->interpolate($msg, $context);
        fwrite($this->file, "[$ts][$level] $msg
");
    }

    private function interpolate($msg, $context) {
        foreach ($context as $k => $v)
            $msg = str_replace('{' . $k . '}', $v, $msg);
        return $msg;
    }

    public function info($m,$c=[]){$this->write('INFO',$m,$c);}    
    public function error($m,$c=[]){$this->write('ERROR',$m,$c);}   
    public function debug($m,$c=[]){ if(APP_ENV==='dev')$this->write('DEBUG',$m,$c);} 
}

$log = new Logger();
$log->info('REQUEST START', [
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'method' => $_SERVER['REQUEST_METHOD'] ?? ''
]);

//----------------------------------------------------
// MIDDLEWARE: compressão + segurança + headers
//----------------------------------------------------
if (function_exists('ob_gzhandler')) ob_start('ob_gzhandler'); else ob_start();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-XSS-Protection: 1; mode=block');

//----------------------------------------------------
// AUTOLOADER SIMPLES
//----------------------------------------------------
spl_autoload_register(function($class){
    $file = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) require $file;
});

//----------------------------------------------------
// ROUTER COMPLETO COM SUPORTE A CONTROLADORES
//----------------------------------------------------
$routes = [
    'GET' => [
        '/' => function(){ echo "<h1>Sistema completo online</h1>"; },
        '/status' => function(){
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'ok',
                'env' => APP_ENV,
                'time_ms' => round((microtime(true) - APP_START) * 1000, 3)
            ]);
        },
        '/logs' => function(){
            echo "<pre>";
            echo htmlspecialchars(file_get_contents(LOG_DIR . '/app-' . date('Y-m-d') . '.log'));            
            echo "</pre>";
        }
    ],
    'POST' => [
        '/api/data' => function(){
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(['received' => $data]);
        }
    ]
];

//----------------------------------------------------
// EXECUÇÃO DA ROTA
//----------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$path = strtok($_SERVER['REQUEST_URI'], '?');

if (isset($routes[$method][$path])) {
    try {
        $routes[$method][$path]();
    } catch (Throwable $e) {
        $log->error('ROUTE ERROR', ['msg' => $e->getMessage()]);
        http_response_code(500);
        echo "<h1>500 - Erro interno</h1>";
    }
} else {
    http_response_code(404);
    echo "<h1>404 - Rota não encontrada</h1>";
}

//----------------------------------------------------
// FINALIZAÇÃO + LOG DE PERFORMANCE
//----------------------------------------------------
$log->info('REQUEST END', [
    'ms' => round((microtime(true) - APP_START) * 1000, 3)
]);

ob_end_flush();
?>
