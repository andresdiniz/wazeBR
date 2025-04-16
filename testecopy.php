<?php
// Inclua o autoload do Composer (caminho correto dependendo do seu projeto)
require 'vendor/autoload.php';

// Instanciando o PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    // Configurações do servidor SMTP
    $mail->isSMTP();                                            // Enviar via SMTP
    $mail->Host       = 'smtp.hostinger.com';                        // Defina o servidor SMTP (Gmail usado como exemplo)
    $mail->SMTPAuth   = true;                                     // Habilitar autenticação SMTP
    $mail->Username   = 'sac@wazeportal.com.br';                    // Seu endereço de e-mail
    $mail->Password   = '@Ndre2025';                               // Sua senha de e-mail (use senhas de app se necessário)
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;           // Habilitar criptografia TLS
    $mail->Port       = 587;                                      // Porta SMTP (587 para TLS)

    // Remetente e destinatário
    $mail->setFrom('sac@wazeportal.com.br', 'Waze Portal BR');
    $mail->addAddress('andresoaresdiniz201218@gmail.com', 'Destinatário'); // Substitua pelo destinatário

    // Assunto e corpo do e-mail
    $mail->Subject = 'Teste de Envio de E-mail com PHPMailer';
    $mail->Body    = 'Este é um e-mail de teste enviado usando o PHPMailer e SMTP.';
    $mail->AltBody = 'Este é o corpo do e-mail em formato texto simples, caso o HTML não seja suportado.';

    // Envia o e-mail
    if ($mail->send()) {
        echo 'E-mail enviado com sucesso!';
    } else {
        echo 'Falha ao enviar o e-mail.';
    }
} catch (Exception $e) {
    echo "Erro ao enviar e-mail. Erro: {$mail->ErrorInfo}";
}
?>
