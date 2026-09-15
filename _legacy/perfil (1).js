const DESKTOP_THEME_KEY = 'espetaria_theme';
const MAX_AUDIO_BYTES = 2 * 1024 * 1024;

let _audioDataUrlDesktop = '';
let _audioNomeDesktop = '';

document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;

    carregarSessaoDesktop();
    renderAtalhosGestaoDesktop();
    configurarTemaDesktop();
    configurarSomDesktop();

    const btnLogout = document.getElementById('btnLogoutPerfilDesktop');
    if (btnLogout) {
        btnLogout.addEventListener('click', () => {
            Storage.clearSession();
            window.location.href = 'login.html';
        });
    }
});

function carregarSessaoDesktop() {
    const session = Storage.getSession();
    if (!session) return;

    const nome = document.getElementById('perfilNomeDesktop');
    const login = document.getElementById('perfilLoginDesktop');
    const funcao = document.getElementById('perfilFuncaoDesktop');

    if (nome) nome.textContent = session.nome || '-';
    if (login) login.textContent = session.login || '-';
    if (funcao) funcao.textContent = session.isAdmin ? 'Administrador' : 'Atendente';
}

function configurarTemaDesktop() {
    const seletor = document.getElementById('seletorTemaDesktop');
    if (!seletor) return;

    const salvo = localStorage.getItem(DESKTOP_THEME_KEY);
    seletor.value = salvo || 'system';

    seletor.addEventListener('change', () => {
        aplicarTema(seletor.value);
        Toast.success('Tema atualizado!');
    });
}

function aplicarTema(valor) {
    if (valor === 'system') {
        localStorage.removeItem(DESKTOP_THEME_KEY);
        const systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.setAttribute('data-theme', systemDark ? 'dark' : 'light');
        return;
    }

    localStorage.setItem(DESKTOP_THEME_KEY, valor);
    document.documentElement.setAttribute('data-theme', valor);
}

function renderAtalhosGestaoDesktop() {
    const secao = document.getElementById('secaoFerramentasGestaoDesktop');
    const grid = document.getElementById('atalhosGestaoDesktop');
    if (!secao || !grid) return;

    const links = [
        { permission: 'produtos', href: 'produtos.html', label: 'Produtos', desc: 'Cadastrar e editar produtos' },
        { permission: 'estoque', href: 'estoque.html', label: 'Estoque', desc: 'Controle e lista de compras' },
        { permission: 'relatorios', href: 'relatorios.html', label: 'Relatórios', desc: 'Financeiro e desempenho' },
        { permission: 'funcionarios', href: 'funcionarios.html', label: 'Funcionários', desc: 'Usuários e permissões' }
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

function configurarSomDesktop() {
    const check = document.getElementById('perfilSomAtivoDesktop');
    const inputArquivo = document.getElementById('perfilAudioDesktop');
    const textoAtual = document.getElementById('perfilAudioAtualDesktop');
    const btnSalvar = document.getElementById('btnSalvarSomPerfilDesktop');
    const btnTestar = document.getElementById('btnTestarSomPerfilDesktop');
    const btnRemover = document.getElementById('btnRemoverSomPerfilDesktop');

    if (!check || !inputArquivo || !textoAtual || !btnSalvar || !btnTestar || !btnRemover) return;

    const cfg = Storage.getCozinhaSoundConfig();
    check.checked = cfg.enabled !== false;
    _audioDataUrlDesktop = cfg.audioDataUrl || '';
    _audioNomeDesktop = cfg.audioName || '';
    atualizarLabelSomDesktop();

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
            _audioDataUrlDesktop = String(reader.result || '');
            _audioNomeDesktop = arquivo.name || 'audio-personalizado';
            atualizarLabelSomDesktop();
            Toast.info('Áudio carregado. Clique em "Salvar Preferências de Som" para aplicar.');
        };
        reader.onerror = () => {
            Toast.error('Falha ao ler o arquivo de áudio.');
        };
        reader.readAsDataURL(arquivo);
    });

    btnSalvar.addEventListener('click', () => {
        Storage.setCozinhaSoundConfig({
            enabled: !!check.checked,
            audioDataUrl: _audioDataUrlDesktop,
            audioName: _audioNomeDesktop
        });
        Toast.success('Preferências de som salvas com sucesso!');
    });

    btnTestar.addEventListener('click', () => {
        testarSomDesktop(check.checked, _audioDataUrlDesktop);
    });

    btnRemover.addEventListener('click', () => {
        _audioDataUrlDesktop = '';
        _audioNomeDesktop = '';
        inputArquivo.value = '';
        atualizarLabelSomDesktop();
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
            audioDataUrl: _audioDataUrlDesktop,
            audioName: _audioNomeDesktop
        });
    });
}

function atualizarLabelSomDesktop() {
    const textoAtual = document.getElementById('perfilAudioAtualDesktop');
    if (!textoAtual) return;
    textoAtual.textContent = _audioNomeDesktop
        ? `Áudio atual: ${_audioNomeDesktop}`
        : 'Usando som padrão do sistema.';
}

function testarSomDesktop(ativo, audioDataUrl) {
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
