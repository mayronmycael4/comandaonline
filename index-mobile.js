let todasComandasMobile = [];
let statusAtualMobile = 'aberta';
const COMANDAS_MOBILE_AUTO_REFRESH_MS = 8000;
let comandasMobileAutoRefreshTimer = null;
let formComandaMobileBusy = false;

function getComandasSyncInfoMobile() {
    let info = document.getElementById('comandasSyncInfoMobile');
    if (info) return info;

    const resumo = document.getElementById('resumoComandasMobile');
    if (!resumo || !resumo.parentElement) return null;

    info = document.createElement('small');
    info.id = 'comandasSyncInfoMobile';
    info.style.display = 'block';
    info.style.marginTop = '4px';
    info.style.opacity = '0.9';
    info.style.fontSize = '12px';
    info.style.color = '#555';
    resumo.parentElement.appendChild(info);
    return info;
}

function formatSyncTime(ts) {
    try {
        return new Date(ts).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    } catch (_e) {
        return '--:--:--';
    }
}

function setComandasSyncInfoMobile(source = 'cache', status = 'info') {
    const info = getComandasSyncInfoMobile();
    if (!info) return;

    const hora = formatSyncTime(Date.now());
    const origem = source === 'server' ? 'servidor' : 'cache local';
    info.textContent = `Ultima atualizacao: ${hora} (${origem})`;
    info.style.color = status === 'warn' ? '#b45309' : (status === 'ok' ? '#1b7f3b' : '#555');
}

function reaplicarFuncionarioLogadoMobile(selectId) {
    const select = document.getElementById(selectId);
    const session = Storage.getSession();
    if (!select || !session || !session.funcionarioId) return;
    select.value = String(session.funcionarioId);
}

document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;

    const btnLogout = document.getElementById('btnLogoutMobileHeader');
    if (btnLogout) {
        btnLogout.addEventListener('click', () => {
            Storage.clearSession();
            window.location.href = 'login.html';
        });
    }

    bindMobileComandasContainer();
    bindMobileFilters();
    bindMobileForm();

    await carregarEmpresaMobile();
    bindFuncionarioSyncStatusMobile();
    await carregarFuncionariosMobile();
    renderizarComandasMobileDoCache();
    sincronizarComandasMobileEmSegundoPlano();
    iniciarAutoRefreshComandasMobile();
});

function getFuncionarioHintMobile() {
    let hint = document.getElementById('funcionariosSyncHintMobile');
    if (hint) return hint;
    const select = document.getElementById('funcionarioResponsavelMobile');
    if (!select || !select.parentElement) return null;
    hint = document.createElement('small');
    hint.id = 'funcionariosSyncHintMobile';
    hint.style.display = 'block';
    hint.style.marginTop = '4px';
    hint.style.opacity = '0.85';
    hint.style.fontSize = '12px';
    select.parentElement.appendChild(hint);
    return hint;
}

function setFuncionarioHintMobile(msg, level = 'info') {
    const hint = getFuncionarioHintMobile();
    if (!hint) return;
    hint.textContent = msg || '';
    hint.style.color = level === 'warn' ? '#c0392b' : (level === 'ok' ? '#1b7f3b' : '#555');
}

function bindFuncionarioSyncStatusMobile() {
    window.addEventListener('comanda-sync-status', (event) => {
        const detail = event && event.detail ? event.detail : {};
        const message = String(detail.message || '');
        if (!message) return;

        if (message.toLowerCase().includes('comanda')) {
            setComandasSyncInfoMobile(detail.source || 'cache', detail.level || 'info');
        }

        if (message.toLowerCase().includes('funcionario')) {
            setFuncionarioHintMobile(message, detail.level || 'info');
        }
    });
}

function bindMobileComandasContainer() {
    const container = document.getElementById('listaComandasMobile');
    if (!container) return;

    ComandaModule.bindContainer(container, {
        onClose: fecharComandaMobile,
        onDelete: excluirComandaMobile,
        onOpen: abrirComanda,
        onPrint: ComandaModule.printComanda,
        onReopen: reabrirComandaMobile
    });
}

function bindMobileFilters() {
    const busca = document.getElementById('buscaComandaMobile');
    if (busca) {
        busca.addEventListener('input', renderComandasMobile);
    }

    document.querySelectorAll('[data-mobile-status]').forEach(chip => {
        chip.addEventListener('click', () => {
            statusAtualMobile = chip.getAttribute('data-mobile-status') || 'todos';
            document.querySelectorAll('[data-mobile-status]').forEach(item => {
                item.classList.toggle('active', item === chip);
            });
            renderComandasMobile();
        });
    });
}

function bindMobileForm() {
    const form = document.getElementById('formComandaMobile');
    if (!form) return;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (formComandaMobileBusy) return;

        const numeroMesa = document.getElementById('numeroMesaMobile').value.trim();
        const selectFuncionario = document.getElementById('funcionarioResponsavelMobile');
        const funcionarioId = selectFuncionario ? selectFuncionario.value : '';

        if (selectFuncionario && selectFuncionario.disabled) {
            Toast.warning('Aguarde carregar os funcionários.');
            return;
        }

        if (!numeroMesa || !funcionarioId) {
            Toast.error('Preencha mesa e funcionário.');
            return;
        }

        try {
            formComandaMobileBusy = true;
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.dataset.originalText = submitBtn.textContent;
                submitBtn.textContent = 'Enviando...';
            }

            const comanda = await Storage.addComanda({
                numeroMesa,
                funcionarioId: Number(funcionarioId),
                cliente: { nome: '', contato: '', observacoes: '' },
                itens: []
            });

            form.reset();
            reaplicarFuncionarioLogadoMobile('funcionarioResponsavelMobile');
            await carregarComandasMobile();

            Toast.success('Comanda criada com sucesso!');
            window.location.href = `comanda.html?comandaId=${comanda.id}`;
        } catch (error) {
            Toast.error('Nao foi possivel abrir a comanda em tempo real: ' + error.message);
        } finally {
            formComandaMobileBusy = false;
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = submitBtn.dataset.originalText || 'Abrir Comanda';
            }
        }
    });
}

async function carregarEmpresaMobile() {
    try {
        const empresa = await Storage.getEmpresa();
        if (empresa) {
            const titulo = document.getElementById('nomeEmpresaHeaderMobile');
            if (titulo) titulo.textContent = empresa.nome;
        }
    } catch (error) {
        console.error('Erro ao carregar empresa no mobile:', error);
    }
}

async function carregarFuncionariosMobile() {
    const select = document.getElementById('funcionarioResponsavelMobile');
    if (!select) return;

    const cacheLocal = typeof Storage.getFuncionariosCache === 'function'
        ? Storage.getFuncionariosCache()
        : [];

    if (Array.isArray(cacheLocal) && cacheLocal.length > 0) {
        select.innerHTML = '<option value="">Selecione...</option>';
        cacheLocal.forEach((funcionario) => {
            select.innerHTML += `<option value="${funcionario.id}">${escapeHtml(funcionario.nome)}</option>`;
        });
        select.disabled = false;
        reaplicarFuncionarioLogadoMobile('funcionarioResponsavelMobile');
        setFuncionarioHintMobile('Funcionarios em cache prontos para uso.', 'ok');
    } else {
        select.disabled = true;
        select.innerHTML = '<option value="">Carregando funcionários...</option>';
        setFuncionarioHintMobile('Carregando funcionarios...');
    }

    try {
        const funcionarios = await Storage.getFuncionarios();
        const session = Storage.getSession();
        const funcionarioLogadoId = session && session.funcionarioId ? String(session.funcionarioId) : '';
        select.innerHTML = '<option value="">Selecione...</option>';
        funcionarios.forEach(funcionario => {
            select.innerHTML += `<option value="${funcionario.id}">${escapeHtml(funcionario.nome)}</option>`;
        });

        select.disabled = funcionarios.length === 0;

        if (funcionarioLogadoId && funcionarios.some((f) => String(f.id) === funcionarioLogadoId)) {
            select.value = funcionarioLogadoId;
        }

        if (funcionarios.length === 0) {
            Toast.warning('Nenhum funcionário ativo disponível para abrir comanda.');
            setFuncionarioHintMobile('Sem funcionarios disponiveis no momento.', 'warn');
        } else {
            setFuncionarioHintMobile('Funcionarios prontos para uso.', 'ok');
        }
    } catch (error) {
        select.disabled = true;
        select.innerHTML = '<option value="">Falha ao carregar funcionários</option>';
        Toast.error('Não foi possível carregar funcionários.');
        setFuncionarioHintMobile('Falha ao carregar do servidor. Tente novamente.', 'warn');
        console.error('Erro ao carregar funcionários no mobile:', error);
    }
}

function renderizarComandasMobileDoCache() {
    try {
        todasComandasMobile = Storage.getComandasSnapshot();
        Storage.notificarPedidosProntos(todasComandasMobile);
        atualizarStatsMobile();
        renderComandasMobile();
        setComandasSyncInfoMobile('cache', 'info');

        if (todasComandasMobile.length > 0) {
            Storage.notifySyncStatus('Comandas exibidas com dados ja carregados.', 'info', { source: 'cache' });
        } else {
            Storage.notifySyncStatus('Carregando comandas em segundo plano...', 'info', { source: 'cache' });
        }
    } catch (error) {
        console.error('Erro ao renderizar cache de comandas no mobile:', error);
    }
}

async function sincronizarComandasMobileEmSegundoPlano(options = {}) {
    try {
        const atualizadas = await Storage.syncComandasInBackground(null, options);
        todasComandasMobile = Array.isArray(atualizadas) ? atualizadas : [];
        Storage.notificarPedidosProntos(todasComandasMobile);
        atualizarStatsMobile();
        renderComandasMobile();
        setComandasSyncInfoMobile('server', 'ok');
    } catch (error) {
        console.error('Erro ao sincronizar comandas no mobile:', error);
        setComandasSyncInfoMobile('cache', 'warn');
    }
}

function iniciarAutoRefreshComandasMobile() {
    if (comandasMobileAutoRefreshTimer) {
        clearInterval(comandasMobileAutoRefreshTimer);
    }
    comandasMobileAutoRefreshTimer = setInterval(async () => {
        await sincronizarComandasMobileEmSegundoPlano({ silent: true });
    }, COMANDAS_MOBILE_AUTO_REFRESH_MS);
}

function atualizarStatsMobile() {
    const hoje = new Date();
    const abertas = todasComandasMobile.filter(c => c.status === 'aberta');
    const fechadasHoje = todasComandasMobile.filter(c => {
        if (c.status !== 'fechada') return false;
        const dataFechamento = c.fechamento ? new Date(c.fechamento.data) : new Date(c.createdAt);
        return dataFechamento.toDateString() === hoje.toDateString();
    });

    const elAbertas = document.getElementById('statComandasAbertasMobile');
    const elFechadas = document.getElementById('statComandasFechadasMobile');

    if (elAbertas) elAbertas.textContent = abertas.length;
    if (elFechadas) elFechadas.textContent = fechadasHoje.length;
}

function renderComandasMobile() {
    const busca = (document.getElementById('buscaComandaMobile').value || '').trim().toLowerCase();

    let filtradas = [...todasComandasMobile];

    if (statusAtualMobile !== 'todos') {
        filtradas = filtradas.filter(c => c.status === statusAtualMobile || (statusAtualMobile === 'fechada' && c.status === 'cancelada'));
    }

    if (busca) {
        filtradas = filtradas.filter(c => {
            const mesa = String(c.numeroMesa || '').toLowerCase();
            const cliente = String(c.clienteNome || (c.cliente && c.cliente.nome) || '').toLowerCase();
            const responsavel = String(c.funcionarioNome || '').toLowerCase();
            return mesa.includes(busca) || cliente.includes(busca) || responsavel.includes(busca);
        });
    }

    const container = document.getElementById('listaComandasMobile');
    ComandaModule.renderCards(container, filtradas, {
        actionLabel: 'Abrir',
        allowDelete: true,
        emptyMessage: 'Nenhuma comanda encontrada.'
    });

    const abertas = filtradas.filter(c => c.status === 'aberta').length;
    const fechadas = filtradas.filter(c => c.status === 'fechada').length;
    const canceladas = filtradas.filter(c => c.status === 'cancelada').length;
    const resumo = document.getElementById('resumoComandasMobile');
    if (resumo) {
        resumo.textContent = `${filtradas.length} comandas: ${abertas} abertas, ${fechadas} fechadas e ${canceladas} canceladas.`;
    }
}

function abrirComanda(id) {
    ComandaModule.openComanda(id);
}

async function excluirComandaMobile(id) {
    if (!confirm('Tem certeza que deseja cancelar esta comanda? A cozinha sera avisada.')) return;
    try {
        await Storage.deleteComanda(id);
        Toast.success('Comanda cancelada com sucesso!');
        renderizarComandasMobileDoCache();
        await sincronizarComandasMobileEmSegundoPlano();
    } catch (error) {
        Toast.error('Erro ao cancelar comanda: ' + error.message);
    }
}

async function fecharComandaMobile(id) {
    try {
        await Storage.fecharComanda(id);
        Toast.success('Comanda fechada com sucesso!');
        renderizarComandasMobileDoCache();
        await sincronizarComandasMobileEmSegundoPlano();
    } catch (error) {
        Toast.error('Erro ao fechar comanda: ' + error.message);
    }
}

async function reabrirComandaMobile(id) {
    try {
        await Storage.reabrirComanda(id);
        Toast.success('Comanda reaberta com sucesso!');
        renderizarComandasMobileDoCache();
        await sincronizarComandasMobileEmSegundoPlano();
    } catch (error) {
        Toast.error('Erro ao reabrir comanda: ' + error.message);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
