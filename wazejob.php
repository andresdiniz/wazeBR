<?php

require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/functions/scripts.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

ini_set('log_errors', 1);
ini_set('error_log', 'error_log.log');

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
