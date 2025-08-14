<?php
$host = '127.0.0.1';
$db   = 'u335174317_wazeportal';
$user = 'seu_usuario';
$pass = 'sua_senha';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lança exceções em erros
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Começa transação para manter integridade
    $pdo->beginTransaction();

    // Deleta detalhes primeiro
    $stmt1 = $pdo->prepare("DELETE FROM fila_envio_detalhes WHERE data_criacao < NOW() - INTERVAL 30 DAY");
    $stmt1->execute();
    $deletedDetalhes = $stmt1->rowCount();

    // Deleta registros principais
    $stmt2 = $pdo->prepare("DELETE FROM fila_envio WHERE data_criacao < NOW() - INTERVAL 30 DAY");
    $stmt2->execute();
    $deletedFila = $stmt2->rowCount();

    $pdo->commit();

    echo "[" . date('Y-m-d H:i:s') . "] Limpeza concluída: detalhes={$deletedDetalhes}, fila={$deletedFila}\n";

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "[" . date('Y-m-d H:i:s') . "] Erro: " . $e->getMessage() . "\n";
}
