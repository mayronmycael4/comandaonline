document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;

    const btnLogout = document.getElementById('btnLogoutNova');
    if (btnLogout) {
        btnLogout.addEventListener('click', () => {
            Storage.clearSession();
            window.location.href = 'login.html';
        });
    }

    await carregarEmpresa();
    await carregarFuncionarios();
    await atualizarResumoTurno();

    const form = document.getElementById('formNovaComandaMobile');
    if (!form) return;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const numeroMesa = (document.getElementById('numeroMesaNovaMobile').value || '').trim();
        const funcionarioId = Number(document.getElementById('funcionarioResponsavelNovaMobile').value);

        if (!numeroMesa || !funcionarioId) {
            Toast.warning('Preencha mesa e responsavel.');
            return;
        }

        try {
            const comanda = await Storage.addComanda({
                numeroMesa,
                funcionarioId,
                cliente: { nome: '', contato: '', observacoes: '' },
                itens: []
            });

            Toast.success('Comanda aberta com sucesso!');
            window.location.href = `comanda.html?comandaId=${comanda.id}`;
        } catch (error) {
            Toast.error('Erro ao abrir comanda: ' + error.message);
        }
    });
});

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
    } catch (error) {
        Toast.error('Erro ao carregar funcionarios.');
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
