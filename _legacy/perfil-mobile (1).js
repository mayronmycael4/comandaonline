const MOBILE_THEME_KEY = 'espetaria_theme';
const MAX_AUDIO_BYTES = 2 * 1024 * 1024;

let _audioDataUrlMobile = '';
let _audioNomeMobile = '';

document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;

    await carregarEmpresa();
    carregarSessao();
    renderAtalhosGestao();
    configurarTema();
    configurarSomMobile();

    const logout = document.getElementById('btnLogoutPerfilMobile');
    if (logout) {
        logout.addEventListener('click', () => {
            Storage.clearSession();
            window.location.href = 'login.html';
        });
    }
});

async function carregarEmpresa() {
    try {
        const empresa = await Storage.getEmpresa();
        const titulo = document.getElementById('nomeEmpresaHeaderPerfil');
        if (titulo && empresa && empresa.nome) titulo.textContent = empresa.nome;
    } catch (error) {
        console.error(error);
    }
}

function renderAtalhosGestao() {
    const secao = document.getElementById('secaoFerramentasGestao');
    const grid = document.getElementById('atalhosGestaoMobile');
    if (!secao || !grid) return;

    const links = [
        { permission: 'produtos', href: 'produtos-mobile.html', label: 'Produtos', desc: 'Cadastrar e editar produtos' },
        { permission: 'estoque', href: 'estoque-mobile.html', label: 'Estoque', desc: 'Controle e lista de compras' },
        { permission: 'relatorios', href: 'relatorios-mobile.html', label: 'Relatórios', desc: 'Financeiro e desempenho' },
        { permission: 'funcionarios', href: 'funcionarios-mobile.html', label: 'Funcionários', desc: 'Usuários e permissões' }
    ].filter((item) => Storage.hasPermission(item.permission));

    if (!links.length) return;

    secao.style.display = 'block';
    grid.innerHTML = links.map((item) => `
        <a class="admin-shortcut-card" href="${item.href}">
            <strong>${item.label}</strong>
            <span>${item.desc}</span>
        </a>
    `).join('');
}

function carregarSessao() {
    const session = Storage.getSession();
    if (!session) return;

    const nome = document.getElementById('perfilNomeMobile');
    const login = document.getElementById('perfilLoginMobile');
    const funcao = document.getElementById('perfilFuncaoMobile');

    if (nome) nome.textContent = session.nome || '-';
    if (login) login.textContent = session.login || '-';
    if (funcao) funcao.textContent = session.isAdmin ? 'Administrador' : 'Atendente';
}

function configurarTema() {
    const seletor = document.getElementById('seletorTemaMobile');
    if (!seletor) return;

    const salvo = localStorage.getItem(MOBILE_THEME_KEY);
    seletor.value = salvo || 'system';

    seletor.addEventListener('change', () => {
        aplicarTema(seletor.value);
        Toast.success('Tema atualizado!');
    });
}

function aplicarTema(valor) {
    if (valor === 'system') {
        localStorage.removeItem(MOBILE_THEME_KEY);
        const systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.setAttribute('data-theme', systemDark ? 'dark' : 'light');
        return;
    }

    localStorage.setItem(MOBILE_THEME_KEY, valor);
    document.documentElement.setAttribute('data-theme', valor);
}

function configurarSomMobile() {
    const check = document.getElementById('perfilSomAtivoMobile');
    const inputArquivo = document.getElementById('perfilAudioMobile');
    const textoAtual = document.getElementById('perfilAudioAtualMobile');
    const btnSalvar = document.getElementById('btnSalvarSomPerfilMobile');
    const btnTestar = document.getElementById('btnTestarSomPerfilMobile');
    const btnRemover = document.getElementById('btnRemoverSomPerfilMobile');

    if (!check || !inputArquivo || !textoAtual || !btnSalvar || !btnTestar || !btnRemover) return;

    const cfg = Storage.getCozinhaSoundConfig();
    check.checked = cfg.enabled !== false;
    _audioDataUrlMobile = cfg.audioDataUrl || '';
    _audioNomeMobile = cfg.audioName || '';
    atualizarLabelSomMobile();

    inputArquivo.addEventListener('change', () => {
        const arquivo = inputArquivo.files && inputArquivo.files[0] ? inputArquivo.files[0] : null;
        if (!arquivo) return;

        if (!String(arquivo.type || '').startsWith('audio/')) {
            Toast.error('Selecione um arquivo de áudio válido.');
            inputArquivo.value = '';
            return;
        }

        if (arquivo.size > MAX_AUDIO_BYTES) {
            Toast.error('Arquivo muito grande. Limite de 2MB.');
            inputArquivo.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = () => {
            _audioDataUrlMobile = String(reader.result || '');
            _audioNomeMobile = arquivo.name || 'audio-personalizado';
            atualizarLabelSomMobile();
            Toast.info('Áudio carregado. Toque em "Salvar Som" para aplicar.');
        };
        reader.onerror = () => {
            Toast.error('Falha ao ler o arquivo de áudio.');
        };
        reader.readAsDataURL(arquivo);
    });

    btnSalvar.addEventListener('click', () => {
        Storage.setCozinhaSoundConfig({
            enabled: !!check.checked,
            audioDataUrl: _audioDataUrlMobile,
            audioName: _audioNomeMobile
        });
        Toast.success('Preferências de som salvas!');
    });

    btnTestar.addEventListener('click', () => {
        testarSomMobile(check.checked, _audioDataUrlMobile);
    });

    btnRemover.addEventListener('click', () => {
        _audioDataUrlMobile = '';
        _audioNomeMobile = '';
        inputArquivo.value = '';
        atualizarLabelSomMobile();
        Storage.setCozinhaSoundConfig({
            enabled: !!check.checked,
            audioDataUrl: '',
            audioName: ''
        });
        Toast.success('Áudio personalizado removido.');
    });

    check.addEventListener('change', () => {
        Storage.setCozinhaSoundConfig({
            enabled: !!check.checked,
            audioDataUrl: _audioDataUrlMobile,
            audioName: _audioNomeMobile
        });
    });
}

function atualizarLabelSomMobile() {
    const textoAtual = document.getElementById('perfilAudioAtualMobile');
    if (!textoAtual) return;
    textoAtual.textContent = _audioNomeMobile
        ? `Áudio atual: ${_audioNomeMobile}`
        : 'Usando som padrão do sistema.';
}

function testarSomMobile(ativo, audioDataUrl) {
    if (!ativo) {
        Toast.warning('Ative o som para testar.');
        return;
    }

    if (audioDataUrl) {
        const audio = new Audio(audioDataUrl);
        audio.currentTime = 0;
        audio.play().catch(() => {
            Toast.warning('O navegador bloqueou o autoplay. Toque na tela e tente novamente.');
        });
        return;
    }

    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(1047, ctx.currentTime);
        gain.gain.setValueAtTime(0.001, ctx.currentTime);
        gain.gain.linearRampToValueAtTime(0.2, ctx.currentTime + 0.03);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.65);
    } catch (_e) {
        Toast.error('Não foi possível reproduzir o som neste navegador.');
    }
}
