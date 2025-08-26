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

    // Funções de Gerenciamento da Instância

    /**
     * Inicia a instância do WhatsApp.
     *
     * @return string JSON da resposta.
     */
    public function startInstance()
    {
        return $this->request('start', 'POST');
    }

    /**
     * Obtém o QR Code para conectar a instância do WhatsApp.
     *
     * @param string $device_password Senha do dispositivo (opcional).
     * @return string JSON da resposta.
     */
    public function getQRCode($device_password = null)
    {
        $payload = null;
        if ($device_password) {
            $payload = ['device_password' => $device_password];
        }
        return $this->request('qrcode', 'POST', $payload);
    }
    
    /**
     * Obtém o status da fila de mensagens.
     *
     * @return string JSON da resposta.
     */
    public function getQueueStatus()
    {
        return $this->request('fila', 'POST');
    }
    
    // Funções de Mensagens

    /**
     * Envia uma mensagem de texto via WhatsApp.
     *
     * @param string $number Número de destino no formato 55 + DDD + número.
     * @param string $text Texto da mensagem.
     * @param int $time_typing Tempo de digitação em milissegundos (opcional).
     * @return string JSON da resposta.
     */
    public function enviarTexto($number, $text, $time_typing = 200)
    {
        $payload = [
            'number' => $number,
            'text' => $text,
            'time_typing' => $time_typing
        ];

        return $this->request('sendText', 'POST', $payload);
    }

    /**
     * Responde a uma mensagem específica.
     *
     * @param string $number Número de destino.
     * @param string $messageid ID da mensagem original a ser respondida.
     * @param string $text Texto da resposta.
     * @return string JSON da resposta.
     */
    public function replyMessage($number, $messageid, $text)
    {
        $payload = [
            'number' => $number,
            'messageid' => $messageid,
            'text' => $text
        ];
        return $this->request('reply', 'POST', $payload);
    }
    
    /**
     * Envia um arquivo (imagem, documento, etc.) codificado em Base64.
     *
     * @param string $number Número de destino.
     * @param string $base64_data Dados do arquivo em Base64, incluindo o tipo (ex: 'data:image/png;base64,...').
     * @param string $caption Legenda da mídia (opcional).
     * @return string JSON da resposta.
     */
    public function sendFileBase64($number, $base64_data, $caption = null)
    {
        $payload = [
            'number' => $number,
            'path' => $base64_data,
            'caption' => $caption
        ];
        return $this->request('sendFile64', 'POST', $payload);
    }

    // Funções de Grupos

    /**
     * Obtém uma lista de todos os grupos dos quais a instância faz parte.
     *
     * @return string JSON da resposta.
     */
    public function getAllGroups()
    {
        return $this->request('getAllGroups', 'POST');
    }
    
    /**
     * Obtém uma lista detalhada de todos os grupos, incluindo membros.
     *
     * @return string JSON da resposta.
     */
    public function getAllGroupsFull()
    {
        return $this->request('getAllGroupsFull', 'POST');
    }
}