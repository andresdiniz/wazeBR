/**
 * Collection of utility functions for handling cookies, sessions, alerts and logout functionality
 *
 * Functions:
 * - deleteAllCookies: Removes all browser cookies by setting expired date
 * - destroySession: Clears session storage
 * - logout: Performs full logout by clearing cookies/session and redirecting
 * - confirmarAlerta: Makes AJAX call to confirm alert with given UUID and KM
 */

function deleteAllCookies() {
    var cookies = document.cookie.split(";");

    for (var i = 0; i < cookies.length; i++) {
        var cookie = cookies[i];
        var eqPos = cookie.indexOf("=");
        var name = eqPos > -1 ? cookie.substr(0, eqPos).trim() : cookie.trim();
        document.cookie = name + "=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/";
    }
}

// Clears all session storage data
function destroySession() {
    sessionStorage.clear();
}

// Performs full logout by clearing cookies/session and redirecting to login page
function logout() {
    deleteAllCookies();
    destroySession();
    window.location.href = "login.html";
}

// Faz a chamada AJAX para confirmar o alerta com UUID e KM fornecidos
function confirmarAlerta(uuid, km) {
    if (!uuid) {
        console.error('UUID missing');
        alert('UUID não fornecido!');
        return;
    }

    if (!km) {
        console.error('KM missing');
        km = null;  // Se km não for fornecido, definimos como null
    }

    $.ajax({
        url: './api.php?action=confirm_alert',
        type: 'POST',
        data: {
            uuid: uuid,
            km: km,
            status: 1
        },
        success: function (response) {
            try {
                // Parse da resposta JSON
                const result = JSON.parse(response);
                console.log('Resposta recebida:', response);  // Log de resposta

                // Verificar o campo 'success' da resposta
                if (result.success) {
                    // Se 'success' for true, mostramos a mensagem de sucesso
                    alert(result.message);  // Alerta com a mensagem de sucesso
                    $('#alertModal').modal('hide');  // Fecha o modal após sucesso
                } else {
                    // Se 'success' for false, mostramos a mensagem de erro
                    alert(result.message);  // Alerta com a mensagem de erro
                }
            } catch (error) {
                // Caso ocorra erro no parse da resposta
                console.error('Erro ao interpretar a resposta:', error);
                alert('Erro inesperado ao processar a resposta.');
            }
        },
        error: function (xhr, status, error) {
            // Se a requisição AJAX falhar
            console.error('Erro na requisição:', error);
            alert('Erro ao confirmar o alerta. Tente novamente.');
        },
    });
}

async function buscarKmDnit(latitude, longitude, raio = 5) {
    const urlBase = "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm";
    const dataAtual = new Date().toISOString().split('T')[0]; // Formata a data como YYYY-MM-DD
    const url = `${urlBase}?lng=${longitude}&lat=${latitude}&r=${raio}&data=${dataAtual}`;

    try {
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error(`Erro na requisição: ${response.status}`);
        }

        const data = await response.json();
        return data[0]?.km ? parseFloat(data[0].km).toFixed(2) : null;
    } catch (error) {
        console.error("Erro ao buscar o KM no DNIT:", error);
        return null;
    }
}

