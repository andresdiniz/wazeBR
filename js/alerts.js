/* ======================================================
   alerts.js
   Utilitários globais de notificações e tooltips
   Baseado em Popper.js e Notify.js
   ====================================================== */

// ==============================
// DEPENDÊNCIAS REQUERIDAS
// ==============================
// jQuery >= 3.6
// Bootstrap >= 5.0 (para tooltips)
// Notify.js >= 0.4.2
// Popper.js >= 2.11.8

// ==============================
// NOTIFICAÇÕES (Notify.js)
// ==============================

/**
 * Exibe uma notificação elegante em qualquer página.
 * @param {string} message - Texto a ser exibido.
 * @param {string} [type='info'] - Tipo: success | danger | warning | info.
 */
function showAlert(message, type = 'info') {
    const notifyType = {
        success: 'success',
        danger: 'error',
        warning: 'warn',
        info: 'info'
    }[type] || 'info';

    // Garante que o jQuery e Notify estão disponíveis
    if (typeof $ === 'undefined' || typeof $.notify === 'undefined') {
        console.error('Notify.js não está carregado. Verifique a inclusão dos scripts.');
        alert(message);
        return;
    }

    $.notify(message, {
        className: notifyType,
        globalPosition: 'top right',
        autoHideDelay: 4000,
        clickToHide: true,
        showAnimation: 'fadeIn',
        hideAnimation: 'fadeOut'
    });
}

// ==============================
// TOOLTIPS (Popper.js + Bootstrap)
// ==============================

/**
 * Inicializa tooltips Bootstrap para todos os elementos
 * com o atributo [data-bs-toggle="tooltip"].
 */
function initTooltips() {
    if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) {
        console.warn('Bootstrap.Tooltip não encontrado. Tooltips desativados.');
        return;
    }

    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
        new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// ======
