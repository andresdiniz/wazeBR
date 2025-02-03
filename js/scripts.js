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
        success: function (response, textStatus, xhr) {
            // Verifica o código de status HTTP da resposta
            if (xhr.status === 200) {
                // Se o código de status for 200, mostra a mensagem de sucesso
                alert(response.message);
                $('#alertModal').modal('hide');  // Fecha o modal após sucesso
            } else if (xhr.status === 400 || xhr.status === 500) {
                // Se o código de status for 400 ou 500, mostra a mensagem de erro
                alert(response.message);
            }
        },
        error: function (xhr, status, error) {
            // Se a requisição AJAX falhar (por exemplo, erro de rede)
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

