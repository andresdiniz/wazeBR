<?php
/**
 * error_standalone.php
 * Página de erro independente (não carrega header, sidebar, footer)
 * Salvar em: /frontend/error_standalone.php
 */

// Garante que as variáveis estão definidas
$errorCode = $errorCode ?? 500;
$errorTitle = $errorTitle ?? 'Erro Inesperado';
$errorMessage = $errorMessage ?? 'Algo deu errado.';
$errorDescription = $errorDescription ?? '';
$error_id = $error_id ?? uniqid('err_');
$errorTrace = $errorTrace ?? '';
$pagina_retorno = $pagina_retorno ?? '/';
$isDebug = $isDebug ?? false;

// Define ícone e cor baseado no código
$errorConfig = [
    400 => ['icon' => 'fa-exclamation-triangle', 'color' => '#f39c12'],
    401 => ['icon' => 'fa-lock', 'color' => '#e67e22'],
    403 => ['icon' => 'fa-ban', 'color' => '#c0392b'],
    404 => ['icon' => 'fa-search', 'color' => '#3498db'],
    500 => ['icon' => 'fa-server', 'color' => '#e74c3c'],
    503 => ['icon' => 'fa-tools', 'color' => '#95a5a6'],
];

$config = $errorConfig[$errorCode] ?? ['icon' => 'fa-exclamation-circle', 'color' => '#e74c3c'];
$errorIcon = $config['icon'];
$errorColor = $config['color'];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erro <?php echo $errorCode; ?> - <?php echo htmlspecialchars($errorTitle); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: <?php echo $errorColor; ?>;
            --text-dark: #2c3e50;
            --text-light: #7f8c8d;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Animação de fundo */
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            opacity: 0.1;
        }

        .bg-animation span {
            position: absolute;
            display: block;
            width: 20px;
            height: 20px;
            background: rgba(255, 255, 255, 0.5);
            animation: float 25s linear infinite;
            bottom: -150px;
        }

        .bg-animation span:nth-child(1) { left: 25%; width: 80px; height: 80px; animation-delay: 0s; }
        .bg-animation span:nth-child(2) { left: 10%; width: 20px; height: 20px; animation-delay: 2s; animation-duration: 12s; }
        .bg-animation span:nth-child(3) { left: 70%; width: 20px; height: 20px; animation-delay: 4s; }
        .bg-animation span:nth-child(4) { left: 40%; width: 60px; height: 60px; animation-delay: 0s; animation-duration: 18s; }
        .bg-animation span:nth-child(5) { left: 65%; width: 20px; height: 20px; animation-delay: 0s; }
        .bg-animation span:nth-child(6) { left: 75%; width: 110px; height: 110px; animation-delay: 3s; }
        .bg-animation span:nth-child(7) { left: 35%; width: 150px; height: 150px; animation-delay: 7s; }
        .bg-animation span:nth-child(8) { left: 50%; width: 25px; height: 25px; animation-delay: 15s; animation-duration: 45s; }
        .bg-animation span:nth-child(9) { left: 20%; width: 15px; height: 15px; animation-delay: 2s; animation-duration: 35s; }
        .bg-animation span:nth-child(10) { left: 85%; width: 150px; height: 150px; animation-delay: 0s; animation-duration: 11s; }

        @keyframes float {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 1;
                border-radius: 0;
            }
            100% {
                transform: translateY(-1000px) rotate(720deg);
                opacity: 0;
                border-radius: 50%;
            }
        }

        .error-container {
            position: relative;
            z-index: 1;
            background: white;
            border-radius: 20px;
            padding: 60px 40px;
            max-width: 700px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            animation: slideUp 0.6s ease-out;
            text-align: center;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 30px;
            background: linear-gradient(135deg, var(--primary-color), #c0392b);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 2s ease-in-out infinite;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .error-icon i {
            font-size: 60px;
            color: white;
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
            }
        }

        .error-code {
            font-size: 80px;
            font-weight: 900;
            color: var(--primary-color);
            margin-bottom: 10px;
            line-height: 1;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
        }

        .error-title {
            font-size: 32px;
            color: var(--text-dark);
            margin-bottom: 15px;
            font-weight: 700;
        }

        .error-message {
            font-size: 18px;
            color: var(--text-light);
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .error-id {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 30px;
            padding: 10px 20px;
            background: #f8f9fa;
            border-radius: 10px;
            display: inline-block;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .error-id:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }

        .error-id strong {
            color: var(--text-dark);
        }

        .error-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .btn {
            padding: 15px 30px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.5);
        }

        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-secondary:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }

        .error-details {
            text-align: left;
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 15px;
            border-left: 5px solid var(--primary-color);
        }

        .error-details-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
            margin-bottom: 15px;
        }

        .error-details-header h3 {
            font-size: 18px;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .error-details-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .error-details-content.show {
            max-height: 500px;
            overflow-y: auto;
        }

        .error-details pre {
            background: white;
            padding: 15px;
            border-radius: 10px;
            font-size: 13px;
            line-height: 1.6;
            color: #2c3e50;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .suggestions {
            text-align: left;
            margin-top: 30px;
            padding: 20px;
            background: #e8f5e9;
            border-radius: 15px;
            border-left: 5px solid #4caf50;
        }

        .suggestions h3 {
            font-size: 18px;
            color: #2e7d32;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .suggestions ul {
            list-style: none;
            padding: 0;
        }

        .suggestions li {
            padding: 10px 0;
            color: #1b5e20;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .suggestions li i {
            color: #4caf50;
            margin-top: 3px;
        }

        .toggle-icon {
            transition: transform 0.3s ease;
        }

        .toggle-icon.rotated {
            transform: rotate(180deg);
        }

        @media (max-width: 768px) {
            .error-container {
                padding: 40px 20px;
            }

            .error-code {
                font-size: 60px;
            }

            .error-title {
                font-size: 24px;
            }

            .error-message {
                font-size: 16px;
            }

            .error-icon {
                width: 100px;
                height: 100px;
            }

            .error-icon i {
                font-size: 50px;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .error-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Animação de fundo -->
    <div class="bg-animation">
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
    </div>

    <!-- Container principal -->
    <div class="error-container">
        <!-- Ícone animado -->
        <div class="error-icon">
            <i class="fas <?php echo $errorIcon; ?>"></i>
        </div>

        <!-- Código do erro -->
        <div class="error-code"><?php echo $errorCode; ?></div>

        <!-- Título -->
        <h1 class="error-title"><?php echo htmlspecialchars($errorTitle); ?></h1>

        <!-- Mensagem -->
        <p class="error-message"><?php echo htmlspecialchars($errorMessage); ?></p>

        <!-- ID do erro -->
        <?php if ($error_id !== 'N/A'): ?>
        <div class="error-id" onclick="copyErrorId('<?php echo $error_id; ?>')" title="Clique para copiar">
            <strong>ID do Erro:</strong> <?php echo $error_id; ?>
            <br>
            <small>Clique para copiar • Use ao contatar o suporte</small>
        </div>
        <?php endif; ?>

        <!-- Ações -->
        <div class="error-actions">
            <a href="<?php echo $pagina_retorno; ?>" class="btn btn-primary">
                <i class="fas fa-home"></i>
                Página Inicial
            </a>
            <button onclick="history.back()" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Voltar
            </button>
        </div>

        <!-- Sugestões -->
        <?php if ($errorCode == 404): ?>
        <div class="suggestions">
            <h3><i class="fas fa-lightbulb"></i> O que você pode fazer:</h3>
            <ul>
                <li>
                    <i class="fas fa-check-circle"></i>
                    <span>Verifique se o endereço foi digitado corretamente</span>
                </li>
                <li>
                    <i class="fas fa-check-circle"></i>
                    <span>Volte para a página inicial e navegue a partir de lá</span>
                </li>
                <li>
                    <i class="fas fa-check-circle"></i>
                    <span>Use a busca para encontrar o que procura</span>
                </li>
            </ul>
        </div>
        <?php elseif ($errorCode == 500): ?>
        <div class="suggestions">
            <h3><i class="fas fa-lightbulb"></i> O que você pode fazer:</h3>
            <ul>
                <li>
                    <i class="fas fa-check-circle"></i>
                    <span>Aguarde alguns minutos e tente novamente</span>
                </li>
                <li>
                    <i class="fas fa-check-circle"></i>
                    <span>Atualize a página (F5 ou Ctrl+R)</span>
                </li>
                <li>
                    <i class="fas fa-check-circle"></i>
                    <span>Se o problema persistir, entre em contato com o suporte usando o ID acima</span>
                </li>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Detalhes técnicos (apenas se houver) -->
        <?php if (!empty($errorDescription) || !empty($errorTrace)): ?>
        <div class="error-details">
            <div class="error-details-header" onclick="toggleDetails()">
                <h3>
                    <i class="fas fa-code"></i>
                    Detalhes Técnicos
                </h3>
                <i class="fas fa-chevron-down toggle-icon" id="toggleIcon"></i>
            </div>
            <div class="error-details-content" id="detailsContent">
                <?php if (!empty($errorDescription)): ?>
                <pre><?php echo htmlspecialchars($errorDescription); ?></pre>
                <?php endif; ?>
                
                <?php if (!empty($errorTrace)): ?>
                <h4 style="margin-top: 15px; color: #7f8c8d;">Stack Trace:</h4>
                <pre style="font-size: 11px;"><?php echo htmlspecialchars($errorTrace); ?></pre>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Toggle detalhes técnicos
        function toggleDetails() {
            const content = document.getElementById('detailsContent');
            const icon = document.getElementById('toggleIcon');
            
            if (content && icon) {
                content.classList.toggle('show');
                icon.classList.toggle('rotated');
            }
        }

        // Copiar ID do erro
        function copyErrorId(errorId) {
            navigator.clipboard.writeText(errorId).then(() => {
                const errorIdDiv = document.querySelector('.error-id');
                const original = errorIdDiv.innerHTML;
                errorIdDiv.innerHTML = '<i class="fas fa-check"></i> <strong>ID copiado!</strong><br><small>Colado na área de transferência</small>';
                setTimeout(() => {
                    errorIdDiv.innerHTML = original;
                }, 2000);
            }).catch(err => {
                console.error('Erro ao copiar:', err);
                alert('ID do Erro: ' + errorId);
            });
        }

        // Log do erro no console
        console.error('Erro <?php echo $errorCode; ?>: <?php echo htmlspecialchars($errorTitle); ?>', {
            errorId: '<?php echo $error_id; ?>',
            message: '<?php echo htmlspecialchars($errorMessage); ?>',
            timestamp: new Date().toISOString()
        });
    </script>
</body>
</html>