let currentSession = null;
let dashboardComandas = [];
const DASHBOARD_AUTO_REFRESH_MS = 10000;
let dashboardAutoRefreshTimer = null;
let formComandaBusy = false;

function getComandasSyncInfoDesktop() {
    let info = document.getElementById('comandasSyncInfoDesktop');
    if (info) return info;

    const lista = document.getElementById('listaComandasAbertas');
    if (!lista || !lista.parentElement) return null;

    info = document.createElement('small');
    info.id = 'comandasSyncInfoDesktop';
    info.style.display = 'block';
    info.style.marginTop = '8px';
    info.style.opacity = '0.9';
    info.style.fontSize = '12px';
    info.style.color = '#555';
    lista.parentElement.insertBefore(info, lista);
    return info;
}

function formatSyncTime(ts) {
    try {
        return new Date(ts).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    } catch (_e) {
        return '--:--:--';
    }
}

function setComandasSyncInfoDesktop(source = 'cache', status = 'info') {
    const info = getComandasSyncInfoDesktop();
    if (!info) return;

    const hora = formatSyncTime(Date.now());
    const origem = source === 'server' ? 'servidor' : 'cache local';
    info.textContent = `Ultima atualizacao: ${hora} (${origem})`;
    info.style.color = status === 'warn' ? '#b45309' : (status === 'ok' ? '#1b7f3b' : '#555');
}

function reaplicarFuncionarioLogado(selectId) {
    const select = document.getElementById(selectId);
    const session = Storage.getSession();
    if (!select || !session || !session.funcionarioId) return;
    select.value = String(session.funcionarioId);
}

document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;

    currentSession = Storage.getSession();
    
    try {
        const empresa = await Storage.getEmpresa();
        const nomeEmpresaHeader = document.getElementById('nomeEmpresaHeader');
        if (empresa && nomeEmpresaHeader) {
            nomeEmpresaHeader.textContent = empresa.nome;
        }
    } catch (e) {
        console.log('Erro ao carregar empresa:', e);
    }

    bindDashboardComandas();
    bindFuncionarioSyncStatusDesktop();
    showDashboardSkeletons();
    
    await carregarFuncionariosSelect();
    await atualizarStats();
    renderizarDashboardComandasDoCache();
    sincronizarDashboardComandasEmSegundoPlano();
    iniciarAutoRefreshDashboard();
    
    document.getElementById('formComanda').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (formComandaBusy) return;
        
        const numeroMesa = document.getElementById('numeroMesa').value.trim();
        const funcionarioId = document.getElementById('funcionarioResponsavel').value;
        
        if (numeroMesa && funcionarioId) {
            try {
                formComandaBusy = true;
                const submitBtn = e.target.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.dataset.originalText = submitBtn.textContent;
                    submitBtn.textContent = 'Enviando...';
                }

                const comanda = await Storage.addComanda({
                    numeroMesa,
                    funcionarioId: parseInt(funcionarioId),
                    cliente: { nome: '', contato: '', observacoes: '' },
                    itens: []
                });
                
                document.getElementById('formComanda').reset();
                reaplicarFuncionarioLogado('funcionarioResponsavel');
                await atualizarStats();
                renderizarDashboardComandasDoCache();
                await sincronizarDashboardComandasEmSegundoPlano();

                Toast.success('Comanda criada com sucesso!');
                
                setTimeout(() => {
                    window.location.href = `comanda.html?comandaId=${comanda.id}`;
                }, 1000);
            } catch (error) {
                Toast.error('Nao foi possivel abrir a comanda em tempo real: ' + error.message);
            } finally {
                formComandaBusy = false;
                const submitBtn = e.target.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitBtn.dataset.originalText || 'Abrir Comanda';
                }
            }
        }
    });
});

function getFuncionarioHintDesktop() {
    let hint = document.getElementById('funcionariosSyncHintDesktop');
    if (hint) return hint;
    const select = document.getElementById('funcionarioResponsavel');
    if (!select || !select.parentElement) return null;
    hint = document.createElement('small');
    hint.id = 'funcionariosSyncHintDesktop';
    hint.style.display = 'block';
    hint.style.marginTop = '4px';
    hint.style.opacity = '0.85';
    hint.style.fontSize = '12px';
    select.parentElement.appendChild(hint);
    return hint;
}

function setFuncionarioHintDesktop(msg, level = 'info') {
    const hint = getFuncionarioHintDesktop();
    if (!hint) return;
    hint.textContent = msg || '';
    hint.style.color = level === 'warn' ? '#c0392b' : (level === 'ok' ? '#1b7f3b' : '#555');
}

function bindFuncionarioSyncStatusDesktop() {
    window.addEventListener('comanda-sync-status', (event) => {
        const detail = event && event.detail ? event.detail : {};
        const message = String(detail.message || '');
        if (!message) return;

        if (message.toLowerCase().includes('comanda')) {
            setComandasSyncInfoDesktop(detail.source || 'cache', detail.level || 'info');
        }

        if (message.toLowerCase().includes('funcionario')) {
            setFuncionarioHintDesktop(message, detail.level || 'info');
        }
    });
}

function showDashboardSkeletons() {
    const skeletonHtml = '<div class="skeleton-list"><div class="skeleton-card"></div><div class="skeleton-card"></div><div class="skeleton-card"></div></div>';
    const abertas = document.getElementById('listaComandasAbertas');
    const fechadas = document.getElementById('listaComandasFechadas');
    const porFunc = document.getElementById('comandasPorFuncionario');
    if (abertas) abertas.innerHTML = skeletonHtml;
    if (fechadas) fechadas.innerHTML = skeletonHtml;
    if (porFunc) porFunc.innerHTML = skeletonHtml;
}

function bindDashboardComandas() {
    [
        document.getElementById('listaComandasAbertas'),
        document.getElementById('listaComandasFechadas')
    ].forEach(container => {
        ComandaModule.bindContainer(container, {
            onClose: fecharComandaRapido,
            onDelete: excluirComanda,
            onOpen: abrirComanda,
            onPrint: ComandaModule.printComanda,
            onReopen: reabrirComandaRapido
        });
    });

    ComandaModule.bindContainer(document.getElementById('comandasPorFuncionario'), {
        onOpen: abrirComanda,
        expandOnCardClick: false
    });
}

async function carregarFuncionariosSelect() {
    const select = document.getElementById('funcionarioResponsavel');
    try {
        setFuncionarioHintDesktop('Carregando funcionarios...');
        const funcionarios = await Storage.getFuncionarios();
        const session = Storage.getSession();
        const funcionarioLogadoId = session && session.funcionarioId ? String(session.funcionarioId) : '';
        
        select.innerHTML = '<option value="">Selecione...</option>';
        funcionarios.forEach(f => {
            select.innerHTML += `<option value="${f.id}">${escapeHtml(f.nome)}</option>`;
        });

        if (funcionarioLogadoId && funcionarios.some((f) => String(f.id) === funcionarioLogadoId)) {
            select.value = funcionarioLogadoId;
        }
        if (funcionarios.length > 0) {
            setFuncionarioHintDesktop('Funcionarios prontos para uso.', 'ok');
        } else {
            setFuncionarioHintDesktop('Sem funcionarios disponiveis no momento.', 'warn');
        }
    } catch (e) {
        console.error('Erro ao carregar funcionários:', e);
        setFuncionarioHintDesktop('Falha ao carregar funcionarios do servidor.', 'warn');
    }
}

async function atualizarStats() {
    try {
        const comandas = Array.isArray(dashboardComandas) ? dashboardComandas : [];
        Storage.notificarPedidosProntos(comandas);
        const hoje = new Date();
        
        const abertas = comandas.filter(c => c.status === 'aberta');
        const fechadasHoje = comandas.filter(c => {
            if (c.status !== 'fechada') return false;
            const dataFechamento = c.fechamento ? new Date(c.fechamento.data) : new Date(c.createdAt);
            return dataFechamento.toDateString() === hoje.toDateString();
        });
        
        const relatorioHoje = await Storage.getRelatorioDia(hoje);
        const funcionarios = await Storage.getFuncionarios();
        
        document.getElementById('statComandasAbertas').textContent = abertas.length;
        document.getElementById('statComandasFechadas').textContent = fechadasHoje.length;
        document.getElementById('statTotalVendas').textContent = `R$ ${relatorioHoje.total.toFixed(2)}`;
        document.getElementById('statFuncionarios').textContent = funcionarios.length;
    } catch (e) {
        console.error('Erro ao atualizar estatísticas:', e);
    }
}

function iniciarAutoRefreshDashboard() {
    if (dashboardAutoRefreshTimer) {
        clearInterval(dashboardAutoRefreshTimer);
    }
    dashboardAutoRefreshTimer = setInterval(async () => {
        await sincronizarDashboardComandasEmSegundoPlano({ silent: true });
    }, DASHBOARD_AUTO_REFRESH_MS);
}

function renderizarDashboardComandasDoCache() {
    dashboardComandas = Storage.getComandasSnapshot();
    setComandasSyncInfoDesktop('cache', 'info');

    if (dashboardComandas.length > 0) {
        Storage.notifySyncStatus('Comandas exibidas com dados ja carregados.', 'info', { source: 'cache' });
    } else {
        Storage.notifySyncStatus('Carregando comandas em segundo plano...', 'info', { source: 'cache' });
    }

    atualizarStats();
    renderComandasAbertas();
    renderComandasFechadas();
    renderComandasPorFuncionario();
}

async function sincronizarDashboardComandasEmSegundoPlano(options = {}) {
    try {
        dashboardComandas = await Storage.syncComandasInBackground(null, options);
        await atualizarStats();
        await renderComandasAbertas();
        await renderComandasFechadas();
        await renderComandasPorFuncionario();
        setComandasSyncInfoDesktop('server', 'ok');
    } catch (e) {
        console.error('Erro ao sincronizar comandas no dashboard:', e);
        setComandasSyncInfoDesktop('cache', 'warn');
    }
}

async function renderComandasAbertas() {
    const lista = document.getElementById('listaComandasAbertas');
    try {
        const comandas = Array.isArray(dashboardComandas) ? dashboardComandas : [];
        const abertas = comandas.filter(c => c.status === 'aberta');

        ComandaModule.renderCards(lista, abertas, {
            actionLabel: 'Abrir/Editar',
            allowDelete: true,
            emptyMessage: 'Nenhuma comanda aberta.'
        });
    } catch (e) {
        console.error('Erro ao renderizar comandas abertas:', e);
        lista.innerHTML = '<p class="empty">Erro ao carregar comandas.</p>';
    }
}

async function renderComandasFechadas() {
    const lista = document.getElementById('listaComandasFechadas');
    try {
        const comandas = Array.isArray(dashboardComandas) ? dashboardComandas : [];
        const fechadas = comandas.filter(c => c.status === 'fechada' || c.status === 'cancelada');

        ComandaModule.renderCards(lista, fechadas, {
            actionLabel: 'Abrir/Ver',
            allowDelete: true,
            emptyMessage: 'Nenhuma comanda fechada ou cancelada.'
        });
    } catch (e) {
        console.error('Erro ao renderizar comandas fechadas:', e);
        lista.innerHTML = '<p class="empty">Erro ao carregar comandas.</p>';
    }
}

async function renderComandasGeral() {
    await renderComandasAbertas();
    await renderComandasFechadas();
}

async function renderComandasPorFuncionario() {
    const container = document.getElementById('comandasPorFuncionario');
    try {
        const funcionarios = await Storage.getFuncionarios();
        const comandas = Array.isArray(dashboardComandas) ? dashboardComandas : [];
        
        if (funcionarios.length === 0) {
            container.innerHTML = '<p class="empty">Nenhum funcionário cadastrado.</p>';
            return;
        }
        
        container.innerHTML = funcionarios.map(funcionario => {
            const comandasFunc = comandas.filter(c => c.funcionarioId == funcionario.id);
            const comandasAbertas = comandasFunc.filter(c => c.status === 'aberta');
            const comandasFechadas = comandasFunc.filter(c => c.status === 'fechada');
            const comandasCanceladas = comandasFunc.filter(c => c.status === 'cancelada');
            
            return `
                <div class="funcionario-comandas">
                    <h4>${escapeHtml(funcionario.nome)} ${funcionario.is_admin ? '(Admin)' : ''}</h4>
                    <p style="color: #666; font-size: 0.9rem; margin-bottom: 10px;">
                        ${comandasAbertas.length} abertas • ${comandasFechadas.length} fechadas • ${comandasCanceladas.length} canceladas
                    </p>
                    <div class="comandas-list">
                        ${ComandaModule.buildFuncionarioTags(comandasFunc)}
                    </div>
                </div>
            `;
        }).join('');
    } catch (e) {
        console.error('Erro ao renderizar comandas por funcionário:', e);
    }
}

function abrirComanda(id) {
    ComandaModule.openComanda(id);
}

async function excluirComanda(id) {
    if (confirm('Tem certeza que deseja cancelar esta comanda? A cozinha sera avisada.')) {
        try {
            await Storage.deleteComanda(id);
            renderizarDashboardComandasDoCache();
            await sincronizarDashboardComandasEmSegundoPlano();
            Toast.success('Comanda cancelada com sucesso!');
        } catch (error) {
            Toast.error('Erro ao cancelar comanda: ' + error.message);
        }
    }
}

async function fecharComandaRapido(id) {
    try {
        await Storage.fecharComanda(id);
        renderizarDashboardComandasDoCache();
        await sincronizarDashboardComandasEmSegundoPlano();
        Toast.success('Comanda fechada com sucesso!');
    } catch (error) {
        Toast.error('Erro ao fechar comanda: ' + error.message);
    }
}

async function reabrirComandaRapido(id) {
    try {
        await Storage.reabrirComanda(id);
        renderizarDashboardComandasDoCache();
        await sincronizarDashboardComandasEmSegundoPlano();
        Toast.success('Comanda reaberta com sucesso!');
    } catch (error) {
        Toast.error('Erro ao reabrir comanda: ' + error.message);
    }
}

async function refreshDashboardComandas() {
    renderizarDashboardComandasDoCache();
    await sincronizarDashboardComandasEmSegundoPlano();
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDateTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('pt-BR');
}
