<?php
function generateUuid()
{
    return bin2hex(random_bytes(16));
}

function calculateSpeed($lengthMeters, $timeSeconds)
{
    if ($timeSeconds <= 0 || $lengthMeters <= 0) return 0;
    return ($lengthMeters / 1000) / ($timeSeconds / 3600);
}

function safeGet($array, $key, $default = null)
{
    return isset($array[$key]) ? $array[$key] : $default;
}

function formatCoordinate($x, $y)
{
    return number_format($x, 6) . ',' . number_format($y, 6);
}

function compareCoordinatesArray($array1, $array2)
{
    $formatted1 = array_map(fn($p) => formatCoordinate($p['x'], $p['y']), $array1);
    $formatted2 = array_map(fn($p) => formatCoordinate($p['x'], $p['y']), $array2);

    sort($formatted1);
    sort($formatted2);

    return $formatted1 === $formatted2;
}

function shouldSendAlert($pdo, $hash, &$newCooldown)
{
    $stmt = $pdo->prepare("SELECT cooldown_until, send_count FROM alert_cooldown WHERE alert_hash = ?");
    $stmt->execute([$hash]);
    $cooldownData = $stmt->fetch();

    $now = new DateTime();
    if ($cooldownData) {
        $cooldownUntil = new DateTime($cooldownData['cooldown_until']);
        $sendCount = $cooldownData['send_count'];

        if ($now < $cooldownUntil) return false;

        $newCooldown = match (true) {
            $sendCount >= 5 => '30 MINUTE',
            $sendCount >= 3 => '15 MINUTE',
            default => '1 MINUTE'
        };
    } else {
        $newCooldown = '30 MINUTE';
    }

    return true;
}

function updateCooldown($pdo, $hash, $cooldown, $sendCount)
{
    $stmt = $pdo->prepare("INSERT INTO alert_cooldown 
        (alert_hash, last_sent, cooldown_until, send_count)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            last_sent = VALUES(last_sent),
            cooldown_until = VALUES(cooldown_until),
            send_count = VALUES(send_count)");

    $cooldownUntil = (new DateTime())->modify("+$cooldown")->format('Y-m-d H:i:s');

    $stmt->execute([
        $hash,
        date('Y-m-d H:i:s'),
        $cooldownUntil,
        $sendCount + 1
    ]);
}

function sendEmail($to, $body, $subject)
{
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: alertas@seusistema.com.br\r\n";

    return mail($to, $subject, $body, $headers);
}