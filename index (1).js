let currentSession = null;
const DASHBOARD_AUTO_REFRESH_MS = 10000;
let dashboardAutoRefreshTimer = null;

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
    showDashboardSkeletons();
    
    await carregarFuncionariosSelect();
    await atualizarStats();
    await renderComandasAbertas();
    await renderComandasFechadas();
    await renderComandasPorFuncionario();
    iniciarAutoRefreshDashboard();
    
    document.getElementById('formComanda').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const numeroMesa = document.getElementById('numeroMesa').value.trim();
        const funcionarioId = document.getElementById('funcionarioResponsavel').value;
        
        if (numeroMesa && funcionarioId) {
            try {
                const comanda = await Storage.addComanda({
                    numeroMesa,
                    funcionarioId: parseInt(funcionarioId),
                    cliente: { nome: '', contato: '', observacoes: '' },
                    itens: []
                });
                
                document.getElementById('formComanda').reset();
                reaplicarFuncionarioLogado('funcionarioResponsavel');
                await atualizarStats();
                await renderComandasAbertas();
                await renderComandasFechadas();
                await renderComandasPorFuncionario();
                Toast.success('Comanda criada com sucesso!');
                
                setTimeout(() => {
                    window.location.href = `comanda.html?comandaId=${comanda.id}`;
                }, 1000);
            } catch (error) {
                Toast.error('Erro ao criar comanda: ' + error.message);
            }
        }
    });
});

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
    } catch (e) {
        console.error('Erro ao carregar funcionários:', e);
    }
}

async function atualizarStats() {
    try {
        const comandas = await Storage.getComandas();
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
        await refreshDashboardComandas();
    }, DASHBOARD_AUTO_REFRESH_MS);
}

async function renderComandasAbertas() {
    const lista = document.getElementById('listaComandasAbertas');
    try {
        const comandas = await Storage.getComandas();
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
        const comandas = await Storage.getComandas();
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
        const comandas = await Storage.getComandas();
        
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
            await refreshDashboardComandas();
            Toast.success('Comanda cancelada com sucesso!');
        } catch (error) {
            Toast.error('Erro ao cancelar comanda: ' + error.message);
        }
    }
}

async function fecharComandaRapido(id) {
    try {
        await Storage.fecharComanda(id);
        await refreshDashboardComandas();
        Toast.success('Comanda fechada com sucesso!');
    } catch (error) {
        Toast.error('Erro ao fechar comanda: ' + error.message);
    }
}

async function reabrirComandaRapido(id) {
    try {
        await Storage.reabrirComanda(id);
        await refreshDashboardComandas();
        Toast.success('Comanda reaberta com sucesso!');
    } catch (error) {
        Toast.error('Erro ao reabrir comanda: ' + error.message);
    }
}

async function refreshDashboardComandas() {
    await atualizarStats();
    await renderComandasAbertas();
    await renderComandasFechadas();
    await renderComandasPorFuncionario();
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
