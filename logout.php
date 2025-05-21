<?php
// Segurança básica no header
header("X-XSS-Protection: 1; mode=block");
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com;");
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Inicia sessão para poder destruí-la
session_start();

// Remove todos os dados da sessão
$_SESSION = [];

// Destroi a sessão
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    // Remove o cookie de sessão
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );

    // Remove o cookie parceiro_id
    setcookie('parceiro_id', '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destrói a sessão no servidor
session_destroy();

// Redireciona para a página de login
header("Location: /login?erro=Logout realizado com sucesso");
exit();
