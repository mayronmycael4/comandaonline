let pedidosMobile = [];
let statusPedidoAtual = 'aberta';
const PEDIDOS_AUTO_REFRESH_MS = 8000;
let pedidosAutoRefreshTimer = null;

document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;

    const lista = document.getElementById('listaPedidosMobile');
    if (lista) {
        lista.innerHTML = '<div class="skeleton-list"><div class="skeleton-card"></div><div class="skeleton-card"></div><div class="skeleton-card"></div></div>';
    }

    const btnLogout = document.getElementById('btnLogoutPedidos');
    if (btnLogout) {
        btnLogout.addEventListener('click', () => {
            Storage.clearSession();
            window.location.href = 'login.html';
        });
    }

    bindFiltros();
    bindContainer();
    await Promise.allSettled([
        carregarEmpresa(),
        carregarPedidos()
    ]);
    iniciarAutoRefreshPedidos();
});

function bindFiltros() {
    const busca = document.getElementById('buscaPedidosMobile');
    if (busca) busca.addEventListener('input', renderPedidos);

    document.querySelectorAll('[data-pedido-status]').forEach((chip) => {
        chip.addEventListener('click', () => {
            statusPedidoAtual = chip.getAttribute('data-pedido-status') || 'todos';
            document.querySelectorAll('[data-pedido-status]').forEach((item) => {
                item.classList.toggle('active', item === chip);
            });
            renderPedidos();
        });
    });
}

function bindContainer() {
    const container = document.getElementById('listaPedidosMobile');
    if (!container) return;

    ComandaModule.bindContainer(container, {
        onClose: fecharComanda,
        onDelete: excluirComanda,
        onOpen: abrirComanda,
        onPrint: ComandaModule.printComanda,
        onReopen: reabrirComanda
    });
}

async function carregarEmpresa() {
    try {
        const empresa = await Storage.getEmpresa();
        const titulo = document.getElementById('nomeEmpresaHeaderPedidos');
        if (titulo && empresa && empresa.nome) titulo.textContent = empresa.nome;
    } catch (error) {
        console.error(error);
    }
}

async function carregarPedidos() {
    try {
        pedidosMobile = await Storage.getComandas();
        Storage.notificarPedidosProntos(pedidosMobile);
        renderPedidos();
    } catch (error) {
        Toast.error('Erro ao carregar pedidos: ' + error.message);
    }
}

function iniciarAutoRefreshPedidos() {
    if (pedidosAutoRefreshTimer) {
        clearInterval(pedidosAutoRefreshTimer);
    }
    pedidosAutoRefreshTimer = setInterval(async () => {
        await carregarPedidos();
    }, PEDIDOS_AUTO_REFRESH_MS);
}

function renderPedidos() {
    const busca = (document.getElementById('buscaPedidosMobile').value || '').trim().toLowerCase();
    let filtradas = [...pedidosMobile];

    if (statusPedidoAtual !== 'todos') {
        filtradas = filtradas.filter((c) => c.status === statusPedidoAtual || (statusPedidoAtual === 'fechada' && c.status === 'cancelada'));
    }

    if (busca) {
        filtradas = filtradas.filter((c) => {
            const mesa = String(c.numeroMesa || '').toLowerCase();
            const cliente = String(c.clienteNome || (c.cliente && c.cliente.nome) || '').toLowerCase();
            const responsavel = String(c.funcionarioNome || '').toLowerCase();
            return mesa.includes(busca) || cliente.includes(busca) || responsavel.includes(busca);
        });
    }

    const container = document.getElementById('listaPedidosMobile');
    ComandaModule.renderCards(container, filtradas, {
        actionLabel: 'Abrir',
        allowDelete: true,
        emptyMessage: 'Nenhum pedido encontrado.'
    });

    const abertas = filtradas.filter((c) => c.status === 'aberta').length;
    const fechadas = filtradas.filter((c) => c.status === 'fechada').length;
    const canceladas = filtradas.filter((c) => c.status === 'cancelada').length;
    const resumo = document.getElementById('resumoPedidosMobile');
    if (resumo) resumo.textContent = `${filtradas.length} pedidos: ${abertas} abertos, ${fechadas} fechados e ${canceladas} cancelados.`;
}

function abrirComanda(id) {
    ComandaModule.openComanda(id);
}

async function fecharComanda(id) {
    try {
        await Storage.fecharComanda(id);
        Toast.success('Comanda fechada com sucesso!');
        await carregarPedidos();
    } catch (error) {
        Toast.error('Erro ao fechar comanda: ' + error.message);
    }
}

async function reabrirComanda(id) {
    try {
        await Storage.reabrirComanda(id);
        Toast.success('Comanda reaberta com sucesso!');
        await carregarPedidos();
    } catch (error) {
        Toast.error('Erro ao reabrir comanda: ' + error.message);
    }
}

async function excluirComanda(id) {
    if (!confirm('Tem certeza que deseja cancelar esta comanda? A cozinha sera avisada.')) return;
    try {
        await Storage.deleteComanda(id);
        Toast.success('Comanda cancelada com sucesso!');
        await carregarPedidos();
    } catch (error) {
        Toast.error('Erro ao cancelar comanda: ' + error.message);
    }
}
