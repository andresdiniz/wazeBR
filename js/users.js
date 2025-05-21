class UserManager {
    constructor() {
        this.initModals();
        this.initEventListeners();
        this.initApiHandlers();
    }

    initModals() {
        this.modals = {
            cadastrar: new bootstrap.Modal('#modalCadastrar'),
            alterar: new bootstrap.Modal('#modalAlterar'),
            resetSenha: new bootstrap.Modal('#modalResetSenha'),
            apagar: new bootstrap.Modal('#modalApagar')
        };
    }

    initEventListeners() {
        // Geração de senha
        document.getElementById('generatePass').addEventListener('click', () => this.generatePassword());

        // Submissão de formulários
        document.getElementById('formCadastrar').addEventListener('submit', (e) => this.handleFormSubmit(e, 'cadastrar'));
        document.getElementById('formAlterar').addEventListener('submit', (e) => this.handleFormSubmit(e, 'alterar'));

        // Buscas dinâmicas
        ['Alterar', 'ResetSenha', 'Apagar'].forEach(action => {
            const input = document.getElementById(`search${action}`);
            input && input.addEventListener('input', (e) => this.handleSearch(e, action.toLowerCase()));
        });
    }

    initApiHandlers() {
        this.api = {
            request: async (endpoint, method = 'GET', body = null) => {
                const url = new URL(`${globalConfig.apiBase}/${globalConfig.endpoints[endpoint]}`);
                url.searchParams.append('id_parceiro', globalConfig.parceiroId);

                try {
                    const res = await fetch(url, {
                        method,
                        headers: { 'Content-Type': 'application/json' },
                        body: body && JSON.stringify(body)
                    });
                    return await res.json();
                } catch (error) {
                    this.showToast('danger', 'Erro na comunicação com o servidor');
                    throw error;
                }
            }
        };
    }

    async handleFormSubmit(event, action) {
        event.preventDefault();
        const form = event.target;
        const submitBtn = form.querySelector('button[type="submit"]');

        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            return;
        }

        submitBtn.disabled = true;
        submitBtn.querySelector('.spinner-border').classList.remove('d-none');

        try {
            const formData = new FormData(form);
            const response = await this.api.request(
                action === 'cadastrar' ? 'users' : `users/${formData.get('id')}`,
                action === 'cadastrar' ? 'POST' : 'PUT',
                Object.fromEntries(formData)
            );

            if (response.success) {
                this.showToast('success', `Usuário ${action === 'cadastrar' ? 'cadastrado' : 'atualizado'} com sucesso!`);
                this.modals[action].hide();
                form.reset();
                this.refreshUserLists();
            }
        } catch (error) {
            this.showToast('danger', error.message);
        } finally {
            submitBtn.disabled = false;
            submitBtn.querySelector('.spinner-border').classList.add('d-none');
        }
    }

    generatePassword() {
        const charset = {
            uppercase: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            lowercase: 'abcdefghijklmnopqrstuvwxyz',
            numbers: '0123456789',
            symbols: '!@#$%&*?'
        };

        let password = [
            charset.uppercase[Math.floor(Math.random() * 26)],
            charset.lowercase[Math.floor(Math.random() * 26)],
            charset.numbers[Math.floor(Math.random() * 10)],
            charset.symbols[Math.floor(Math.random() * 7)]
        ];

        for (let i = 4; i < 12; i++) {
            const charType = Object.keys(charset)[Math.floor(Math.random() * 4)];
            password.push(charset[charType][Math.floor(Math.random() * charset[charType].length)]);
        }

        const senhaInput = document.getElementById('senhaUsuario');
        senhaInput.value = password.sort(() => 0.5 - Math.random()).join('');
        senhaInput.dispatchEvent(new Event('input'));
    }

    async handleSearch(event, action) {
        const searchTerm = event.target.value.trim();
        if (searchTerm.length < 3) return;

        try {
            const response = await this.api.request('users', 'GET', { q: searchTerm });
            this.updateResults(action, response.users);
        } catch (error) {
            this.showToast('danger', error.message);
        }
    }

    updateResults(action, users) {
        const container = document.getElementById(`result${action.charAt(0).toUpperCase() + action.slice(1)}`);
        if (!container) return;

        container.innerHTML = users.map(user => `
            <tr>
                <td>${user.nome}</td>
                <td>${user.username}</td>
                <td>${user.email}</td>
                <td>
                    <button class="btn btn-sm btn-${action === 'apagar' ? 'danger' : 'warning'} btn-action" 
                            data-id="${user.id}" 
                            data-action="${action}">
                        <i class="fas fa-${action === 'apagar' ? 'trash' : 'edit'}"></i>
                    </button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="4">Nenhum resultado encontrado</td></tr>';
    }

    async refreshUserLists() {
        try {
            const response = await this.api.request('users');
            ['Alterar', 'ResetSenha', 'Apagar'].forEach(action =>
                this.updateResults(action.toLowerCase(), response.users)
            );
        } catch (error) {
            this.showToast('danger', error.message);
        }
    }

    showToast(type, message) {
        const toast = new bootstrap.Toast(document.createElement('div'));
        toast._element.className = `toast show bg-${type} text-white`;
        toast._element.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        document.body.appendChild(toast._element);
        toast.show();
        setTimeout(() => toast.dispose(), 4000);
    }

    generatePassword() {
        const charset = {
            uppercase: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            lowercase: 'abcdefghijklmnopqrstuvwxyz',
            numbers: '0123456789',
            symbols: '!@#$%&*?'
        };

        let password = [
            charset.uppercase[Math.floor(Math.random() * 26)],
            charset.lowercase[Math.floor(Math.random() * 26)],
            charset.numbers[Math.floor(Math.random() * 10)],
            charset.symbols[Math.floor(Math.random() * 7)]
        ];

        for (let i = 4; i < 12; i++) {
            const charType = Object.keys(charset)[Math.floor(Math.random() * 4)];
            password.push(charset[charType][Math.floor(Math.random() * charset[charType].length)]);
        }

        const senhaInput = document.getElementById('senhaUsuario');
        senhaInput.value = password.sort(() => 0.5 - Math.random()).join('');
        senhaInput.dispatchEvent(new Event('input'));
    }
}

// Inicialização
document.addEventListener('DOMContentLoaded', () => new UserManager());