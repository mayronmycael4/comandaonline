document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;

    try {
        await Storage.getEmpresa();
        const comandas = await Storage.getComandas();
        const hoje = Storage.companyDate();
        const abertas = comandas.filter((c) => c.status === 'aberta');
        const fechadasHoje = comandas.filter((c) => {
            if (c.status !== 'fechada') return false;
            const data = c.fechamento && c.fechamento.data ? c.fechamento.data : c.createdAt;
            return Storage.companyDate(data) === hoje;
        });
        const totalHoje = fechadasHoje.reduce((acc, c) => acc + Number(c.total || 0), 0);

        document.getElementById('pdvTotalHoje').textContent = `R$ ${totalHoje.toFixed(2)}`;
        document.getElementById('pdvAbertas').textContent = String(abertas.length);
        document.getElementById('pdvFechadas').textContent = String(fechadasHoje.length);

        const container = document.getElementById('pdvComandasAbertas');
        ComandaModule.renderCards(container, abertas, {
            actionLabel: 'Abrir',
            allowDelete: false,
            allowToggleStatus: true,
            emptyMessage: 'Nenhuma comanda aberta.'
        });
        ComandaModule.bindContainer(container, {
            onOpen: (id) => ComandaModule.openComanda(id),
            onPrint: ComandaModule.printComanda,
            onClose: (id) => ComandaModule.openComanda(id)
        });
    } catch (error) {
        Toast.error('Erro ao carregar PDV: ' + error.message);
    }
});
