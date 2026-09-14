const THEME_KEY = 'espetaria_theme';

// Cache da empresa (nome/logo/cores) buscada da propria instancia (isolada por tenant).
let _brandingEmpresaPromise = null;

function carregarEmpresaBranding() {
    if (_brandingEmpresaPromise) return _brandingEmpresaPromise;

    _brandingEmpresaPromise = fetch('empresa.php')
        .then((r) => (r.ok ? r.json() : null))
        .catch(() => null);

    return _brandingEmpresaPromise;
}

function calcularIniciais(nome) {
    const partes = String(nome || '').trim().split(/\s+/).filter(Boolean);
    if (!partes.length) return 'CO';
    if (partes.length === 1) return partes[0].slice(0, 2).toUpperCase();
    return (partes[0][0] + partes[partes.length - 1][0]).toUpperCase();
}

function aplicarCoresDaEmpresa(empresa) {
    if (!empresa) return;
    const root = document.documentElement;
    if (empresa.cor_primaria) {
        root.style.setProperty('--c-red', empresa.cor_primaria);
        root.style.setProperty('--c-red-dark', empresa.cor_primaria);
    }
    if (empresa.cor_secundaria) {
        root.style.setProperty('--c-orange', empresa.cor_secundaria);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    applyThemeOnLoad();
    injectThemeToggle();
    injectHeaderBranding();
    injectLoginBranding();
    hydrateExistingBrandLogos();
    injectVoltarPainelAdmin();

    carregarEmpresaBranding().then((empresa) => {
        aplicarCoresDaEmpresa(empresa);
        atualizarBrandingComEmpresa(empresa);
    });
});

// Exibe um atalho flutuante para voltar ao painel administrativo quando o
// acesso veio via SSO do painel (login-init.js/sso_login.php gravam essa chave).
function injectVoltarPainelAdmin() {
    const adminUrl = localStorage.getItem('comanda_admin_panel_url');
    if (!adminUrl || document.querySelector('.voltar-painel-admin-btn')) return;

    const link = document.createElement('a');
    link.className = 'voltar-painel-admin-btn';
    link.href = adminUrl;
    link.textContent = '← Painel Administrativo';
    link.title = 'Voltar para o painel administrativo';
    link.addEventListener('click', () => localStorage.removeItem('comanda_admin_panel_url'));
    document.body.appendChild(link);
}


function applyThemeOnLoad() {
    const savedTheme = localStorage.getItem(THEME_KEY);
    const systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const theme = savedTheme || (systemDark ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', theme);
}

function injectThemeToggle() {
    const path = window.location.pathname.split('/').pop().toLowerCase();
    const isDedicatedMobilePage = path.includes('-mobile.html') || path === 'index-mobile.html';
    if (isDedicatedMobilePage) return;

    if (document.querySelector('.theme-toggle-btn')) return;

    const button = document.createElement('button');
    button.className = 'theme-toggle-btn';
    button.type = 'button';
    button.setAttribute('aria-label', 'Alternar tema');

    const setIcon = () => {
        const theme = document.documentElement.getAttribute('data-theme') || 'dark';
        button.textContent = theme === 'dark' ? '☀' : '🌙';
        button.title = theme === 'dark' ? 'Tema claro' : 'Tema escuro';
    };

    button.addEventListener('click', () => {
        const current = document.documentElement.getAttribute('data-theme') || 'dark';
        const next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem(THEME_KEY, next);
        setIcon();
    });

    setIcon();
    document.body.appendChild(button);
}

function createLogoElement(size = 48, nome = '') {
    const wrap = document.createElement('div');
    wrap.className = 'brand-logo-wrap';
    wrap.appendChild(createLogoImage(size, nome));
    return wrap;
}

function createLogoImage(size = 48, nome = '') {
    const iniciais = calcularIniciais(nome || 'Comanda Online');
    const fallback = document.createElement('div');
    fallback.className = 'brand-logo brand-logo-iniciais';
    fallback.textContent = iniciais;
    fallback.style.width = size + 'px';
    fallback.style.height = size + 'px';
    fallback.style.fontSize = Math.round(size * 0.38) + 'px';
    return fallback;
}

function trocarPorLogoReal(el, logoPath, size, nome) {
    if (!logoPath) return;

    const img = document.createElement('img');
    img.src = logoPath;
    img.alt = `Logo ${nome || 'do estabelecimento'}`;
    img.className = 'brand-logo';
    img.width = size;
    img.height = size;

    img.addEventListener('error', () => {
        // Mantem o fallback de iniciais caso o arquivo de logo nao exista.
    });
    img.addEventListener('load', () => {
        el.replaceWith(img);
    });
}

function hydrateExistingBrandLogos() {
    document.querySelectorAll('.brand-logo-wrap').forEach((slot) => {
        if (slot.querySelector('img, .brand-logo-iniciais')) return;

        const size = Math.max(slot.clientWidth || 0, slot.clientHeight || 0) || 48;
        const img = createLogoImage(size);
        slot.appendChild(img);
    });
}

function injectHeaderBranding() {
    const header = document.querySelector('header');
    if (!header || header.querySelector('.brand-block')) return;

    const title = header.querySelector('h1, h2');
    if (!title) return;

    const isMenuGeralHeader = !!header.querySelector('nav.desktop-menu');

    const brand = document.createElement('div');
    brand.className = 'brand-block';

    const logo = createLogoElement(52);

    const textWrap = document.createElement('div');
    textWrap.className = 'brand-text-wrap';

    const main = document.createElement('span');
    main.className = 'brand-title';
    main.textContent = 'Comanda Online';

    const sub = document.createElement('span');
    sub.className = 'brand-subtitle';
    sub.textContent = isMenuGeralHeader ? 'Menu do Sistema' : title.textContent.trim();

    textWrap.appendChild(main);
    textWrap.appendChild(sub);

    brand.appendChild(logo);
    brand.appendChild(textWrap);

    title.textContent = '';
    title.appendChild(brand);
}

function injectLoginBranding() {
    const loginBox = document.querySelector('.login-box');
    if (!loginBox || loginBox.querySelector('.login-brand')) return;

    const firstTitle = loginBox.querySelector('h1');
    if (!firstTitle) return;

    const brand = document.createElement('div');
    brand.className = 'login-brand';

    const logo = createLogoElement(86);
    const title = document.createElement('h2');
    title.className = 'login-brand-title';
    title.textContent = 'Sistema de Gestao';

    brand.appendChild(logo);
    brand.appendChild(title);

    loginBox.insertBefore(brand, firstTitle);
}

/**
 * Atualiza nome/logo ja injetados assim que os dados reais da empresa chegam da API
 * (a injecao inicial usa um placeholder generico para nao esperar a requisicao).
 */
function atualizarBrandingComEmpresa(empresa) {
    const nome = empresa?.nome ? String(empresa.nome).trim() : '';
    if (!nome && !empresa?.logo_path) return;

    document.querySelectorAll('.brand-title').forEach((el) => {
        el.textContent = nome || 'Comanda Online';
    });

    document.querySelectorAll('.login-brand-title').forEach((el) => {
        el.textContent = nome || 'Sistema de Gestao';
    });

    document.querySelectorAll('.brand-logo-wrap, .login-brand').forEach((wrap) => {
        const alvo = wrap.querySelector('.brand-logo-iniciais');
        if (!alvo) return;

        alvo.textContent = calcularIniciais(nome);

        if (empresa?.logo_path) {
            const size = alvo.clientWidth || 48;
            trocarPorLogoReal(alvo, empresa.logo_path, size, nome);
        }
    });
}

