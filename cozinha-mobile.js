/* cozinha-mobile.js — inicializa a tela mobile da cozinha */
document.addEventListener('DOMContentLoaded', () => {
    if (!Storage.requireAuth()) return;
    if (!Storage.hasPermission('cozinha')) {
        window.location.href = 'index-mobile.html';
        return;
    }

    const btnLogout = document.getElementById('btnLogoutCozinha');
    if (btnLogout) {
        btnLogout.addEventListener('click', () => {
            Storage.clearSession();
            window.location.href = 'login.html';
        });
    }

    Storage.getEmpresa().then(empresa => {
        const el = document.getElementById('nomeEmpresaHeaderCozinha');
        if (el && empresa && empresa.nome) el.textContent = empresa.nome;
    }).catch(() => {});

    const ensureMobileNavVisible = () => {
        if (window.MenuGeral && typeof window.MenuGeral.renderMobileBottomNav === 'function') {
            window.MenuGeral.renderMobileBottomNav();
        }
    };

    ensureMobileNavVisible();
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) ensureMobileNavVisible();
    });

    CozinhaModule.startPolling({
        listContainer : document.getElementById('cozinhaListaMobile'),
        contadorEl    : document.getElementById('cozinhaContadorPendentes'),
        timestampEl   : document.getElementById('cozinhaUltimaAtualizacao'),
        somBtn        : document.getElementById('btnSomCozinha'),
        toolbarEl     : document.getElementById('cozinhaToolbar'),
        onError       : (err) => console.error('Cozinha poll error:', err)
    });

    // Limpar painel mobile
    const btnLimparM = document.getElementById('btnLimparCozinhaMobile');
    if (btnLimparM) {
        btnLimparM.addEventListener('click', () => {
            if (confirm('Ocultar pedidos concluídos/cancelados do painel?')) {
                CozinhaModule.limparPainel(null);
                Toast.success('Painel limpo!');
            }
        });
    }

    const btnLote = document.getElementById('btnProntoLoteMobile');
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
    const btnFs = document.getElementById('btnFullscreenMobile');
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
        document.addEventListener('fullscreenchange', () => {
            syncFsState();
            if (!document.fullscreenElement) {
                ensureMobileNavVisible();
            }
        });
        syncFsState();
    }
});
