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

    // Funções de Envio

    /**
     * Envia uma mensagem de texto via WhatsApp
     *
     * @param string $number Número de destino no formato 55 + DDD + número
     * @param string $text Texto da mensagem
     * @param int $time_typing Tempo de digitação em milissegundos (opcional)
     * @return string JSON da resposta
     */
    public function enviarTexto($number, $text, $time_typing = 500)
    {
        $payload = [
            'number' => $number,
            'text' => $text,
            'time_typing' => $time_typing
        ];

        return $this->request('sendText', 'POST', $payload);
    }

    /**
     * Envia uma mídia (imagem, vídeo, áudio, documento) via WhatsApp
     *
     * @param string $number Número de destino
     * @param string $caption Legenda da mídia (opcional)
     * @param string $media_url URL da mídia a ser enviada
     * @param string $media_type Tipo de mídia ('image', 'video', 'audio', 'document')
     * @return string JSON da resposta
     */
    public function enviarMidia($number, $caption, $media_url, $media_type)
    {
        $payload = [
            'number' => $number,
            'caption' => $caption,
            'media_url' => $media_url,
            'type' => $media_type
        ];

        return $this->request('sendMedia', 'POST', $payload);
    }

    /**
     * Envia uma mensagem com botões de resposta
     *
     * @param string $number Número de destino
     * @param string $text Texto principal da mensagem
     * @param string $footer Texto do rodapé da mensagem
     * @param array $buttons Array de objetos de botões. Cada objeto deve ter 'id' e 'text'.
     * @return string JSON da resposta
     */
    public function enviarBotoes($number, $text, $footer, $buttons)
    {
        $payload = [
            'number' => $number,
            'text' => $text,
            'footer' => $footer,
            'buttons' => $buttons
        ];

        return $this->request('sendButtons', 'POST', $payload);
    }

    /**
     * Envia uma mensagem de lista
     *
     * @param string $number Número de destino
     * @param string $text Texto principal da mensagem
     * @param string $button_text Texto do botão que exibe a lista
     * @param string $title Título da lista
     * @param string $footer Texto do rodapé da lista
     * @param array $sections Array de seções, cada uma contendo um título e uma lista de opções
     * @return string JSON da resposta
     */
    public function enviarLista($number, $text, $button_text, $title, $footer, $sections)
    {
        $payload = [
            'number' => $number,
            'text' => $text,
            'button_text' => $button_text,
            'title' => $title,
            'footer' => $footer,
            'sections' => $sections
        ];

        return $this->request('sendList', 'POST', $payload);
    }

    /**
     * Envia uma mensagem de template (modelo)
     *
     * @param string $number Número de destino
     * @param string $template_name Nome do template registrado
     * @param string $language_code Código do idioma do template (ex: 'pt_BR')
     * @param array $components Componentes do template (headers, body, etc.)
     * @return string JSON da resposta
     */
    public function enviarTemplate($number, $template_name, $language_code, $components)
    {
        $payload = [
            'number' => $number,
            'template_name' => $template_name,
            'language_code' => $language_code,
            'components' => $components
        ];

        return $this->request('sendTemplate', 'POST', $payload);
    }

    /**
     * Envia as informações de um contato
     *
     * @param string $number Número de destino
     * @param array $contact_info Array com as informações do contato
     * @return string JSON da resposta
     */
    public function enviarContato($number, $contact_info)
    {
        $payload = [
            'number' => $number,
            'contacts' => [$contact_info]
        ];

        return $this->request('sendContact', 'POST', $payload);
    }

    // Funções de Status

    /**
     * Obtém o status da instância
     *
     * @return string JSON da resposta
     */
    public function getStatus()
    {
        return $this->request('status', 'GET');
    }
}