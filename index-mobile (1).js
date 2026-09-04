let todasComandasMobile = [];
let statusAtualMobile = 'aberta';
const COMANDAS_MOBILE_AUTO_REFRESH_MS = 8000;
let comandasMobileAutoRefreshTimer = null;

function reaplicarFuncionarioLogadoMobile(selectId) {
    const select = document.getElementById(selectId);
    const session = Storage.getSession();
    if (!select || !session || !session.funcionarioId) return;
    select.value = String(session.funcionarioId);
}

document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;

    const btnLogout = document.getElementById('btnLogoutMobileHeader');
    if (btnLogout) {
        btnLogout.addEventListener('click', () => {
            Storage.clearSession();
            window.location.href = 'login.html';
        });
    }

    bindMobileComandasContainer();
    bindMobileFilters();
    bindMobileForm();

    await carregarEmpresaMobile();
    await carregarFuncionariosMobile();
    await carregarComandasMobile();
    iniciarAutoRefreshComandasMobile();
});

function bindMobileComandasContainer() {
    const container = document.getElementById('listaComandasMobile');
    if (!container) return;

    ComandaModule.bindContainer(container, {
        onClose: fecharComandaMobile,
        onDelete: excluirComandaMobile,
        onOpen: abrirComanda,
        onPrint: ComandaModule.printComanda,
        onReopen: reabrirComandaMobile
    });
}

function bindMobileFilters() {
    const busca = document.getElementById('buscaComandaMobile');
    if (busca) {
        busca.addEventListener('input', renderComandasMobile);
    }

    document.querySelectorAll('[data-mobile-status]').forEach(chip => {
        chip.addEventListener('click', () => {
            statusAtualMobile = chip.getAttribute('data-mobile-status') || 'todos';
            document.querySelectorAll('[data-mobile-status]').forEach(item => {
                item.classList.toggle('active', item === chip);
            });
            renderComandasMobile();
        });
    });
}

function bindMobileForm() {
    const form = document.getElementById('formComandaMobile');
    if (!form) return;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const numeroMesa = document.getElementById('numeroMesaMobile').value.trim();
        const selectFuncionario = document.getElementById('funcionarioResponsavelMobile');
        const funcionarioId = selectFuncionario ? selectFuncionario.value : '';

        if (selectFuncionario && selectFuncionario.disabled) {
            Toast.warning('Aguarde carregar os funcionários.');
            return;
        }

        if (!numeroMesa || !funcionarioId) {
            Toast.error('Preencha mesa e funcionário.');
            return;
        }

        try {
            const comanda = await Storage.addComanda({
                numeroMesa,
                funcionarioId: Number(funcionarioId),
                cliente: { nome: '', contato: '', observacoes: '' },
                itens: []
            });

            form.reset();
            reaplicarFuncionarioLogadoMobile('funcionarioResponsavelMobile');
            Toast.success('Comanda criada com sucesso!');
            await carregarComandasMobile();
            window.location.href = `comanda.html?comandaId=${comanda.id}`;
        } catch (error) {
            Toast.error('Erro ao criar comanda: ' + error.message);
        }
    });
}

async function carregarEmpresaMobile() {
    try {
        const empresa = await Storage.getEmpresa();
        if (empresa) {
            const titulo = document.getElementById('nomeEmpresaHeaderMobile');
            if (titulo) titulo.textContent = empresa.nome;
        }
    } catch (error) {
        console.error('Erro ao carregar empresa no mobile:', error);
    }
}

async function carregarFuncionariosMobile() {
    const select = document.getElementById('funcionarioResponsavelMobile');
    if (!select) return;

    select.disabled = true;
    select.innerHTML = '<option value="">Carregando funcionários...</option>';

    try {
        const funcionarios = await Storage.getFuncionarios();
        const session = Storage.getSession();
        const funcionarioLogadoId = session && session.funcionarioId ? String(session.funcionarioId) : '';
        select.innerHTML = '<option value="">Selecione...</option>';
        funcionarios.forEach(funcionario => {
            select.innerHTML += `<option value="${funcionario.id}">${escapeHtml(funcionario.nome)}</option>`;
        });

        select.disabled = funcionarios.length === 0;

        if (funcionarioLogadoId && funcionarios.some((f) => String(f.id) === funcionarioLogadoId)) {
            select.value = funcionarioLogadoId;
        }

        if (funcionarios.length === 0) {
            Toast.warning('Nenhum funcionário ativo disponível para abrir comanda.');
        }
    } catch (error) {
        select.disabled = true;
        select.innerHTML = '<option value="">Falha ao carregar funcionários</option>';
        Toast.error('Não foi possível carregar funcionários.');
        console.error('Erro ao carregar funcionários no mobile:', error);
    }
}

async function carregarComandasMobile() {
    try {
        todasComandasMobile = await Storage.getComandas();
        Storage.notificarPedidosProntos(todasComandasMobile);
        atualizarStatsMobile();
        renderComandasMobile();
    } catch (error) {
        console.error('Erro ao carregar comandas no mobile:', error);
    }
}

function iniciarAutoRefreshComandasMobile() {
    if (comandasMobileAutoRefreshTimer) {
        clearInterval(comandasMobileAutoRefreshTimer);
    }
    comandasMobileAutoRefreshTimer = setInterval(async () => {
        await carregarComandasMobile();
    }, COMANDAS_MOBILE_AUTO_REFRESH_MS);
}

function atualizarStatsMobile() {
    const hoje = new Date();
    const abertas = todasComandasMobile.filter(c => c.status === 'aberta');
    const fechadasHoje = todasComandasMobile.filter(c => {
        if (c.status !== 'fechada') return false;
        const dataFechamento = c.fechamento ? new Date(c.fechamento.data) : new Date(c.createdAt);
        return dataFechamento.toDateString() === hoje.toDateString();
    });

    const elAbertas = document.getElementById('statComandasAbertasMobile');
    const elFechadas = document.getElementById('statComandasFechadasMobile');

    if (elAbertas) elAbertas.textContent = abertas.length;
    if (elFechadas) elFechadas.textContent = fechadasHoje.length;
}

function renderComandasMobile() {
    const busca = (document.getElementById('buscaComandaMobile').value || '').trim().toLowerCase();

    let filtradas = [...todasComandasMobile];

    if (statusAtualMobile !== 'todos') {
        filtradas = filtradas.filter(c => c.status === statusAtualMobile || (statusAtualMobile === 'fechada' && c.status === 'cancelada'));
    }

    if (busca) {
        filtradas = filtradas.filter(c => {
            const mesa = String(c.numeroMesa || '').toLowerCase();
            const cliente = String(c.clienteNome || (c.cliente && c.cliente.nome) || '').toLowerCase();
            const responsavel = String(c.funcionarioNome || '').toLowerCase();
            return mesa.includes(busca) || cliente.includes(busca) || responsavel.includes(busca);
        });
    }

    const container = document.getElementById('listaComandasMobile');
    ComandaModule.renderCards(container, filtradas, {
        actionLabel: 'Abrir',
        allowDelete: true,
        emptyMessage: 'Nenhuma comanda encontrada.'
    });

    const abertas = filtradas.filter(c => c.status === 'aberta').length;
    const fechadas = filtradas.filter(c => c.status === 'fechada').length;
    const canceladas = filtradas.filter(c => c.status === 'cancelada').length;
    const resumo = document.getElementById('resumoComandasMobile');
    if (resumo) {
        resumo.textContent = `${filtradas.length} comandas: ${abertas} abertas, ${fechadas} fechadas e ${canceladas} canceladas.`;
    }
}

function abrirComanda(id) {
    ComandaModule.openComanda(id);
}

async function excluirComandaMobile(id) {
    if (!confirm('Tem certeza que deseja cancelar esta comanda? A cozinha sera avisada.')) return;
    try {
        await Storage.deleteComanda(id);
        Toast.success('Comanda cancelada com sucesso!');
        await carregarComandasMobile();
    } catch (error) {
        Toast.error('Erro ao cancelar comanda: ' + error.message);
    }
}

async function fecharComandaMobile(id) {
    try {
        await Storage.fecharComanda(id);
        Toast.success('Comanda fechada com sucesso!');
        await carregarComandasMobile();
    } catch (error) {
        Toast.error('Erro ao fechar comanda: ' + error.message);
    }
}

async function reabrirComandaMobile(id) {
    try {
        await Storage.reabrirComanda(id);
        Toast.success('Comanda reaberta com sucesso!');
        await carregarComandasMobile();
    } catch (error) {
        Toast.error('Erro ao reabrir comanda: ' + error.message);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
