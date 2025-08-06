<?php

class EvolutionAPI
{
    private $baseUrl = 'https://cluster.apigratis.com/api/v2/evolution/';

    private function request($endpoint, $method, $deviceToken, $authToken, $payload = null)
    {
        $curl = curl_init();

        $options = [
            CURLOPT_URL => $this->baseUrl . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'DeviceToken: ' . $deviceToken,
                'Authorization: Bearer ' . $authToken
            ]
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }

        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return json_encode(['error' => $err]);
        }

        return $response;
    }

    public function criarInstancia($deviceToken, $authToken, $instanceName, $qrcode, $number)
    {
        $payload = [
            'instanceName' => $instanceName,
            'qrcode' => $qrcode,
            'number' => $number
        ];
        return $this->request('instance/create', 'POST', $deviceToken, $authToken, $payload);
    }

    public function statusConexao($deviceToken, $authToken)
    {
        return $this->request('instance/connectionState', 'GET', $deviceToken, $authToken);
    }

    public function enviarTexto($deviceToken, $authToken, $number, $text, $delay = 1200, $presence = 'composing')
    {
        $payload = [
            'number' => $number,
            'options' => [
                'delay' => $delay,
                'presence' => $presence
            ],
            'textMessage' => [
                'text' => $text
            ]
        ];
        return $this->request('message/sendText', 'POST', $deviceToken, $authToken, $payload);
    }

    public function enviarMidia($deviceToken, $authToken, $number, $mediaBase64, $caption = '', $mediaType = 'image', $delay = 1200, $presence = 'composing')
    {
        $payload = [
            'number' => $number,
            'options' => [
                'delay' => $delay,
                'presence' => $presence
            ],
            'mediaMessage' => [
                'mediatype' => $mediaType,
                'caption' => $caption,
                'media' => $mediaBase64
            ]
        ];
        return $this->request('message/sendMedia', 'POST', $deviceToken, $authToken, $payload);
    }

    public function enviarEnquete($deviceToken, $authToken, $number, $pergunta, $opcoes = [], $selectableCount = 1, $delay = 1200, $presence = 'composing')
    {
        $payload = [
            'number' => $number,
            'options' => [
                'delay' => $delay,
                'presence' => $presence
            ],
            'pollMessage' => [
                'name' => $pergunta,
                'selectableCount' => $selectableCount,
                'values' => $opcoes
            ]
        ];
        return $this->request('message/sendPoll', 'POST', $deviceToken, $authToken, $payload);
    }
}
