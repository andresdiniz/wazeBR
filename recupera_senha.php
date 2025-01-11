<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criação de Senha</title>
    <style>
        .error {
            color: red;
        }
        .success {
            color: green;
        }
        button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <h2>Criar Senha</h2>
    <form action="save_password.php" method="POST" id="passwordForm">
        <label for="password1">Senha:</label><br>
        <input type="password" id="password1" name="password1"><br>
        <label for="password2">Confirmar Senha:</label><br>
        <input type="password" id="password2" name="password2"><br>
        <ul id="requirements">
            <li id="length" class="error">Mínimo de 8 caracteres</li>
            <li id="uppercase" class="error">Pelo menos uma letra maiúscula</li>
            <li id="number" class="error">Pelo menos um número</li>
            <li id="symbol" class="error">Pelo menos um símbolo</li>
            <li id="match" class="error">As senhas devem ser iguais</li>
        </ul>
        <button type="submit" id="submitButton" disabled>Salvar Senha</button>
    </form>

    <script>
        const password1 = document.getElementById('password1');
        const password2 = document.getElementById('password2');
        const requirements = {
            length: document.getElementById('length'),
            uppercase: document.getElementById('uppercase'),
            number: document.getElementById('number'),
            symbol: document.getElementById('symbol'),
            match: document.getElementById('match'),
        };
        const submitButton = document.getElementById('submitButton');

        function validatePasswords() {
            const pwd1 = password1.value;
            const pwd2 = password2.value;

            // Verificar requisitos
            requirements.length.className = pwd1.length >= 8 ? 'success' : 'error';
            requirements.uppercase.className = /[A-Z]/.test(pwd1) ? 'success' : 'error';
            requirements.number.className = /[0-9]/.test(pwd1) ? 'success' : 'error';
            requirements.symbol.className = /[\W_]/.test(pwd1) ? 'success' : 'error';
            requirements.match.className = pwd1 === pwd2 ? 'success' : 'error';

            // Habilitar botão apenas se tudo estiver válido
            const allValid = Object.values(requirements).every(req => req.className === 'success');
            submitButton.disabled = !allValid;
        }

        password1.addEventListener('input', validatePasswords);
        password2.addEventListener('input', validatePasswords);
    </script>
</body>
</html>
