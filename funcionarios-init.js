let funcionariosCache = [];

document.addEventListener('DOMContentLoaded', () => {
    if (!Storage.requireAuth()) return;

    const session = Storage.getSession();
    if (!session.isAdmin) {
        Toast.error('Apenas administradores podem gerenciar funcionários');
        window.location.href = 'index.html';
        return;
    }

    bindEvents();
    carregarFuncionarios();
});

function bindEvents() {
    document.getElementById('formFuncionario').addEventListener('submit', cadastrarFuncionario);
    document.getElementById('listaFuncionarios').addEventListener('click', onTabelaFuncionarioClick);
    document.getElementById('formEditar').addEventListener('submit', salvarEdicaoFuncionario);
    document.getElementById('btnFecharModalFuncionario').addEventListener('click', fecharModal);
    document.getElementById('btnCancelarModalFuncionario').addEventListener('click', fecharModal);
    document.getElementById('tipoFuncionario').addEventListener('change', syncPermissoesComTipoNovo);
    document.getElementById('editarTipo').addEventListener('change', syncPermissoesComTipoEditar);

    bindPermissoesVisualState('permissoesNovoFuncionario');
    bindPermissoesVisualState('permissoesEditarFuncionario');
}

async function carregarFuncionarios() {
    funcionariosCache = await Storage.getFuncionarios();
    renderFuncionarios();
}

function getPermissoesFromContainer(containerId) {
    return Array.from(document.querySelectorAll(`#${containerId} input[type="checkbox"]:checked`)).map(i => i.value);
}

function setPermissoesInContainer(containerId, permissoes = []) {
    const set = new Set(permissoes);
    document.querySelectorAll(`#${containerId} input[type="checkbox"]`).forEach(input => {
        input.checked = set.has(input.value);
    });
    applyPermissoesVisualState(containerId);
}

function syncPermissoesComTipoNovo() {
    const isAdmin = document.getElementById('tipoFuncionario').value === 'admin';
    const checks = document.querySelectorAll('#permissoesNovoFuncionario input[type="checkbox"]');

    if (isAdmin) {
        checks.forEach(input => {
            input.checked = true;
        });
    } else {
        checks.forEach(input => {
            input.checked = input.value === 'dashboard' || input.value === 'comandas';
        });
    }

    applyPermissoesVisualState('permissoesNovoFuncionario');
}

function syncPermissoesComTipoEditar() {
    const isAdmin = document.getElementById('editarTipo').value === 'admin';
    const checks = document.querySelectorAll('#permissoesEditarFuncionario input[type="checkbox"]');

    if (isAdmin) {
        checks.forEach(input => {
            input.checked = true;
        });
    } else {
        checks.forEach(input => {
            input.checked = input.value === 'dashboard' || input.value === 'comandas';
        });
    }

    applyPermissoesVisualState('permissoesEditarFuncionario');
}

function applyPermissoesVisualState(containerId) {
    document.querySelectorAll(`#${containerId} label`).forEach(label => {
        const input = label.querySelector('input[type="checkbox"]');
        if (!input) return;
        label.classList.toggle('is-checked', !!input.checked);
    });
}

function bindPermissoesVisualState(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || target.type !== 'checkbox') return;
        applyPermissoesVisualState(containerId);
    });

    applyPermissoesVisualState(containerId);
}

async function cadastrarFuncionario(event) {
    event.preventDefault();

    const nome = document.getElementById('nomeFuncionario').value.trim();
    const login = document.getElementById('loginFuncionario').value.trim();
    const senha = document.getElementById('senhaFuncionario').value;
    const tipo = document.getElementById('tipoFuncionario').value;
    const permissoes = getPermissoesFromContainer('permissoesNovoFuncionario');

    const existente = await Storage.getFuncionarioByLogin(login);
    if (existente) {
        Toast.error('Já existe um funcionário com este login');
        return;
    }

    try {
        await Storage.addFuncionario({
            nome,
            login,
            senha,
            is_admin: tipo === 'admin',
            permissoes
        });

        document.getElementById('formFuncionario').reset();
        syncPermissoesComTipoNovo();
        await carregarFuncionarios();
        Toast.success('Funcionário cadastrado com sucesso!');
    } catch (error) {
        Toast.error('Erro ao cadastrar funcionário: ' + error.message);
    }
}

function renderFuncionarios() {
    const container = document.getElementById('listaFuncionarios');
    const session = Storage.getSession();
    const isMobilePage = window.location.pathname.toLowerCase().includes('funcionarios-mobile.html');
    const isMobileViewport = window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
    const isMobile = isMobilePage || (window.MobileRouting && window.MobileRouting.isMobileClient()) || isMobileViewport;

    if (funcionariosCache.length === 0) {
        container.innerHTML = '<p class="empty">Nenhum funcionário cadastrado.</p>';
        return;
    }

    if (isMobile) {
        container.innerHTML = funcionariosCache.map(f => {
            const permissoes = Array.isArray(f.permissoes) ? f.permissoes : [];
            return `
                <div class="lista-item funcionario-mobile-card">
                    <h3>${escapeHtml(f.nome)}</h3>
                    <p>Login: ${escapeHtml(f.login)}</p>
                    <p>Tipo: ${f.is_admin || f.isAdmin ? 'Administrador' : 'Funcionário'}</p>
                    <div style="margin-top: 8px;">${formatPermissoes(permissoes)}</div>
                    <div class="comanda-card-actions" style="margin-top: 10px;">
                        ${f.id === session.funcionarioId
                            ? '<span class="hint">Você</span>'
                            : `<button class="btn btn-warning btn-small" type="button" data-action="editar" data-id="${f.id}">Editar</button>
                               <button class="btn btn-danger btn-small" type="button" data-action="excluir" data-id="${f.id}">Excluir</button>`
                        }
                    </div>
                </div>
            `;
        }).join('');
        return;
    }

    container.innerHTML = `
        <table class="tabela-itens" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Login</th>
                    <th>Tipo</th>
                    <th>Permissões</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                ${funcionariosCache.map(f => {
                    const permissoes = Array.isArray(f.permissoes) ? f.permissoes : [];
                    return `
                        <tr>
                            <td>${escapeHtml(f.nome)}</td>
                            <td>${escapeHtml(f.login)}</td>
                            <td>${f.is_admin || f.isAdmin ? 'Administrador' : 'Funcionário'}</td>
                            <td>${formatPermissoes(permissoes)}</td>
                            <td>
                                ${f.id === session.funcionarioId
                                    ? '<span class="hint">Você</span>'
                                    : `<button class="btn btn-warning btn-small" type="button" data-action="editar" data-id="${f.id}">Editar</button>
                                       <button class="btn btn-danger btn-small" type="button" data-action="excluir" data-id="${f.id}">Excluir</button>`
                                }
                            </td>
                        </tr>
                    `;
                }).join('')}
            </tbody>
        </table>
    `;
}

function onTabelaFuncionarioClick(event) {
    const actionEl = event.target.closest('[data-action][data-id]');
    if (!actionEl) return;

    const id = Number(actionEl.getAttribute('data-id'));
    const action = actionEl.getAttribute('data-action');
    if (action === 'editar') {
        abrirModalEdicao(id);
    } else if (action === 'excluir') {
        excluirFuncionario(id);
    }
}

function abrirModalEdicao(id) {
    const funcionario = funcionariosCache.find(f => f.id == id);
    if (!funcionario) {
        Toast.error('Funcionário não encontrado');
        return;
    }

    document.getElementById('editarId').value = funcionario.id;
    document.getElementById('editarNome').value = funcionario.nome;
    document.getElementById('editarLogin').value = funcionario.login;
    document.getElementById('editarSenha').value = '';
    document.getElementById('editarTipo').value = (funcionario.is_admin || funcionario.isAdmin) ? 'admin' : 'normal';
    setPermissoesInContainer('permissoesEditarFuncionario', funcionario.permissoes || []);
    applyPermissoesVisualState('permissoesEditarFuncionario');
    document.getElementById('modalEditar').style.display = 'flex';
}

function fecharModal() {
    document.getElementById('modalEditar').style.display = 'none';
}

async function salvarEdicaoFuncionario(event) {
    event.preventDefault();

    const id = Number(document.getElementById('editarId').value);
    const nome = document.getElementById('editarNome').value.trim();
    const login = document.getElementById('editarLogin').value.trim();
    const senha = document.getElementById('editarSenha').value;
    const isAdmin = document.getElementById('editarTipo').value === 'admin';
    const permissoes = getPermissoesFromContainer('permissoesEditarFuncionario');

    try {
        await Storage.updateFuncionario({
            id,
            nome,
            login,
            senha,
            is_admin: isAdmin,
            permissoes
        });

        const session = Storage.getSession();
        if (session && id === session.funcionarioId) {
            session.nome = nome;
            session.login = login;
            session.isAdmin = isAdmin;
            session.permissoes = permissoes;
            localStorage.setItem(Storage.SESSION_KEY, JSON.stringify(session));
        }

        fecharModal();
        await carregarFuncionarios();
        Toast.success('Funcionário atualizado com sucesso!');
    } catch (error) {
        Toast.error('Erro ao atualizar funcionário: ' + error.message);
    }
}

async function excluirFuncionario(id) {
    const session = Storage.getSession();
    if (id === session.funcionarioId) {
        Toast.error('Você não pode excluir a si mesmo');
        return;
    }

    if (!confirm('Tem certeza que deseja excluir este funcionário?')) return;

    try {
        await Storage.deleteFuncionario(id);
        await carregarFuncionarios();
        Toast.success('Funcionário excluído com sucesso!');
    } catch (error) {
        Toast.error('Erro ao excluir: ' + error.message);
    }
}

function formatPermissoes(permissoes) {
    if (!permissoes || permissoes.length === 0) return '<span class="hint">Sem permissões</span>';

    const labels = {
        dashboard: 'Dashboard',
        comandas: 'Comandas',
        cozinha: 'Cozinha',
        produtos: 'Produtos',
        estoque: 'Estoque',
        clientes: 'Clientes',
        relatorios: 'Relatórios',
        backup: 'Backup',
        funcionarios: 'Funcionários'
    };

    return permissoes.map(p => `<span class="perm-tag">${labels[p] || p}</span>`).join(' ');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}
