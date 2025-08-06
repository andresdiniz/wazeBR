<?php
function buildTrafficAlertEmail($irregularity, $avgSpeed, $subType)
{
    $centerX = ($irregularity['bbox']['minX'] + $irregularity['bbox']['maxX']) / 2;
    $centerY = ($irregularity['bbox']['minY'] + $irregularity['bbox']['maxY']) / 2;

    $wazeUrl = "https://waze.com/ul?ll=$centerY,$centerX&z=12";
    $mapEmbedUrl = "https://embed.waze.com/pt-BR/iframe?zoom=12&lat=$centerY&lon=$centerX";

    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { margin: 0; padding: 0; background: #f4f4f4; font-family: Arial, sans-serif; }
        .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #d9534f; padding: 20px; text-align: center; color: white; font-size: 24px; font-weight: bold; }
        .content { padding: 20px; color: #333333; }
        .alert-badge { background: #dc3545; color: white; padding: 8px 16px; border-radius: 20px; display: inline-block; font-weight: bold; margin-bottom: 16px; }
        .info-box { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 10px; border-left: 5px solid #d9534f; }
        .map-container { text-align: center; margin: 20px 0; }
        iframe { width: 100%; height: 250px; border: none; border-radius: 8px; }
        .button { display: inline-block; padding: 12px 20px; background: #d9534f; color: white; border-radius: 8px; text-decoration: none; font-weight: bold; }
        .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">🚨 Alerta de Tráfego</div>
        <div class="content">
            <div class="alert-badge">Congestionamento nível ' . $irregularity['jamLevel'] . '/5</div>
            <h2>' . $irregularity['name'] . '</h2>
            <div class="info-box"><strong>Extensão:</strong> ' . number_format($irregularity['length'] / 1000, 2) . ' km</div>
            <div class="info-box"><strong>Velocidade:</strong> ' . number_format($avgSpeed, 1) . ' km/h</div>
            <div class="info-box"><strong>Local:</strong> ' . $irregularity['fromName'] . ' → ' . $irregularity['toName'] . '</div>
            <div class="info-box"><strong>Tipo:</strong> ' . $irregularity['type'] . ' (' . $subType . ')</div>
            <div class="info-box"><strong>Última atualização:</strong> ' . date('d/m/Y H:i') . '</div>
            <div class="map-container">
                <iframe src="' . $mapEmbedUrl . '" title="Mapa do Waze"></iframe>
            </div>
            <div style="text-align: center;">
                <a href="' . $wazeUrl . '" class="button">🗺️ Abrir no Waze</a>
            </div>
        </div>
        <div class="footer">
            <p><a href="[UNSUBSCRIBE_URL]">Cancelar inscrição</a> | <a href="[VIEW_IN_BROWSER_URL]">Ver no navegador</a></p>
            <p>Dados de mapa © <a href="https://www.mapbox.com/">Mapbox</a>, © <a href="https://www.openstreetmap.org/">OpenStreetMap</a></p>
        </div>
    </div>
</body>
</html>';
}
