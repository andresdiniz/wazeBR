<?php
require_once __DIR__ . '/config/configbd.php';
require_once __DIR__ . '/classes/Logger.php';
require_once __DIR__ . '/whatsapp_alerts.php';

$pdo = Database::getConnection();
$logger = Logger::getInstance(__DIR__ . '/logs');
$whatsapp = new WhatsAppAlertService($pdo, $logger);

$whatsapp->sendDailyReport();
?>