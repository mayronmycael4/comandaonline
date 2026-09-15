/* cozinha.js — inicializa a tela desktop da cozinha */
document.addEventListener('DOMContentLoaded', () => {
    if (!Storage.requireAuth()) return;

    CozinhaModule.startPolling({
        listContainer : document.getElementById('cozinhaListaDesktop'),
        contadorEl    : document.getElementById('cozinhaContadorDesktop'),
        timestampEl   : document.getElementById('cozinhaUltimaDesktop'),
        somBtn        : document.getElementById('btnSomDesktop'),
        toolbarEl     : document.querySelector('.cozinha-toolbar'),
        onError       : (err) => console.error('Cozinha poll error:', err)
    });

    // Limpar painel
    const btnLimpar = document.getElementById('btnLimparCozinha');
    if (btnLimpar) {
        btnLimpar.addEventListener('click', () => {
            if (confirm('Ocultar do painel todos os pedidos já concluídos/cancelados?\nPedidos com itens pendentes continuarão visíveis.')) {
                CozinhaModule.limparPainel(btnLimpar);
            }
        });
    }

    const btnLote = document.getElementById('btnProntoLoteDesktop');
    if (btnLote) {
        btnLote.addEventListener('click', async () => {
            btnLote.disabled = true;
            try {
                const res = await CozinhaModule.marcarPendentesVisiveisProntos();
                Toast.success((res && res.atualizados ? res.atualizados : 0) + ' itens atualizados para pronto.');
            } catch (e) {
                Toast.error('Não foi possível concluir o lote de itens.');
            } finally {
                btnLote.disabled = false;
            }
        });
    }

    // Fullscreen
    const btnFs = document.getElementById('btnFullscreenDesktop');
    if (btnFs) {
        const syncFsState = () => {
            const isFs = !!document.fullscreenElement;
            btnFs.textContent = isFs ? '✕' : '⛶';
            btnFs.title = isFs ? 'Sair da tela cheia' : 'Tela cheia';
        };

        btnFs.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else {
                document.exitFullscreen().catch(() => {});
            }
        });
        document.addEventListener('fullscreenchange', syncFsState);
        syncFsState();
    }
});
