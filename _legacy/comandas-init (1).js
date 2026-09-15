let todasComandas = [];
const COMANDAS_AUTO_REFRESH_MS = 8000;
let comandasAutoRefreshTimer = null;

document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;

    const skel = '<div class="skeleton-list"><div class="skeleton-card"></div><div class="skeleton-card"></div></div>';
    const abertasEl = document.getElementById('listaComandasAbertas');
    const fechadasEl = document.getElementById('listaComandasFechadas');
    if (abertasEl) abertasEl.innerHTML = skel;
    if (fechadasEl) fechadasEl.innerHTML = skel;
    
    await carregarFuncionariosFiltro();
    await carregarComandas();
    iniciarAutoRefreshComandas();

    const btnAplicar = document.getElementById('btnAplicarFiltros');
    if (btnAplicar) {
        btnAplicar.addEventListener('click', () => {
            aplicarFiltros();
        });
    }

    const buscaComanda = document.getElementById('buscaComanda');
    if (buscaComanda) {
        buscaComanda.addEventListener('input', aplicarFiltros);
    }

    document.querySelectorAll('[data-quick-status]').forEach(chip => {
        chip.addEventListener('click', () => {
            const valor = chip.getAttribute('data-quick-status') || 'todos';
            const filtroStatus = document.getElementById('filtroStatus');
            if (filtroStatus) {
                filtroStatus.value = valor;
            }

            document.querySelectorAll('[data-quick-status]').forEach(item => {
                item.classList.toggle('active', item === chip);
            });

            aplicarFiltros();
        });
    });

    // Delegação de eventos para cards e botões (CSP bloqueia onclick inline)
    const containers = [
        document.getElementById('listaComandasAbertas'),
        document.getElementById('listaComandasFechadas'),
        document.getElementById('ultimasComandas')
    ].filter(Boolean);

    containers.forEach(container => {
        ComandaModule.bindContainer(container, {
            onClose: fecharComandaRapido,
            onDelete: excluirComanda,
            onOpen: abrirComanda,
            onPrint: ComandaModule.printComanda,
            onReopen: reabrirComandaRapido
        });
    });
});

async function carregarComandas() {
    todasComandas = await Storage.getComandas();
    Storage.notificarPedidosProntos(todasComandas);
    aplicarFiltros();
}

function iniciarAutoRefreshComandas() {
    if (comandasAutoRefreshTimer) {
        clearInterval(comandasAutoRefreshTimer);
    }
    comandasAutoRefreshTimer = setInterval(async () => {
        await carregarComandas();
    }, COMANDAS_AUTO_REFRESH_MS);
}

async function carregarFuncionariosFiltro() {
    const select = document.getElementById('filtroFuncionario');
    const funcionarios = await Storage.getFuncionarios();
    
    select.innerHTML = '<option value="todos">Todos</option>';
    
    funcionarios.forEach(f => {
        select.innerHTML += `<option value="${f.id}">${escapeHtml(f.nome)}</option>`;
    });
}

async function aplicarFiltros() {
    const status = document.getElementById('filtroStatus').value;
    const funcionarioId = document.getElementById('filtroFuncionario').value;
    const busca = ((document.getElementById('buscaComanda') || {}).value || '').trim().toLowerCase();

    let filtradas = [...todasComandas];
    
    if (status !== 'todos') {
        filtradas = filtradas.filter(c => c.status === status);
    }
    
    if (funcionarioId !== 'todos') {
        filtradas = filtradas.filter(c => c.funcionarioId == funcionarioId);
    }

    if (busca) {
        filtradas = filtradas.filter(c => {
            const mesa = String(c.numeroMesa || '').toLowerCase();
            const cliente = String(c.clienteNome || (c.cliente && c.cliente.nome) || '').toLowerCase();
            const responsavel = String(c.funcionarioNome || '').toLowerCase();
            return mesa.includes(busca) || cliente.includes(busca) || responsavel.includes(busca);
        });
    }
    
    // Separa em abertas e fechadas após filtrar
    const comandasAbertas = filtradas.filter(c => c.status === 'aberta');
    const comandasFechadas = filtradas.filter(c => c.status === 'fechada' || c.status === 'cancelada');
    
    renderComandasAbertas(comandasAbertas);
    renderComandasFechadas(comandasFechadas);
    renderUltimasComandas(comandasAbertas);
    atualizarResumoComandasMobile(filtradas, comandasAbertas, comandasFechadas);
}

function atualizarResumoComandasMobile(todasFiltradas, abertas, fechadas) {
    const resumo = document.getElementById('resumoComandasMobile');
    if (!resumo) return;
    const canceladas = todasFiltradas.filter(c => c.status === 'cancelada').length;
    resumo.textContent = `${todasFiltradas.length} comandas encontradas: ${abertas.length} abertas, ${fechadas.length - canceladas} fechadas e ${canceladas} canceladas.`;
}

function renderComandasAbertas(comandas) {
    const lista = document.getElementById('listaComandasAbertas');

    ComandaModule.renderCards(lista, comandas, {
        actionLabel: 'Abrir/Editar',
        allowDelete: true,
        emptyMessage: 'Nenhuma comanda aberta.'
    });
}

function renderComandasFechadas(comandas) {
    const lista = document.getElementById('listaComandasFechadas');

    ComandaModule.renderCards(lista, comandas, {
        actionLabel: 'Abrir/Ver',
        allowDelete: true,
        emptyMessage: 'Nenhuma comanda fechada ou cancelada.'
    });
}

function renderUltimasComandas(comandas) {
    const container = document.getElementById('ultimasComandas');

    ComandaModule.renderCards(container, comandas, {
        actionLabel: 'Abrir/Editar',
        allowDelete: false,
        emptyMessage: 'Nenhuma comanda aberta recentemente.',
        layout: 'compact',
        maxItems: 10
    });
}

function abrirComanda(id) {
    ComandaModule.openComanda(id);
}

function excluirComanda(id) {
    if (confirm('Tem certeza que deseja cancelar esta comanda? A cozinha sera avisada.')) {
        Storage.deleteComanda(id).then(async () => {
            await carregarComandas();
            Toast.success('Comanda cancelada com sucesso!');
        }).catch(err => {
            Toast.error('Erro ao cancelar comanda: ' + err.message);
        });
    }
}

async function fecharComandaRapido(id) {
    try {
        await Storage.fecharComanda(id);
        await carregarComandas();
        Toast.success('Comanda fechada com sucesso!');
    } catch (error) {
        Toast.error('Erro ao fechar comanda: ' + error.message);
    }
}

async function reabrirComandaRapido(id) {
    try {
        await Storage.reabrirComanda(id);
        await carregarComandas();
        Toast.success('Comanda reaberta com sucesso!');
    } catch (error) {
        Toast.error('Erro ao reabrir comanda: ' + error.message);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDateTime(date) {
    if (!date) return 'N/A';
    const d = new Date(date);
    return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR');
}
