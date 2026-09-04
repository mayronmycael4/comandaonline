(function () {
    const MOBILE_BREAKPOINT = 768;

    function isMobileClient() {
        const viewportMobile = window.matchMedia && window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT}px)`).matches;
        const uaMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent || '');
        return viewportMobile || uaMobile;
    }

    function getPostLoginDestination() {
        return isMobileClient() ? 'index-mobile.html' : 'index.html';
    }

    function redirectAfterLogin() {
        window.location.href = getPostLoginDestination();
    }

    function routeLegacyMobilePages() {
        if (!isMobileClient()) return;

        const path = window.location.pathname.split('/').pop().toLowerCase();
        const query = window.location.search || '';

        const map = {
            'index.html': 'index-mobile.html',
            'comandas.html': 'pedidos-mobile.html',
            'perfil.html': 'perfil-mobile.html',
            'produtos.html': 'produtos-mobile.html',
            'estoque.html': 'estoque-mobile.html',
            'relatorios.html': 'relatorios-mobile.html',
            'funcionarios.html': 'funcionarios-mobile.html'
        };

        if (path === 'comanda.html' && !query.includes('comandaId=')) {
            window.location.replace('nova-comanda-mobile.html');
            return;
        }

        if (map[path]) {
            window.location.replace(map[path]);
        }
    }

    window.MobileRouting = {
        isMobileClient,
        getPostLoginDestination,
        redirectAfterLogin,
        routeLegacyMobilePages
    };

    routeLegacyMobilePages();
})();
