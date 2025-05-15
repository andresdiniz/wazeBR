<?php
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'error_log.log');
date_default_timezone_set('America/Sao_Paulo');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_once './config/configbd.php';
        require_once './functions/scripts.php';

        // Validação CSRF
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
            die('Token CSRF inválido');
        }

        $pdo = Database::getConnection();

        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT id, password, nome, email, username, photo, id_parceiro, type 
                              FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $dataHora = (new DateTime())->format('Y-m-d H:i:s');
        $ip = substr(getIp(), 0, 50);
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido', 0, 255);

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION = [
                'usuario_id' => $user['id'],
                'usuario_nome' => $user['nome'],
                'usuario_email' => $user['email'],
                'usuario_username' => $user['username'],
                'usuario_photo' => $user['photo'],
                'usuario_id_parceiro' => $user['id_parceiro'],
                'type' => $user['type'],
                'csrf_token' => bin2hex(random_bytes(32))
            ];

            $stmt = $pdo->prepare("INSERT INTO historic_login 
                                   (user_id, login_time, ip, user_agent, success)
                                   VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$user['id'], $dataHora, $ip, $userAgent]);

            header("Location: /dashboard");
            exit();
        }

        // Falha
        $stmt = $pdo->prepare("INSERT INTO historic_login 
                               (login_time, ip, user_agent, success)
                               VALUES (?, ?, ?, 0)");
        $stmt->execute([$dataHora, $ip, $userAgent]);

        sleep(random_int(1, 3));
        header("Location: /login.php?erro=Credenciais inválidas");
        exit();

    } catch (PDOException $e) {
        error_log("Erro PDO: " . $e->getMessage());
        header("Location: /login.php?erro=Erro no sistema");
        exit();
    } catch (Exception $e) {
        error_log("Erro geral: " . $e->getMessage());
        header("Location: /login.php?erro=Erro inesperado");
        exit();
    }
} else {
    // GET request - Geração do token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrf_token = $_SESSION['csrf_token'];
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Waze BR - Login de Parceiros</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --waze-blue: #33CCFF;
            --waze-orange: #FF6633;
            --dark-blue: #1A237E;
        }

        body {
            background: linear-gradient(45deg, var(--dark-blue), var(--waze-blue));
            height: 100vh;
            font-family: 'Roboto', sans-serif;
        }

        .login-card {
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            background: rgba(255,255,255,0.95);
            overflow: hidden;
        }

        .brand-section {
            background: linear-gradient(45deg, var(--waze-blue), var(--waze-orange));
            padding: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-logo {
            width: 220px;
            transition: transform 0.3s ease;
        }

        .brand-logo:hover {
            transform: scale(1.05);
        }

        .form-section {
            padding: 40px;
        }

        .form-control {
            border-radius: 10px;
            padding: 15px 20px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--waze-blue);
            box-shadow: 0 0 15px rgba(51,204,255,0.2);
        }

        .btn-waze {
            background: linear-gradient(45deg, var(--waze-blue), var(--waze-orange));
            color: white;
            padding: 15px 30px;
            border-radius: 10px;
            border: none;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
        }

        .btn-waze:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(51,204,255,0.3);
        }

        .link-secondary {
            color: var(--dark-blue) !important;
            text-decoration: none;
            position: relative;
        }

        .link-secondary:after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--waze-orange);
            transition: width 0.3s ease;
        }

        .link-secondary:hover:after {
            width: 100%;
        }

        .alert-waze {
            background: rgba(255,102,51,0.1);
            border-left: 4px solid var(--waze-orange);
            border-radius: 0 8px 8px 0;
        }

        @media (max-width: 768px) {
            .brand-section {
                padding: 30px;
            }
            .form-section {
                padding: 30px;
            }
            .brand-logo {
                width: 180px;
            }
        }
    </style>
</head>
<body class="d-flex align-items-center">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-10 col-xl-8">
            <div class="login-card">
                <div class="row g-0">
                    <!-- Marca -->
                    <div class="col-md-6 brand-section">
                        <img src="/img/login-img.jpeg" alt="Waze Brasil" class="brand-logo">
                    </div>

                    <!-- Formulário -->
                    <div class="col-md-6 form-section">
                        <div class="text-center mb-5">
                            <h2 class="mb-3">Acesso de Parceiros</h2>
                            <p class="text-muted">Plataforma de gestão colaborativa</p>
                        </div>

                        <!-- Erro -->
                        <?php if (isset($_GET['erro'])): ?>
                            <div class="alert alert-waze mb-4">
                                <?= htmlspecialchars($_GET['erro']) ?>
                            </div>
                        <?php endif; ?>

                        <!-- Form -->
                        <form method="POST" action="login.php">
                            <div class="mb-4">
                                <input type="email" class="form-control" name="email" placeholder="Email institucional" required>
                            </div>
                            <div class="mb-4">
                                <input type="password" class="form-control" name="password" placeholder="Senha de acesso" required minlength="6">
                            </div>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                            <div class="mb-4 form-check">
                                <input type="checkbox" class="form-check-input" id="rememberMe">
                                <label class="form-check-label" for="rememberMe">Manter conectado</label>
                            </div>

                            <button type="submit" class="btn btn-waze w-100 mb-4">Acessar Plataforma</button>

                            <div class="text-center">
                                <a href="forgot-password.html" class="link-secondary">Esqueceu sua senha?</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Rodapé -->
            <div class="text-center mt-4">
                <p class="text-white mb-0">Novo parceiro? <a href="#contact" class="text-white fw-bold">Solicite credenciais</a></p>
                <p class="text-white mt-2">Suporte: <a href="mailto:parceiros@wazebrasil.com" class="text-white">parceiros@wazebrasil.com</a></p>
            </div>
        </div>
    </div>
</div>
</body>
</html>
