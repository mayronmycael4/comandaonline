const MENU_THEME_KEY = 'espetaria_theme';

if (window.MenuGeral) {
    window.MenuGeral.renderDesktopMenu();
    window.MenuGeral.ensureHeaderLogo();
}

function initMenuAdmin() {
    const session = Storage.getSession();
    if (!session) return;

    if (!validatePagePermission()) return;

    if (window.MenuGeral) {
        window.MenuGeral.renderDesktopMenu();
        window.MenuGeral.ensureHeaderLogo();
    }

    const header = document.querySelector('header');
    if (header && !header.querySelector('.user-info')) {
        const userInfo = document.createElement('div');
        userInfo.className = 'user-info';
        userInfo.innerHTML = `
            <div class="profile-menu">
                <button class="profile-trigger" type="button">
                    <span class="profile-name">${session.nome}</span>
                    <span class="profile-icon">◉</span>
                </button>
                <div class="profile-dropdown">
                    <button type="button" class="profile-item js-theme-option" data-theme="dark">Tema escuro</button>
                    <button type="button" class="profile-item js-theme-option" data-theme="light">Tema claro</button>
                    <button type="button" class="profile-item js-theme-option" data-theme="system">Tema do sistema</button>
                    <button type="button" class="profile-item danger js-logout-btn">Sair</button>
                </div>
            </div>
        `;
        header.appendChild(userInfo);
    }

    criarMenuMobile(session);
    criarDicaMenuMobile();
    habilitarGestoMenuMobile();

    bindMenuActions();
    applyDesktopHeaderOffset();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMenuAdmin);
} else {
    initMenuAdmin();
}

window.addEventListener('resize', () => {
    applyDesktopHeaderOffset();
});

function getCurrentPageName() {
    if (window.MenuGeral) return window.MenuGeral.getCurrentPage();
    const page = window.location.pathname.split('/').pop();
    return page || 'index.html';
}

function applyDesktopHeaderOffset() {
    if (window.innerWidth <= 768) return;
    const header = document.querySelector('header.menu-geral-header, header');
    if (!header) return;
    const headerHeight = Math.ceil(header.getBoundingClientRect().height);
    document.body.style.paddingTop = `${headerHeight + 18}px`;
}

function resolveAllowedPath() {
    const session = Storage.getSession();
    if (!session) return 'login.html';

    const ordered = window.MenuGeral ? window.MenuGeral.DESKTOP_MENU_ITEMS : [];
    if (session.isAdmin) return (ordered[0] && ordered[0].href) || 'index.html';

    for (const item of ordered) {
        if (!item.permission || Storage.hasPermission(item.permission)) {
            return item.href;
        }
    }

    return 'login.html';
}

function validatePagePermission() {
    const currentPage = getCurrentPageName();
    const required = window.MenuGeral ? window.MenuGeral.getRequiredPermission(currentPage) : null;
    if (!required) return true;
    if (Storage.hasPermission(required)) return true;

    Toast.error('Você não tem permissão para acessar esta página.');
    window.location.href = resolveAllowedPath();
    return false;
}

function criarMenuMobile(session) {
    if (document.querySelector('.menu-toggle')) return;

    const header = document.querySelector('header');
    if (!header) return;

    const menuToggle = document.createElement('button');
    menuToggle.className = 'menu-toggle';
    menuToggle.type = 'button';
    menuToggle.setAttribute('aria-label', 'Abrir menu');
    menuToggle.setAttribute('aria-controls', 'mobileMenuLateral');
    menuToggle.setAttribute('aria-expanded', 'false');
    menuToggle.innerHTML = '<span class="menu-toggle-icon">☰</span><span class="menu-toggle-text">Menu</span>';
    menuToggle.addEventListener('click', abrirMenu);
    header.appendChild(menuToggle);

    const overlay = document.createElement('div');
    overlay.className = 'menu-overlay';
    overlay.addEventListener('click', fecharMenu);
    document.body.appendChild(overlay);

    const mobileMenu = document.createElement('nav');
    mobileMenu.className = 'mobile-menu';
    mobileMenu.id = 'mobileMenuLateral';

    const currentPage = getCurrentPageName();
    const links = window.MenuGeral
        ? window.MenuGeral.getDesktopItems(session)
        : [];

    let menuHTML = `
        <button class="menu-close js-menu-close" type="button">×</button>
        <div class="user-info-mobile">
            Bem-vindo,<br><strong>${session.nome}</strong>
            <div style="margin-top:10px; display:grid; gap:6px;">
                <button type="button" class="btn btn-small js-theme-option" data-theme="dark">Tema escuro</button>
                <button type="button" class="btn btn-small js-theme-option" data-theme="light">Tema claro</button>
                <button type="button" class="btn btn-small js-theme-option" data-theme="system">Tema do sistema</button>
            </div>
            <br><button class="btn btn-logout btn-small js-logout-btn" type="button" style="margin-top:10px;width:100%">Sair</button>
        </div>
    `;

    links.forEach(item => {
        const activeRef = window.MenuGeral ? window.MenuGeral.getDesktopActivePage() : currentPage;
        const isActive = activeRef === item.href ? 'active' : '';
        menuHTML += `<a href="${item.href}" class="${isActive}">${item.label}</a>`;
    });

    mobileMenu.innerHTML = menuHTML;
    document.body.appendChild(mobileMenu);
}

function habilitarGestoMenuMobile() {
    if (document.body.dataset.menuSwipeBound === '1') return;

    let startX = 0;
    let startY = 0;
    let tracking = false;

    document.addEventListener('touchstart', (event) => {
        if (!event.touches || event.touches.length !== 1) return;
        const touch = event.touches[0];
        startX = touch.clientX;
        startY = touch.clientY;
        tracking = true;
    }, { passive: true });

    document.addEventListener('touchend', (event) => {
        if (!tracking || !event.changedTouches || event.changedTouches.length !== 1) return;
        tracking = false;

        const touch = event.changedTouches[0];
        const deltaX = touch.clientX - startX;
        const deltaY = touch.clientY - startY;
        const horizontalEnough = Math.abs(deltaX) > 70 && Math.abs(deltaY) < 60;
        if (!horizontalEnough) return;

        const swipeDireitaParaEsquerda = deltaX < -70;
        const swipeEsquerdaParaDireitaNaBorda = startX < 60 && deltaX > 70;
        const menuAberto = document.querySelector('.mobile-menu')?.classList.contains('active');

        if (!menuAberto && (swipeDireitaParaEsquerda || swipeEsquerdaParaDireitaNaBorda)) {
            abrirMenu();
        }
    }, { passive: true });

    document.body.dataset.menuSwipeBound = '1';
}

function criarDicaMenuMobile() {
    if (document.querySelector('.mobile-menu-hint')) return;

    const header = document.querySelector('header');
    if (!header) return;

    const hint = document.createElement('div');
    hint.className = 'mobile-menu-hint';
    hint.innerHTML = '<strong>☰ Menu:</strong> toque no botao no topo para abrir o menu lateral';
    header.insertAdjacentElement('afterend', hint);
}

function bindMenuActions() {
    document.querySelectorAll('.profile-trigger').forEach(btn => {
        if (!btn.dataset.bound) {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const dropdown = btn.parentElement?.querySelector('.profile-dropdown');
                if (!dropdown) return;
                const isOpen = dropdown.classList.contains('active');
                document.querySelectorAll('.profile-dropdown.active').forEach(d => d.classList.remove('active'));
                if (!isOpen) dropdown.classList.add('active');
            });
            btn.dataset.bound = '1';
        }
    });

    document.querySelectorAll('.js-logout-btn').forEach(btn => {
        if (!btn.dataset.bound) {
            btn.addEventListener('click', logout);
            btn.dataset.bound = '1';
        }
    });

    document.querySelectorAll('.js-theme-option').forEach(btn => {
        if (!btn.dataset.bound) {
            btn.addEventListener('click', () => {
                const selected = btn.getAttribute('data-theme');
                applyThemeSelection(selected);
                document.querySelectorAll('.profile-dropdown.active').forEach(d => d.classList.remove('active'));
                Toast.success('Tema atualizado!');
            });
            btn.dataset.bound = '1';
        }
    });

    document.querySelectorAll('.js-menu-close').forEach(btn => {
        if (!btn.dataset.bound) {
            btn.addEventListener('click', fecharMenu);
            btn.dataset.bound = '1';
        }
    });
}

function applyThemeSelection(theme) {
    if (theme === 'system') {
        localStorage.removeItem(MENU_THEME_KEY);
        const systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.setAttribute('data-theme', systemDark ? 'dark' : 'light');
        return;
    }

    document.documentElement.setAttribute('data-theme', theme === 'light' ? 'light' : 'dark');
    localStorage.setItem(MENU_THEME_KEY, theme === 'light' ? 'light' : 'dark');
}

function abrirMenu() {
    document.querySelector('.mobile-menu')?.classList.add('active');
    document.querySelector('.menu-overlay')?.classList.add('active');
    const toggle = document.querySelector('.menu-toggle');
    if (toggle) toggle.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
}

function fecharMenu() {
    document.querySelector('.mobile-menu')?.classList.remove('active');
    document.querySelector('.menu-overlay')?.classList.remove('active');
    const toggle = document.querySelector('.menu-toggle');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
}

function logout() {
    Storage.clearSession();
    window.location.href = 'login.html';
}

document.addEventListener('click', (e) => {
    if (e.target.matches('.mobile-menu a')) {
        fecharMenu();
    }

    if (!e.target.closest('.profile-menu')) {
        document.querySelectorAll('.profile-dropdown.active').forEach(d => d.classList.remove('active'));
    }
});
