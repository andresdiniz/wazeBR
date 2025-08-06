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

function processUsersOnJams($pdo, $usersOnJams, $urlId, $id_parceiro, $currentTime)
{
    $stmt = $pdo->prepare("INSERT INTO users_on_jams (user_id, jam_id, url_id, id_parceiro, created_at)
                            VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE created_at = VALUES(created_at)");

    foreach ($usersOnJams as $userJam) {
        $stmt->execute([
            $userJam['userID'],
            $userJam['jamID'],
            $urlId,
            $id_parceiro,
            $currentTime
        ]);
    }
}

function processRoutes($pdo, $routes, $urlId, $id_parceiro)
{
    $stmt = $pdo->prepare("INSERT INTO route_updates (route_id, status, eta, url_id, id_parceiro, updated_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE status = VALUES(status), eta = VALUES(eta), updated_at = NOW()");

    foreach ($routes as $route) {
        $stmt->execute([
            $route['routeID'],
            $route['status'],
            $route['eta'],
            $urlId,
            $id_parceiro
        ]);
    }
}

function processIrregularities($pdo, $irregularities, $urlId, $id_parceiro)
{
    $stmt = $pdo->prepare("INSERT INTO irregularities (irregularity_id, name, type, sub_type, length, jamLevel, fromName, toName, bbox, url_id, id_parceiro, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE 
                                name = VALUES(name), type = VALUES(type), sub_type = VALUES(sub_type),
                                length = VALUES(length), jamLevel = VALUES(jamLevel), fromName = VALUES(fromName),
                                toName = VALUES(toName), bbox = VALUES(bbox), updated_at = NOW()");

    foreach ($irregularities as $item) {
        $bbox = json_encode($item['bbox']);

        $stmt->execute([
            $item['irregularityID'],
            $item['name'],
            $item['type'],
            $item['subtype'],
            $item['length'],
            $item['jamLevel'],
            $item['fromName'],
            $item['toName'],
            $bbox,
            $urlId,
            $id_parceiro
        ]);
    }
}

