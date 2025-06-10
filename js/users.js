class UserManager {
    constructor() {
        this.initModals();
        this.initEventListeners();
        this.initApiHandlers();
        // this.refreshUserLists(); // Removido daqui
    }

    initModals() {
        this.modals = {
            cadastrar: new bootstrap.Modal(document.getElementById('modalCadastrar')),
            alterar: new bootstrap.Modal(document.getElementById('modalAlterar')),
            alterarfinal: new bootstrap.Modal(document.getElementById('modalAlterarFinal')),
            resetSenha: new bootstrap.Modal(document.getElementById('modalResetSenha')),
            apagar: new bootstrap.Modal(document.getElementById('modalApagar'))
        };
    }

    initEventListeners() {
        // Geração de senha
        document.getElementById('generatePass')?.addEventListener('click', () => this.generatePassword());

        // Submissão de formulários
        document.getElementById('formCadastrar')?.addEventListener('submit', (e) => this.handleFormSubmit(e, 'cadastrar'));
        document.getElementById('formAlterar')?.addEventListener('submit', (e) => this.handleFormSubmit(e, 'alterar'));

        // Buscas dinâmicas
        ['Alterar', 'ResetSenha', 'Apagar'].forEach(action => {
            const input = document.getElementById(`search${action}`);
            input?.addEventListener('input', (e) => this.handleSearch(e, action.toLowerCase()));
        });

        // Ações nos resultados
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.btn-action');
            if (btn) this.handleUserAction(btn);
            console.log('Button clicked:', btn);
        });

        // Reset de Senha
        document.getElementById('confirmResetSenha')?.addEventListener('click', () => this.handlePasswordReset());

        // Confirmação de Exclusão
        document.getElementById('confirmDelete')?.addEventListener('click', () => this.handleUserDelete());

        // Carregar a lista de usuários ao carregar a página (opcional)
        // this.refreshUserLists();
    }

    initApiHandlers() {
        this.api = {
            request: async (endpointKey, method = 'GET', data = null) => {
                const endpoint = globalConfig.endpoints[endpointKey];
                if (!endpoint) {
                    console.error(`Endpoint not found: ${endpointKey}`);
                    throw new Error(`Endpoint não encontrado: ${endpointKey}`);
                }
                try {
                    const baseURL = new URL(globalConfig.apiBase, window.location.href);
                    const url = new URL(baseURL);
                    console.log(`Making API request to ${url.href} with method ${method} and data:`, data);

                    // Adiciona parâmetros de consulta
                    url.searchParams.append('action', endpoint);
                    url.searchParams.append('id_parceiro', globalConfig.parceiroId);

                    const config = {
                        method,
                        headers: {}
                    };

                    if (method === 'GET' && data) {
                        Object.keys(data).forEach(key => url.searchParams.append(key, data[key]));
                    } else if (data) {
                        config.body = data instanceof FormData ? data : JSON.stringify(data);
                        config.headers = data instanceof FormData ? {} : { 'Content-Type': 'application/json' };
                    }

                    const res = await fetch(url, config);
                    if (!res.ok) {
                        const errorText = await res.text();
                        console.error(`API Error on ${endpoint}:`, errorText);
                        throw new Error(errorText);
                    }
                    return await res.json();
                } catch (error) {
                    this.showToast('danger', error.message || 'Erro na comunicação com a API');
                    console.error('API Request Error:', error);
                    throw error;
                }
            }
        };
    }

    async handleFormSubmit(event, action) {
        event.preventDefault();
        const form = event.target;
        if (!form.checkValidity()) return form.classList.add('was-validated');

        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        const spinner = submitBtn.querySelector('.spinner-border');
        spinner?.classList.remove('d-none');

        try {
            const formData = new FormData(form);
            const endpoints = {
                cadastrar: { method: 'POST', endpoint: 'users' },
                alterar: { method: 'PUT', endpoint: 'updateUser' }
            };

            if (action === 'alterar') formData.append('id', document.getElementById('editarIdUsuario').value);

            const { method, endpoint } = endpoints[action];
            const response = await this.api.request(endpoint, method, formData);

            if (response.success) {
                this.showToast('success', `Usuário ${action === 'cadastrar' ? 'cadastrado' : 'atualizado'}!`);
                this.modals[action].hide();
                form.reset();
                if (action === 'alterar') {
                    console.log('Alteração bem-sucedida, chamando refreshUserLists');
                    await this.refreshUserLists();
                }
                // Se você quiser fazer algo específico após o cadastro (sem ser o refresh da lista), faça aqui.
            }
        } catch (error) {
            this.showToast('danger', error.message);
        } finally {
            submitBtn.disabled = false;
            spinner?.classList.add('d-none');
        }
    }

    async handleSearch(event, action) {
        const searchTerm = event.target.value.trim();
        if (searchTerm.length < 3) return;

        try {
            const response = await this.api.request('searchUsers', 'GET', {
                q: searchTerm,
                actionType: action
            });
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
                        <i class="fas fa-${this.getActionIcon(action)}"></i>
                    </button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="4">Nenhum resultado</td></tr>';
    }

    getActionIcon(action) {
        const icons = {
            alterar: 'edit',
            resetSenha: 'key',
            apagar: 'trash'
        };
        return icons[action] || 'question';
    }

    async handleUserAction(button) {
        const action = button.dataset.action;
        const userId = button.dataset.id;

        try {
            if (action === 'alterar') {
                const response = await this.api.request('getUser', 'GET', { id: userId });
                console.log('User data for edit:', response.user);
                this.populateEditForm(response.user);
                console.log(`Handling action: ${action} for user ID: ${userId}`);
                this.modals.alterarfinal.show();
            }
            else if (action === 'apagar') {
                this.selectedUser = userId;
                this.modals.apagar.show();
            }
            else if (action === 'resetSenha') {
                this.selectedUser = userId;
                this.modals.resetSenha.show();
            }
        } catch (error) {
            this.showToast('danger', error.message);
        }
    }

    async handlePasswordReset() {
        try {
            const response = await this.api.request('resetPassword', 'POST', {
                id: this.selectedUser
            });

            if (response.success) {
                this.showToast('success', 'Senha resetada com sucesso!');
                this.modals.resetSenha.hide();
                await this.refreshUserLists();
            }
        } catch (error) {
            this.showToast('danger', error.message);
        }
    }

    async handleUserDelete() {
        try {
            const response = await this.api.request('deleteUser', 'DELETE', {
                id: this.selectedUser
            });

            if (response.success) {
                this.showToast('success', 'Usuário excluído com sucesso!');
                this.modals.apagar.hide();
                await this.refreshUserLists();
            }
        } catch (error) {
            this.showToast('danger', error.message);
        }
    }

    populateEditForm(user) {
        document.getElementById('editarIdUsuario').value = user.id;
        document.getElementById('editarNomeUsuario').value = user.nome;
        document.getElementById('editarEmailUsuario').value = user.email;
        document.getElementById('editarUsername').value = user.username;
        document.getElementById('editarType').value = user.type;
        document.getElementById('editarParceiro').value = user.id_parceiro;
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

    async refreshUserLists() {
        try {
            const response = await this.api.request('listUsers', 'GET');
            ['Alterar', 'ResetSenha', 'Apagar'].forEach(action => {
                this.updateResults(action.toLowerCase(), response.users);
            });
        } catch (error) {
            this.showToast('danger', error.message);
        }
    }

    showToast(type, message) {
        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center text-white bg-${type} border-0`;
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;

        const toastContainer = document.getElementById('toastContainer') || this.createToastContainer();
        toastContainer.appendChild(toastEl);

        new bootstrap.Toast(toastEl, { autohide: true, delay: 3000 }).show();
        setTimeout(() => toastEl.remove(), 3000);
    }

    createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toastContainer';
        container.style.position = 'fixed';
        container.style.top = '20px';
        container.style.right = '20px';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
        return container;
    }
}

// Configuração Global
const globalConfig = {
    apiBase: '../api.php',
    parceiroId: id_parceiro, // Variável global definida no PHP
    endpoints: {
        users: 'cadastrar_usuario',
        updateUser: 'atualizar_usuario',
        deleteUser: 'apagar_usuario',
        searchUsers: 'get_users',
        listUsers: 'listar_usuarios',
        getUser: 'obter_usuario',
        resetPassword: 'resetar_senha'
    }
};

// Inicialização
document.addEventListener('DOMContentLoaded', () => new UserManager());