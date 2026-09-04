document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;
    let formBusy = false;

    const btnLogout = document.getElementById('btnLogoutNova');
    if (btnLogout) {
        btnLogout.addEventListener('click', () => {
            Storage.clearSession();
            window.location.href = 'login.html';
        });
    }

    await carregarEmpresa();
    bindFuncionarioSyncStatusNovaMobile();
    await carregarFuncionarios();
    await atualizarResumoTurno();

    const form = document.getElementById('formNovaComandaMobile');
    if (!form) return;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (formBusy) return;

        const numeroMesa = (document.getElementById('numeroMesaNovaMobile').value || '').trim();
        const funcionarioId = Number(document.getElementById('funcionarioResponsavelNovaMobile').value);

        if (!numeroMesa || !funcionarioId) {
            Toast.warning('Preencha mesa e responsavel.');
            return;
        }

        try {
            formBusy = true;
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.dataset.originalText = submitBtn.textContent;
                submitBtn.textContent = 'Enviando...';
            }

            const comanda = await Storage.addComanda({
                numeroMesa,
                funcionarioId,
                cliente: { nome: '', contato: '', observacoes: '' },
                itens: []
            });

            Toast.success('Comanda aberta com sucesso!');
            window.location.href = `comanda.html?comandaId=${comanda.id}`;
        } catch (error) {
            Toast.error('Nao foi possivel abrir a comanda em tempo real: ' + error.message);
        } finally {
            formBusy = false;
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = submitBtn.dataset.originalText || 'Abrir Comanda';
            }
        }
    });
});

function getFuncionarioHintNovaMobile() {
    let hint = document.getElementById('funcionariosSyncHintNovaMobile');
    if (hint) return hint;
    const select = document.getElementById('funcionarioResponsavelNovaMobile');
    if (!select || !select.parentElement) return null;
    hint = document.createElement('small');
    hint.id = 'funcionariosSyncHintNovaMobile';
    hint.style.display = 'block';
    hint.style.marginTop = '4px';
    hint.style.opacity = '0.85';
    hint.style.fontSize = '12px';
    select.parentElement.appendChild(hint);
    return hint;
}

function setFuncionarioHintNovaMobile(msg, level = 'info') {
    const hint = getFuncionarioHintNovaMobile();
    if (!hint) return;
    hint.textContent = msg || '';
    hint.style.color = level === 'warn' ? '#c0392b' : (level === 'ok' ? '#1b7f3b' : '#555');
}

function bindFuncionarioSyncStatusNovaMobile() {
    window.addEventListener('comanda-sync-status', (event) => {
        const detail = event && event.detail ? event.detail : {};
        const message = String(detail.message || '');
        if (!message) return;
        if (message.toLowerCase().includes('funcionario')) {
            setFuncionarioHintNovaMobile(message, detail.level || 'info');
        }
    });
}

async function carregarEmpresa() {
    try {
        const empresa = await Storage.getEmpresa();
        const titulo = document.getElementById('nomeEmpresaHeaderNova');
        if (titulo && empresa && empresa.nome) titulo.textContent = empresa.nome;
    } catch (error) {
        console.error(error);
    }
}

async function carregarFuncionarios() {
    const select = document.getElementById('funcionarioResponsavelNovaMobile');
    if (!select) return;

    try {
        const cacheLocal = typeof Storage.getFuncionariosCache === 'function'
            ? Storage.getFuncionariosCache()
            : [];

        if (Array.isArray(cacheLocal) && cacheLocal.length > 0) {
            const sessionCache = Storage.getSession();
            const funcionarioLogadoCache = sessionCache && sessionCache.funcionarioId ? String(sessionCache.funcionarioId) : '';
            select.innerHTML = '<option value="">Selecione...</option>';
            cacheLocal.forEach((funcionario) => {
                select.innerHTML += `<option value="${funcionario.id}">${escapeHtml(funcionario.nome)}</option>`;
            });
            if (funcionarioLogadoCache && cacheLocal.some((f) => String(f.id) === funcionarioLogadoCache)) {
                select.value = funcionarioLogadoCache;
            }
            setFuncionarioHintNovaMobile('Funcionarios em cache prontos para uso.', 'ok');
        } else {
            setFuncionarioHintNovaMobile('Carregando funcionarios...');
        }

        const funcionarios = await Storage.getFuncionarios();
        const session = Storage.getSession();
        const funcionarioLogadoId = session && session.funcionarioId ? String(session.funcionarioId) : '';
        select.innerHTML = '<option value="">Selecione...</option>';
        funcionarios.forEach((funcionario) => {
            select.innerHTML += `<option value="${funcionario.id}">${escapeHtml(funcionario.nome)}</option>`;
        });

        if (funcionarioLogadoId && funcionarios.some((f) => String(f.id) === funcionarioLogadoId)) {
            select.value = funcionarioLogadoId;
        }
        if (funcionarios.length > 0) {
            setFuncionarioHintNovaMobile('Funcionarios prontos para uso.', 'ok');
        } else {
            setFuncionarioHintNovaMobile('Sem funcionarios disponiveis no momento.', 'warn');
        }
    } catch (error) {
        Toast.error('Erro ao carregar funcionarios.');
        setFuncionarioHintNovaMobile('Falha ao carregar funcionarios.', 'warn');
    }
}

async function atualizarResumoTurno() {
    try {
        const comandas = await Storage.getComandas();
        const hoje = new Date();
        const abertas = comandas.filter((c) => c.status === 'aberta').length;
        const fechadasHoje = comandas.filter((c) => {
            if (c.status !== 'fechada') return false;
            const data = c.fechamento && c.fechamento.data ? new Date(c.fechamento.data) : new Date(c.createdAt);
            return data.toDateString() === hoje.toDateString();
        }).length;

        const abertasEl = document.getElementById('statAbertasNovaMobile');
        const fechadasEl = document.getElementById('statFechadasNovaMobile');
        if (abertasEl) abertasEl.textContent = String(abertas);
        if (fechadasEl) fechadasEl.textContent = String(fechadasHoje);
    } catch (error) {
        console.error(error);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}
