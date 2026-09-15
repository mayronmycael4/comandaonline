let currentTab = 'dia';
let tabEventsBound = false;

document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;

    bindTabs();
    bindCustomFilter();
    
    await carregarRelatorios('dia');
});

function bindTabs() {
    if (!tabEventsBound) {
        document.addEventListener('click', (event) => {
            const btn = event.target.closest('.tab-btn[data-tab]');
            if (!btn) return;

            event.preventDefault();
            const tab = btn.getAttribute('data-tab');
            if (tab) {
                mostrarTab(tab);
            }
        });
        tabEventsBound = true;
    }

    document.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
        if (!btn.dataset.bound) {
            btn.addEventListener('click', () => {
                const tab = btn.getAttribute('data-tab');
                if (tab) {
                    mostrarTab(tab);
                }
            });
            btn.dataset.bound = '1';
        }
    });
}

function mostrarTab(tab) {
    currentTab = tab;

    const targetContent = document.getElementById(`tab-${tab}`);
    if (!targetContent) return;
    
    // Atualiza botões
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    const activeBtn = document.querySelector(`.tab-btn[data-tab="${tab}"]`);
    if (activeBtn) {
        activeBtn.classList.add('active');
    }
    
    // Atualiza conteúdo
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    targetContent.classList.add('active');

    if (tab !== 'personalizado') {
        toggleCustomResult(false);
    }
    
    carregarRelatorios(tab);
}

function bindCustomFilter() {
    const btn = document.getElementById('btnAplicarFiltroPersonalizado');
    if (!btn || btn.dataset.bound) return;

    btn.addEventListener('click', async () => {
        const inicioStr = document.getElementById('dataInicioPersonalizado')?.value;
        const fimStr = document.getElementById('dataFimPersonalizado')?.value;

        if (!inicioStr || !fimStr) {
            Toast.warning('Selecione data inicial e final.');
            return;
        }

        const inicio = new Date(`${inicioStr}T00:00:00`);
        const fim = new Date(`${fimStr}T23:59:59`);

        if (inicio > fim) {
            Toast.warning('A data inicial não pode ser maior que a final.');
            return;
        }

        try {
            const dados = await calcularRelatorio(inicio, fim);
            currentTab = 'personalizado';
            toggleCustomResult(true);
            await renderRelatorios(dados, 'Vendas no Período');
            await renderFuncionariosVendas(dados.funcionarios, 'Personalizado');
        } catch (error) {
            console.error('Erro no filtro personalizado:', error);
            Toast.error('Erro ao aplicar filtro personalizado');
        }
    });

    btn.dataset.bound = '1';
}

function toggleCustomResult(show) {
    const card = document.getElementById('cardPersonalizado');
    const funcCard = document.getElementById('funcCardPersonalizado');
    if (card) {
        card.style.display = show ? 'block' : 'none';
    }
    if (funcCard) {
        funcCard.style.display = show ? 'block' : 'none';
    }
}

async function carregarRelatorios(tipo) {
    try {
        let dados = null;
        
        switch (tipo) {
            case 'dia':
                dados = await calcularRelatorioDia();
                await renderRelatorios(dados, 'Vendas de Hoje');
                await renderFuncionariosVendas(dados.funcionarios, 'Hoje');
                break;
            case 'semana':
                dados = await calcularRelatorioSemana();
                await renderRelatorios(dados, 'Vendas desta Semana');
                await renderFuncionariosVendas(dados.funcionarios, 'Esta Semana');
                break;
            case 'mes':
                dados = await calcularRelatorioMes();
                await renderRelatorios(dados, 'Vendas deste Mês');
                await renderFuncionariosVendas(dados.funcionarios, 'Este Mês');
                break;
            case 'ano':
                dados = await calcularRelatorioAno();
                await renderRelatorios(dados, 'Vendas deste Ano');
                await renderFuncionariosVendas(dados.funcionarios, 'Este Ano');
                break;
            case 'personalizado':
                // O carregamento é feito pelo botão "Aplicar filtro"
                break;
        }
    } catch (error) {
        console.error('Erro ao carregar relatórios:', error);
        Toast.error('Erro ao carregar relatórios');
    }
}

async function calcularRelatorioDia() {
    const hoje = new Date();
    const inicio = new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate(), 0, 0, 0);
    const fim = new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate(), 23, 59, 59);
    return calcularRelatorio(inicio, fim);
}

async function calcularRelatorioSemana() {
    const hoje = new Date();
    const primeira = new Date(hoje);
    primeira.setDate(hoje.getDate() - hoje.getDay());
    return calcularRelatorio(primeira, hoje);
}

async function calcularRelatorioMes() {
    const hoje = new Date();
    const primeira = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
    return calcularRelatorio(primeira, hoje);
}

async function calcularRelatorioAno() {
    const hoje = new Date();
    const primeira = new Date(hoje.getFullYear(), 0, 1);
    return calcularRelatorio(primeira, hoje);
}

async function calcularRelatorio(dataInicio, dataFim) {
    const comandas = await Storage.getComandas();
    const produtos = await Storage.getProdutos();
    const funcionarios = await Storage.getFuncionarios();
    
    // Filtra apenas comandas fechadas
    const fechadas = comandas.filter(c => c.status === 'fechada');
    
    // Filtra por período
    const filtradas = fechadas.filter(c => {
        const data = new Date(c.fechamento ? c.fechamento.data : c.createdAt);
        return data >= dataInicio && data <= dataFim;
    });
    
    // Calcula totais
    let totalVendas = 0;
    let totalItens = 0;
    const categorias = {};
    const funcMap = {};
    
    filtradas.forEach(comanda => {
        const valor = parseFloat(comanda.total) || 0;
        totalVendas += valor;
        
        // Agrupa por funcionário
        if (!funcMap[comanda.funcionarioId]) {
            funcMap[comanda.funcionarioId] = {
                funcionarioId: comanda.funcionarioId,
                funcionarioNome: comanda.funcionarioNome || 'Desconhecido',
                totalVendas: 0,
                totalComandas: 0,
                totalItens: 0,
                categorias: {}
            };
        }
        
        funcMap[comanda.funcionarioId].totalVendas += valor;
        funcMap[comanda.funcionarioId].totalComandas += 1;
        
        // Contabiliza itens e categorias
        if (comanda.itens && Array.isArray(comanda.itens)) {
            comanda.itens.forEach(item => {
                const qtd = parseFloat(item.quantidade) || 0;
                const valor_unit = parseFloat(item.valor) || 0;
                totalItens += qtd;
                funcMap[comanda.funcionarioId].totalItens += qtd;
                
                const cat = item.categoria || 'Outros';
                if (!categorias[cat]) {
                    categorias[cat] = {
                        categoria: cat,
                        totalItens: 0,
                        totalVenda: 0
                    };
                }
                
                if (!funcMap[comanda.funcionarioId].categorias[cat]) {
                    funcMap[comanda.funcionarioId].categorias[cat] = {
                        categoria: cat,
                        totalItens: 0,
                        totalVenda: 0
                    };
                }
                
                categorias[cat].totalItens += qtd;
                categorias[cat].totalVenda += qtd * valor_unit;
                
                funcMap[comanda.funcionarioId].categorias[cat].totalItens += qtd;
                funcMap[comanda.funcionarioId].categorias[cat].totalVenda += qtd * valor_unit;
            });
        }
    });
    
    return {
        totalVendas,
        totalComandas: filtradas.length,
        totalItens,
        ticketMedio: filtradas.length > 0 ? totalVendas / filtradas.length : 0,
        categorias: Object.values(categorias),
        funcionarios: Object.values(funcMap),
        paymentMethods: calcularPagamentos(filtradas)
    };
}

function calcularPagamentos(comandas) {
    const pagamentos = {
        dinheiro: 0,
        credito: 0,
        debito: 0,
        pix: 0,
        nao_definido: 0
    };
    
    comandas.forEach(comanda => {
        const metodo = comanda.formaPagamento || 'nao_definido';
        const valor = parseFloat(comanda.total) || 0;
        if (pagamentos.hasOwnProperty(metodo)) {
            pagamentos[metodo] += valor;
        } else {
            pagamentos.nao_definido += valor;
        }
    });
    
    return pagamentos;
}

async function renderRelatorios(dados, titulo) {
    const tabId = `tab-${currentTab}`;
    const tabContent = document.getElementById(tabId);
    
    if (!tabContent || !dados) return;
    
    const relatorioCard = tabContent.querySelector('.relatorio-card');
    const categoriasDiv = tabContent.querySelector('.categoria-list');
    
    if (!relatorioCard || !categoriasDiv) return;
    
    // Validação de dados e valores padrão
    const totalVendas = dados.totalVendas || 0;
    const totalComandas = dados.totalComandas || 0;
    const totalItens = dados.totalItens || 0;
    const ticketMedio = dados.ticketMedio || 0;
    
    // Atualiza valores principais
    relatorioCard.querySelector('h3').textContent = titulo;
    relatorioCard.querySelector('.relatorio-valor').textContent = `R$ ${parseFloat(totalVendas).toFixed(2)}`;
    
    const detalhes = relatorioCard.querySelector('.relatorio-detalhes');
    detalhes.innerHTML = `
        <div class="detalhe-item">
            <div class="valor">${totalComandas}</div>
            <div class="label">Comandas</div>
        </div>
        <div class="detalhe-item">
            <div class="valor">${Math.round(totalItens)}</div>
            <div class="label">Itens Vendidos</div>
        </div>
        <div class="detalhe-item">
            <div class="valor">R$ ${parseFloat(ticketMedio).toFixed(2)}</div>
            <div class="label">Ticket Médio</div>
        </div>
    `;
    
    // Renderiza categorias
    if (dados.categorias && Array.isArray(dados.categorias)) {
        categoriasDiv.innerHTML = '<h4>Vendas por Categoria:</h4>' + dados.categorias.map(cat => {
            const totalItens = Math.round(parseFloat(cat.totalItens) || 0);
            const totalVenda = parseFloat(cat.totalVenda) || 0;
            return `
                <div class="categoria-item">
                    <span>${escapeHtml(cat.categoria)}</span>
                    <span>${totalItens} itens • R$ ${totalVenda.toFixed(2)}</span>
                </div>
            `;
        }).join('');
    }
    
    // Renderiza formas de pagamento
    const pagamentoDiv = relatorioCard.querySelector('.categoria-list');
    if (dados.paymentMethods) {
        const pagamentoHtml = Object.entries(dados.paymentMethods)
            .filter(([_, valor]) => parseFloat(valor) > 0)
            .map(([metodo, valor]) => {
                const valorNum = parseFloat(valor) || 0;
                return `
                    <div class="categoria-item">
                        <span>${formatarMetodoPagamento(metodo)}</span>
                        <span>R$ ${valorNum.toFixed(2)}</span>
                    </div>
                `;
            }).join('');
        
        if (pagamentoHtml) {
            categoriasDiv.innerHTML += '<h4 style="margin-top: 15px;">Formas de Pagamento:</h4>' + pagamentoHtml;
        }
    }
}

async function renderFuncionariosVendas(funcionarios, periodo) {
    const sufixo = periodo === 'Hoje'
        ? 'Dia'
        : periodo === 'Esta Semana'
            ? 'Semana'
            : periodo === 'Este Mês'
                ? 'Mes'
                : periodo === 'Personalizado'
                    ? 'Personalizado'
                    : 'Ano';
    const container = document.getElementById(`funcionarios${sufixo}`);
    
    if (!container || !funcionarios || !Array.isArray(funcionarios)) return;
    
    if (funcionarios.length === 0) {
        container.innerHTML = '<p class="empty">Nenhuma venda neste período.</p>';
        return;
    }
    
    const total = funcionarios.reduce((sum, f) => sum + (parseFloat(f.totalVendas) || 0), 0);
    
    container.innerHTML = funcionarios.map(func => {
        const totalVendas = parseFloat(func.totalVendas) || 0;
        const percentual = total > 0 ? (totalVendas / total) * 100 : 0;
        const totalItens = Math.round(parseFloat(func.totalItens) || 0);
        const totalComandas = parseInt(func.totalComandas) || 0;
        
        return `
            <div class="funcionario-vendas">
                <h4>${escapeHtml(func.funcionarioNome)}</h4>
                <p class="relatorio-func-meta">
                    ${totalComandas} comandas • ${totalItens} itens
                </p>
                <div class="relatorio-func-total">
                    <strong>R$ ${totalVendas.toFixed(2)}</strong>
                </div>
                <div class="barra-progresso">
                    <div class="barra-progresso-fill" style="width: ${percentual}%"></div>
                </div>
                <p class="relatorio-func-share">
                    ${percentual.toFixed(1)}% do total
                </p>
            </div>
        `;
    }).join('');
}

function formatarMetodoPagamento(metodo) {
    const mapa = {
        dinheiro: '💵 Dinheiro',
        credito: '💳 Cartão de Crédito',
        debito: '💳 Cartão de Débito',
        pix: '📱 PIX',
        nao_definido: '❓ Não definido'
    };
    return mapa[metodo] || metodo;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
