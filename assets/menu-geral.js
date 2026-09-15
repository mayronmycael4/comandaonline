(function (global) {
    'use strict';

    const DESKTOP_MENU_ITEMS = [
        { href: 'index.html', label: 'Dashboard', icon: '\uD83C\uDFE0', permission: 'dashboard' },
        { href: 'funcionarios.html', label: 'Funcion\u00E1rios', icon: '\uD83D\uDC65', permission: 'funcionarios' },
        { href: 'comandas.html', label: 'Comandas', icon: '\uD83D\uDCCB', permission: 'comandas' },
        { href: 'mesas-mapa.html', label: 'Mesas', icon: '\uD83D\uDDFA', permission: 'mesas' },
        { href: 'cozinha.html', label: 'Cozinha', icon: '\uD83C\uDF73', permission: 'cozinha' },
        { href: 'produtos.html', label: 'Produtos', icon: '\uD83C\uDF54', permission: 'produtos' },
        { href: 'estoque.html', label: 'Estoque', icon: '\uD83D\uDCE6', permission: 'estoque' },
        { href: 'compras.html', label: 'Compras', icon: '🧾', permission: 'compras' },
        { href: 'clientes.html', label: 'Clientes', icon: '\uD83D\uDC64', permission: 'clientes' },
        { href: 'relatorios.html', label: 'Relat\u00F3rios', icon: '\uD83D\uDCC8', permission: 'relatorios' },
        { href: 'download.html', label: 'Backup', icon: '\uD83D\uDCBE', permission: 'backup' },
        { href: 'ajuda.html', label: 'Ajuda', icon: '\u2753', permission: null },
        { href: 'perfil.html', label: 'Perfil', icon: '\uD83D\uDC64', permission: null }
    ];

    const MOBILE_MENU_ITEMS = [
        { id: 'nav-home', icon: '⌂', label: 'Início', page: 'index-mobile.html', permission: 'dashboard' },
        { id: 'nav-new', icon: '+', label: 'Nova', page: 'nova-comanda-mobile.html', permission: 'comandas' },
        { id: 'nav-pedidos', icon: '☰', label: 'Pedidos', page: 'pedidos-mobile.html', permission: 'comandas' },
        { id: 'nav-cozinha', icon: '\uD83C\uDF73', label: 'Cozinha', page: 'cozinha-mobile.html', permission: 'cozinha' },
        { id: 'nav-profile', icon: '☺', label: 'Perfil', page: 'perfil-mobile.html', permission: null }
    ];

    const PAGE_PERMISSIONS = {
        'index.html': 'dashboard',
        'comandas.html': 'comandas',
        'comanda.html': 'comandas',
        'mesas-mapa.html': 'mesas',
        'cozinha.html': 'cozinha',
        'produtos.html': 'produtos',
        'estoque.html': 'estoque',
        'compras.html': 'compras',
        'relatorios.html': 'relatorios',
        'monitoramento.html': 'SISTEMA_VER_LOGS',
        'download.html': 'backup',
        'funcionarios.html': 'funcionarios',
        'clientes.html': 'clientes',
        'ajuda.html': null,
        'perfil.html': null,
        'index-mobile.html': 'dashboard',
        'nova-comanda-mobile.html': 'comandas',
        'pedidos-mobile.html': 'comandas',
        'cozinha-mobile.html': 'cozinha',
        'produtos-mobile.html': 'produtos',
        'estoque-mobile.html': 'estoque',
        'relatorios-mobile.html': 'relatorios',
        'funcionarios-mobile.html': 'funcionarios',
        'caixa-mobile.html': 'relatorios',
        'perfil-mobile.html': null
    };

    const ACTIVE_ALIAS = {
        'comanda.html': 'comandas.html'
    };

    function getCurrentPage() {
        const page = (window.location.pathname.split('/').pop() || '').toLowerCase();
        return page || 'index.html';
    }

    function getDesktopActivePage() {
        const current = getCurrentPage();
        return ACTIVE_ALIAS[current] || current;
    }

    function getRequiredPermission(pageName) {
        return PAGE_PERMISSIONS[pageName] || null;
    }

    function canAccess(item, session) {
        if (!session) return false;
        if (session.isAdmin) return true;
        if (!item.permission) return true;
        return typeof Storage !== 'undefined' && typeof Storage.hasPermission === 'function'
            ? Storage.hasPermission(item.permission)
            : false;
    }

    function getDesktopItems(session) {
        return DESKTOP_MENU_ITEMS.filter(item => canAccess(item, session));
    }

    function renderDesktopMenu() {
        const session = typeof Storage !== 'undefined' ? Storage.getSession() : null;
        const navs = document.querySelectorAll('nav.desktop-menu');
        if (!navs.length) return;

        const active = getDesktopActivePage();
        const visibleItems = getDesktopItems(session);

        navs.forEach(nav => {
            nav.innerHTML = visibleItems.map(item => {
                const isActive = item.href === active ? ' class="active menu-geral-item"' : ' class="menu-geral-item"';
                return `
                    <a href="${item.href}"${isActive} data-menu-item="${item.href}" title="${item.label}">
                        <span class="menu-geral-icon" aria-hidden="true">${item.icon}</span>
                        <span class="menu-geral-label">${item.label}</span>
                    </a>
                `;
            }).join('');
        });
    }

    function detectMobileCurrentId() {
        const path = window.location.pathname.toLowerCase();
        if (path.includes('perfil-mobile')) return 'nav-profile';
        if (path.includes('nova-comanda-mobile')) return 'nav-new';
        if (path.includes('pedidos-mobile') || path.includes('comandas')) return 'nav-pedidos';
        if (path.includes('cozinha-mobile')) return 'nav-cozinha';
        if (path.includes('comanda.html') && !path.includes('comandas')) return 'nav-pedidos';
        return 'nav-home';
    }

    function renderMobileBottomNav() {
        const existing = document.querySelector('.mobile-navbar');
        if (existing) existing.remove();

        const pageName = getCurrentPage();
        const isDedicatedMobilePage = pageName.includes('-mobile.html') || pageName === 'index-mobile.html';
        if (window.innerWidth > 768 && !isDedicatedMobilePage) return;

        const currentId = detectMobileCurrentId();
        const navbar = document.createElement('nav');
        navbar.className = 'mobile-navbar';

        const session = typeof Storage !== 'undefined' ? Storage.getSession() : null;
        const visibleItems = MOBILE_MENU_ITEMS.filter(item => canAccess({ permission: item.permission }, session));

        visibleItems.forEach(item => {
            const link = document.createElement('a');
            link.id = item.id;
            link.href = item.page;
            link.className = item.id === currentId ? 'active' : '';
            link.innerHTML = `
                <span class="mobile-navbar-icon">${item.icon}</span>
                <span class="mobile-navbar-label">${item.label}</span>
            `;
            navbar.appendChild(link);
        });

        document.body.appendChild(navbar);
    }

    function ensureHeaderLogo() {
        const header = document.querySelector('header');
        if (!header) return;
        header.classList.add('menu-geral-header');

        const title = header.querySelector('h1, h2');
        if (title && !title.querySelector('.brand-block')) {
            title.textContent = 'Menu';
            title.classList.add('menu-geral-title');
        }

        if (header.querySelector('.brand-block')) return;

        const desktopMenu = header.querySelector('nav.desktop-menu');
        if (!desktopMenu) return;

        const brand = document.createElement('div');
        brand.className = 'brand-block menu-geral-brand';
        brand.innerHTML = `
            <div class="brand-logo-wrap">
                <div class="brand-logo brand-logo-iniciais">CO</div>
            </div>
            <div class="brand-text-wrap">
                <span class="brand-title">Comanda Online</span>
                <span class="brand-subtitle">Menu do Sistema</span>
            </div>
        `;

        header.insertBefore(brand, desktopMenu);
    }

    global.MenuGeral = {
        DESKTOP_MENU_ITEMS,
        MOBILE_MENU_ITEMS,
        PAGE_PERMISSIONS,
        getCurrentPage,
        getDesktopActivePage,
        getRequiredPermission,
        getDesktopItems,
        renderDesktopMenu,
        renderMobileBottomNav,
        ensureHeaderLogo
    };
})(window);
