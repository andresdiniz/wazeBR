/**
 * Collection of utility functions for handling cookies, sessions, alerts and logout functionality
 *
 * Functions:
 * - deleteAllCookies: Removes all browser cookies by setting expired date
 * - destroySession: Clears session storage
 * - logout: Performs full logout by clearing cookies/session and redirecting
 * - confirmarAlerta: Makes AJAX call to confirm alert with given UUID and KM
 * - confirmarAlertaModal: Handles alert confirmation from modal UI
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

// Makes AJAX call to confirm alert with given UUID and KM
function confirmarAlerta(uuid, km) {
    if (!uuid || !km) {
        console.error('UUID or KM missing');
        return;
    }
    $.ajax({
        url: '/api.php?action=confirm_alert',
        type: 'POST',
        data: { 
            uuid: uuid,
            km: km,
            status: 1 
        },
        success: function(response) {
            alert('Alerta confirmado com sucesso!');
            $('#alertModal').modal('hide');
        },
        error: function(xhr, status, error) {
            alert('Erro ao confirmar o alerta. Tente novamente.');
            console.error('Error:', error);
        },
    });
}

// Handles alert confirmation from modal UI by validating and calling confirmarAlerta
function confirmarAlertaModal(uuid, km) {
    console.log('Confirmar alerta clicado');

    if (uuid && km) {
        console.log('UUID:', uuid, 'KM:', km);
        confirmarAlerta(uuid, km);
    } else {
        console.error('Erro: UUID ou KM não encontrados');
        alert('Dados inválidos para confirmar o alerta');
    }
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
        return data[0]?.km ?? null;
    } catch (error) {
        console.error("Erro ao buscar o KM no DNIT:", error);
        return null;
    }
}

