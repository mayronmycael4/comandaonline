document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;

    const btnLogout = document.getElementById('btnLogoutCaixa');
    if (btnLogout) {
        btnLogout.addEventListener('click', () => {
            Storage.clearSession();
            window.location.href = 'login.html';
        });
    }

    await carregarEmpresa();
    await carregarFechamento();
});

async function carregarEmpresa() {
    try {
        const empresa = await Storage.getEmpresa();
        const titulo = document.getElementById('nomeEmpresaHeaderCaixa');
        if (titulo && empresa && empresa.nome) titulo.textContent = empresa.nome;
    } catch (error) {
        console.error(error);
    }
}

async function carregarFechamento() {
    try {
        const comandas = await Storage.getComandas();
        const hoje = new Date();

        const fechadas = comandas.filter((c) => c.status === 'fechada');
        const fechadasHoje = fechadas.filter((c) => {
            const data = c.fechamento && c.fechamento.data ? new Date(c.fechamento.data) : new Date(c.createdAt);
            return data.toDateString() === hoje.toDateString();
        });

        const totalHoje = fechadasHoje.reduce((acc, c) => {
            if (typeof c.total === 'number') return acc + c.total;
            if (Array.isArray(c.itens)) {
                return acc + c.itens.reduce((sum, item) => sum + (Number(item.quantidade) * Number(item.valor)), 0);
            }
            return acc;
        }, 0);

        const totalEl = document.getElementById('caixaTotalHoje');
        const qtdEl = document.getElementById('caixaComandasHoje');
        const resumoEl = document.getElementById('caixaResumo');

        if (totalEl) totalEl.textContent = `R$ ${totalHoje.toFixed(2)}`;
        if (qtdEl) qtdEl.textContent = String(fechadasHoje.length);
        if (resumoEl) resumoEl.textContent = `${fechadas.length} comandas fechadas no historico.`;

        const container = document.getElementById('listaCaixaMobile');
        ComandaModule.renderCards(container, fechadas.slice().reverse(), {
            actionLabel: 'Ver',
            allowDelete: false,
            emptyMessage: 'Nenhuma comanda fechada.'
        });

        ComandaModule.bindContainer(container, {
            onOpen: (id) => ComandaModule.openComanda(id),
            onPrint: ComandaModule.printComanda
        });
    } catch (error) {
        Toast.error('Erro ao carregar caixa: ' + error.message);
    }
}
