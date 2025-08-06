<?php

class ApiBrasilWhatsApp
{
    private $baseUrl = 'https://gateway.apibrasil.io/api/v2/whatsapp/';
    private $deviceToken;
    private $authToken;
    private $timeout;

    public function __construct($deviceToken, $authToken, $timeout = 120)
    {
        $this->deviceToken = $deviceToken;
        $this->authToken = $authToken;
        $this->timeout = $timeout;
    }

    private function request($endpoint, $method, $payload = null)
    {
        $curl = curl_init();

        $headers = [
            'Content-Type: application/json',
            'DeviceToken: ' . $this->deviceToken,
            'Authorization: Bearer ' . $this->authToken
        ];

        $options = [
            CURLOPT_URL => $this->baseUrl . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }

        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return json_encode(['success' => false, 'error' => $err]);
        }

        return $response;
    }

    /**
     * Envia uma mensagem de texto via WhatsApp
     *
     * @param string $number Número de destino no formato 55 + DDD + número
     * @param string $text Texto da mensagem
     * @param int $time_typing Tempo de digitação em milissegundos (opcional)
     * @return string JSON da resposta
     */
    public function enviarTexto($number, $text, $time_typing = 1000)
    {
        $payload = [ 
            'number' => $number,
            'text' => $text,
            'time_typing' => $time_typing
        ];

        return $this->request('sendText', 'POST', $payload);
    }
}
