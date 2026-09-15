let currentComanda = null;
let currentSession = null;
let clienteAtual = null;
let todosOsProdutos = [];

document.addEventListener('DOMContentLoaded', async () => {
    console.log('[Comanda] Iniciando carregamento...');
    
    if (!Storage.requireAuth()) {
        console.error('[Comanda] Autenticação necessária');
        return;
    }
    
    currentSession = Storage.getSession();
    console.log('[Comanda] Sessão:', currentSession);
    
    const urlParams = new URLSearchParams(window.location.search);
    const comandaId = urlParams.get('comandaId');
    console.log('[Comanda] comandaId da URL:', comandaId);
    
    if (!comandaId) {
        Toast.error('Comanda não especificada');
        window.location.href = 'index.html';
        return;
    }
    
    try {
        currentComanda = await Storage.getComanda(comandaId);
        console.log('[Comanda] Comanda carregada:', currentComanda);
        
        if (!currentComanda) {
            Toast.error('Comanda não encontrada');
            window.location.href = 'index.html';
            return;
        }
        
        await carregarDados();
        await precarregarProdutos();
        carregarProdutosSelect();
        console.log('[Comanda] Carregamento completo!');
    } catch (error) {
        console.error('[Comanda] Erro ao carregar:', error);
        Toast.error('Erro ao carregar comanda: ' + error.message);
    }
    
    document.getElementById('btnVoltar').addEventListener('click', () => {
        const destino = window.MobileRouting && window.MobileRouting.isMobileClient()
            ? 'pedidos-mobile.html'
            : 'comandas.html';
        window.location.href = destino;
    });
    
    document.getElementById('btnSalvar').addEventListener('click', async () => {
        await salvarComanda();
    });
    
    document.getElementById('btnImprimir').addEventListener('click', imprimirComanda);
    const btnImprimirCozinha = document.getElementById('btnImprimirCozinha');
    if (btnImprimirCozinha) {
        btnImprimirCozinha.addEventListener('click', imprimirFichaCozinha);
    }
    
    document.getElementById('btnFechar').addEventListener('click', async () => {
        if (currentComanda.status === 'fechada') {
            await reabrirComanda();
        } else {
            await fecharComanda();
        }
    });
    
    document.getElementById('categoriaItem').addEventListener('change', (e) => {
        carregarProdutosPorCategoria(e.target.value);
    });
    
    document.getElementById('btnAdicionarItem').addEventListener('click', adicionarItem);
    bindProdutoAutocomplete();

    document.addEventListener('keydown', async (event) => {
        if (!Storage.isLoggedIn()) return;

        if (event.ctrlKey && event.key.toLowerCase() === 's') {
            event.preventDefault();
            await salvarComanda();
            return;
        }

        if (event.ctrlKey && event.key === 'Enter') {
            event.preventDefault();
            await adicionarItem();
            return;
        }

        if (event.ctrlKey && event.key.toLowerCase() === 'p') {
            event.preventDefault();
            imprimirComanda();
        }
    });

    const tabelaItens = document.getElementById('tabelaItens');
    if (tabelaItens && !tabelaItens.dataset.bound) {
        tabelaItens.addEventListener('click', async (event) => {
            const actionEl = event.target.closest('[data-item-action][data-item-id]');
            if (!actionEl) return;

            const itemId = Number(actionEl.getAttribute('data-item-id'));
            const action = actionEl.getAttribute('data-item-action');

            if (action === 'remover') {
                removerItem(itemId);
                return;
            }

            if (action === 'editar') {
                await editarItem(itemId);
            }
        });
        tabelaItens.dataset.bound = '1';
    }

    if (urlParams.get('print') === '1') {
        setTimeout(() => {
            imprimirComanda();
        }, 250);
    }
});

async function carregarDados() {
    console.log('Carregando comanda:', currentComanda);
    
    document.getElementById('tituloComanda').textContent = 
        `Comanda - Mesa ${currentComanda.numeroMesa}`;
    
    let responsavelNome = currentComanda.funcionarioNome;
    if (!responsavelNome) {
        const funcionario = await Storage.getFuncionario(currentComanda.funcionarioId);
        responsavelNome = funcionario ? funcionario.nome : 'Desconhecido';
    }
    document.getElementById('responsavelComanda').textContent = 
        `Responsável: ${responsavelNome}`;
    
    document.getElementById('dataCriacao').textContent = 
        `Criada: ${formatDateTime(currentComanda.createdAt)}`;
    
    // Carrega dados do cliente - tenta várias fontes possíveis
    let cliente = null;
    
    // Tenta obter cliente da comanda (pode estar em formato diferente)
    if (currentComanda.cliente && typeof currentComanda.cliente === 'object') {
        cliente = currentComanda.cliente;
        console.log('Cliente encontrado em currentComanda.cliente:', cliente);
    } else if (currentComanda.cliente_nome) {
        // Dados vindos direto da query SQL
        cliente = {
            nome: currentComanda.cliente_nome,
            cpf: currentComanda.cliente_cpf,
            contato: currentComanda.cliente_contato || ''
        };
        console.log('Cliente construído de campos SQL:', cliente);
    }
    
    // Preenche campos do cliente se existirem dados
    if (cliente) {
        if (document.getElementById('nomeCliente')) {
            document.getElementById('nomeCliente').value = cliente.nome || '';
        }
        if (document.getElementById('contatoCliente')) {
            document.getElementById('contatoCliente').value = cliente.contato || '';
        }
        if (document.getElementById('cpfCliente')) {
            document.getElementById('cpfCliente').value = cliente.cpf || '';
        }
    }
    
    // Carrega observações
    if (document.getElementById('observacoes')) {
        document.getElementById('observacoes').value = currentComanda.observacoes || '';
    }

    // Carrega forma de pagamento
    if (document.getElementById('formaPagamento') && currentComanda.formaPagamento) {
        document.getElementById('formaPagamento').value = currentComanda.formaPagamento;
    }
        
    // Configura evento de busca por CPF
    const cpfInput = document.getElementById('cpfCliente');
    if (cpfInput) {
        cpfInput.addEventListener('blur', async () => {
            const cpf = cpfInput.value.replace(/\D/g, '');
            if (cpf.length === 11) {
                const cliente = await Storage.getClienteByCpf(cpf);
                if (cliente) {
                    clienteAtual = cliente;
                    document.getElementById('nomeCliente').value = cliente.nome;
                    document.getElementById('contatoCliente').value = cliente.contato || '';
                    document.getElementById('clienteInfo').textContent = `Cliente encontrado! ${cliente.total_visitas || 0} visitas`;
                    document.getElementById('clienteInfo').style.display = 'block';
                    
                    // Mostra info de fidelidade
                    const fidelidadeDiv = document.getElementById('fidelidadeInfo');
                    const fidelidadeTexto = document.getElementById('fidelidadeTexto');
                    if (fidelidadeDiv && fidelidadeTexto) {
                        fidelidadeDiv.style.display = 'block';
                        fidelidadeTexto.innerHTML = `
                            <strong>${cliente.nome}</strong><br>
                            ${cliente.total_visitas || 0} visitas | 
                            ${cliente.pontos_fidelidade || 0} pontos<br>
                            Total gasto: R$ ${parseFloat(cliente.total_gasto || 0).toFixed(2)}
                        `;
                    }
                    Toast.success(`Cliente ${cliente.nome} encontrado!`);
                } else {
                    document.getElementById('clienteInfo').textContent = 'Novo cliente - será cadastrado automaticamente';
                    document.getElementById('clienteInfo').style.display = 'block';
                    clienteAtual = null;
                }
            }
        });
        
        // Máscara de CPF
        cpfInput.addEventListener('input', (e) => {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 11) value = value.slice(0, 11);
            if (value.length > 9) {
                value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            } else if (value.length > 6) {
                value = value.replace(/(\d{3})(\d{3})(\d{3})/, '$1.$2.$3');
            } else if (value.length > 3) {
                value = value.replace(/(\d{3})(\d{3})/, '$1.$2');
            }
            e.target.value = value;
        });
    }
    
    const statusEl = document.getElementById('statusComanda');
    if (currentComanda.status === 'fechada') {
        statusEl.textContent = 'FECHADA';
        statusEl.className = 'status-fechada';
        desabilitarEdicao();
    } else if (currentComanda.status === 'cancelada') {
        statusEl.textContent = 'CANCELADA';
        statusEl.className = 'status-cancelada';
        desabilitarEdicao(true);
    } else {
        statusEl.textContent = 'ABERTA';
        statusEl.className = 'status-aberta';
        habilitarEdicao();
    }
    
    renderItens();
    
    if (currentComanda.status === 'fechada' && currentComanda.fechamento) {
        const footer = document.getElementById('comandaFooter');
        footer.style.display = 'block';
        document.getElementById('horaFechamento').textContent = 
            `Fechamento: ${formatDateTime(currentComanda.fechamento.data)}`;
        document.getElementById('duracaoComanda').textContent = 
            `Duração: ${currentComanda.fechamento.duracao}`;
    }
}

async function precarregarProdutos() {
    try {
        todosOsProdutos = await Storage.getProdutos();
        if (!Array.isArray(todosOsProdutos)) todosOsProdutos = [];
        console.log('[Comanda] Produtos pré-carregados:', todosOsProdutos.length);
    } catch (e) {
        console.error('[Comanda] Erro ao pré-carregar produtos:', e);
        Toast.warning('Não foi possível carregar o catálogo de produtos. Verifique a conexão.');
        todosOsProdutos = [];
    }
}

function carregarProdutosSelect() {
    const categoriaSelect = document.getElementById('categoriaItem');
    categoriaSelect.value = categoriaSelect.value || 'todos';
    carregarProdutosPorCategoria(categoriaSelect.value);
}

function getCategoriaProdutoAtual() {
    const categoria = document.getElementById('categoriaItem')?.value || 'todos';
    return categoria || 'todos';
}

function getProdutosDisponiveis(categoria = getCategoriaProdutoAtual()) {
    if (categoria === 'outros') return [];
    if (categoria === 'todos') return todosOsProdutos;
    return todosOsProdutos.filter((produto) => produto.categoria === categoria);
}

function limparSelecaoProduto() {
    const produtoInput = document.getElementById('produtoItem');
    const buscaEl = document.getElementById('buscaProdutoItem');
    if (produtoInput) produtoInput.value = '';
    if (buscaEl && getCategoriaProdutoAtual() !== 'outros') {
        buscaEl.placeholder = 'Toque para listar produtos ou digite para filtrar';
    }
}

function ocultarSugestoesProduto() {
    const sugestoes = document.getElementById('produtoSugestoes');
    if (!sugestoes) return;
    sugestoes.hidden = true;
    sugestoes.innerHTML = '';
}

function selecionarProduto(produto) {
    const produtoInput = document.getElementById('produtoItem');
    const buscaEl = document.getElementById('buscaProdutoItem');
    const valorEl = document.getElementById('valorItem');
    if (!produtoInput || !buscaEl || !valorEl) return;

    produtoInput.value = String(produto.id);
    buscaEl.value = produto.nome;
    valorEl.value = Number(produto.preco).toFixed(2);
    ocultarSugestoesProduto();
}

function renderSugestoesProduto(forceOpen = false) {
    const sugestoes = document.getElementById('produtoSugestoes');
    const buscaEl = document.getElementById('buscaProdutoItem');
    if (!sugestoes || !buscaEl) return;

    const categoria = getCategoriaProdutoAtual();
    if (categoria === 'outros') {
        ocultarSugestoesProduto();
        buscaEl.placeholder = 'Digite o nome do item manualmente';
        return;
    }

    const termo = buscaEl.value.trim().toLowerCase();
    const produtos = getProdutosDisponiveis(categoria).filter((produto) => {
        if (!termo) return true;
        return String(produto.nome || '').toLowerCase().includes(termo);
    });

    if (!produtos.length) {
        if (!forceOpen && !termo) {
            ocultarSugestoesProduto();
            return;
        }

        sugestoes.hidden = false;
        sugestoes.innerHTML = '<div class="product-suggestion-empty">Nenhum produto encontrado para este filtro.</div>';
        return;
    }

    const produtosExibidos = produtos.slice(0, 40);
    const totalLabel = produtos.length === 1 ? '1 resultado' : `${produtos.length} resultados`;

    sugestoes.hidden = false;
    sugestoes.innerHTML = `
        <div class="product-suggestion-header">
            <span class="product-suggestion-count">${totalLabel}</span>
            <span class="product-suggestion-help">Toque para selecionar</span>
        </div>
        <div class="product-suggestion-list">
            ${produtosExibidos.map((produto) => `
        <button type="button" class="product-suggestion-item" data-produto-id="${produto.id}">
            <span class="product-suggestion-name">${escapeHtml(produto.nome)}</span>
            <div class="product-suggestion-footer">
                <span class="product-suggestion-cat">${escapeHtml(produto.categoria)}</span>
                <span class="product-suggestion-price">R$&nbsp;${Number(produto.preco).toFixed(2)}</span>
            </div>
        </button>
    `).join('')}
        </div>
    `;
}

function filtrarProdutosSelect() {
    const produtoInput = document.getElementById('produtoItem');
    if (produtoInput) {
        produtoInput.value = '';
    }
    renderSugestoesProduto(true);
}

function bindProdutoAutocomplete() {
    const buscaEl = document.getElementById('buscaProdutoItem');
    const sugestoes = document.getElementById('produtoSugestoes');
    if (!buscaEl || !sugestoes) return;

    buscaEl.addEventListener('focus', () => {
        renderSugestoesProduto(true);
    });

    buscaEl.addEventListener('click', () => {
        renderSugestoesProduto(true);
    });

    buscaEl.addEventListener('input', () => {
        filtrarProdutosSelect();
    });

    buscaEl.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            const primeiro = sugestoes.querySelector('[data-produto-id]');
            if (primeiro) {
                event.preventDefault();
                primeiro.click();
            }
        }

        if (event.key === 'Escape') {
            ocultarSugestoesProduto();
        }
    });

    sugestoes.addEventListener('click', (event) => {
        const item = event.target.closest('[data-produto-id]');
        if (!item) return;
        const produto = todosOsProdutos.find((entry) => String(entry.id) === String(item.getAttribute('data-produto-id')));
        if (!produto) return;
        selecionarProduto(produto);
    });

    document.addEventListener('click', (event) => {
        if (event.target.closest('.product-picker')) return;
        ocultarSugestoesProduto();
    });
}

async function carregarProdutosPorCategoria(categoria) {
    const buscaEl = document.getElementById('buscaProdutoItem');
    const produtoInput = document.getElementById('produtoItem');
    
    if (categoria === 'outros') {
        if (produtoInput) produtoInput.value = '';
        if (buscaEl) {
            buscaEl.value = '';
            buscaEl.placeholder = 'Digite o nome do item manualmente';
        }
        ocultarSugestoesProduto();
        document.getElementById('valorItem').focus();
        return;
    }

    if (todosOsProdutos.length === 0) {
        await precarregarProdutos();
    }

    if (buscaEl) {
        buscaEl.placeholder = 'Toque para listar produtos ou digite para filtrar';
    }

    if (produtoInput && produtoInput.value) {
        const produtoSelecionado = todosOsProdutos.find((produto) => String(produto.id) === String(produtoInput.value));
        if (!produtoSelecionado || (categoria !== 'todos' && produtoSelecionado.categoria !== categoria)) {
            limparSelecaoProduto();
            if (buscaEl) buscaEl.value = '';
        }
    }

    renderSugestoesProduto(false);
}

async function adicionarItem() {
    const categoriaSelecionada = getCategoriaProdutoAtual();
    const produtoId = document.getElementById('produtoItem').value;
    const buscaEl = document.getElementById('buscaProdutoItem');
    const quantidade = parseInt(document.getElementById('quantidadeItem').value);
    let valor = parseFloat(document.getElementById('valorItem').value);
    
    let nome = '';
    let categoria = categoriaSelecionada;
    let produtoSelecionado = null;
    
    if (categoriaSelecionada !== 'outros' && produtoId) {
        produtoSelecionado = todosOsProdutos.find(p => p.id == produtoId);
    }

    if (!produtoSelecionado && categoriaSelecionada !== 'outros') {
        const nomeDigitado = String(buscaEl?.value || '').trim().toLowerCase();
        if (nomeDigitado) {
            produtoSelecionado = getProdutosDisponiveis(categoriaSelecionada).find((produto) => {
                return String(produto.nome || '').trim().toLowerCase() === nomeDigitado;
            });
        }
    }

    if (produtoSelecionado) {
        nome = produtoSelecionado.nome;
        categoria = produtoSelecionado.categoria;
        if (isNaN(valor) || valor < 0) {
            valor = Number(produtoSelecionado.preco);
        }
    }
    
    if (!nome) {
        nome = String(buscaEl?.value || '').trim();
    }

    if (!nome) {
        Toast.warning('Selecione ou digite um produto.');
        return;
    }
    
    if (!quantidade || quantidade <= 0) {
        Toast.error('Quantidade inválida');
        return;
    }
    
    if (isNaN(valor) || valor < 0) {
        Toast.error('Valor inválido');
        return;
    }
    
    if (!currentComanda.itens) {
        currentComanda.itens = [];
    }
    
    currentComanda.itens.push({
        id: Date.now(),
        nome,
        categoria,
        quantidade,
        valor,
        produtoId: produtoSelecionado ? produtoSelecionado.id : (produtoId || null)
    });
    
    document.getElementById('produtoItem').value = '';
    if (buscaEl) buscaEl.value = '';
    ocultarSugestoesProduto();
    document.getElementById('quantidadeItem').value = '1';
    document.getElementById('valorItem').value = '';
    
    renderItens();
    Toast.success('Item adicionado!');
}

function renderItens() {
    const tbody = document.getElementById('tabelaItens');
    
    if (!currentComanda.itens || currentComanda.itens.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="empty">Nenhum item adicionado</td></tr>';
        document.getElementById('totalGeral').textContent = 'R$ 0,00';
        renderHistoricoCancelamentos();
        return;
    }
    
    let total = 0;
    
    tbody.innerHTML = currentComanda.itens.map(item => {
        const itemTotal = item.quantidade * item.valor;
        total += itemTotal;
        
        return `
            <tr>
                <td>${escapeHtml(item.nome)}</td>
                <td>${item.quantidade}</td>
                <td>R$ ${item.valor.toFixed(2)}</td>
                <td>R$ ${itemTotal.toFixed(2)}</td>
                <td>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        <button class="btn btn-secondary btn-small" type="button" data-item-action="editar" data-item-id="${item.id}">Editar</button>
                        <button class="btn btn-danger btn-small" type="button" data-item-action="remover" data-item-id="${item.id}">Remover</button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
    
    document.getElementById('totalGeral').textContent = `R$ ${total.toFixed(2)}`;
    renderHistoricoCancelamentos();
}

function renderHistoricoCancelamentos() {
    const section = document.getElementById('historicoCancelamentosSection');
    const lista = document.getElementById('historicoCancelamentosLista');
    if (!section || !lista) return;

    const historico = Array.isArray(currentComanda?.historicoCancelamentos)
        ? currentComanda.historicoCancelamentos
        : [];

    if (historico.length === 0) {
        section.style.display = 'none';
        lista.innerHTML = '';
        return;
    }

    section.style.display = 'block';
    lista.innerHTML = historico.map((item) => {
        const quando = item.canceladoEm ? formatDateTime(item.canceladoEm) : 'Agora';
        const obs = item.observacoes ? `<span class="cancelado-obs">Obs: ${escapeHtml(item.observacoes)}</span>` : '';
        return `
            <div class="cancelado-log-item">
                <div class="cancelado-log-top">
                    <strong>${escapeHtml(item.nome || 'Item')}</strong>
                    <span class="cancelado-log-time">${escapeHtml(quando)}</span>
                </div>
                <div class="cancelado-log-meta">
                    <span>${Number(item.quantidade || 0)}x</span>
                    <span>R$ ${Number(item.valor || 0).toFixed(2)}</span>
                    ${item.categoria ? `<span>${escapeHtml(item.categoria)}</span>` : ''}
                </div>
                ${obs}
            </div>
        `;
    }).join('');
}

function removerItem(itemId) {
    if (confirm('Tem certeza que deseja remover este item?')) {
        const itemRemovido = (currentComanda.itens || []).find(item => item.id === itemId);
        currentComanda.itens = currentComanda.itens.filter(item => item.id !== itemId);

        if (itemRemovido) {
            if (!Array.isArray(currentComanda.historicoCancelamentos)) {
                currentComanda.historicoCancelamentos = [];
            }
            currentComanda.historicoCancelamentos.unshift({
                id: `tmp-${Date.now()}`,
                nome: itemRemovido.nome,
                categoria: itemRemovido.categoria || null,
                quantidade: Number(itemRemovido.quantidade || 0),
                valor: Number(itemRemovido.valor || 0),
                observacoes: itemRemovido.observacoes || null,
                canceladoEm: new Date().toISOString()
            });
        }

        renderItens();
        Toast.success('Item removido. Clique em "Salvar Comanda" para confirmar.');
    }
}

async function editarItem(itemId) {
    const item = (currentComanda.itens || []).find((entry) => entry.id === itemId);
    if (!item) return;

    const novoNome = prompt('Editar nome do item:', item.nome || '');
    if (novoNome === null) return;

    const quantidadeTexto = prompt('Editar quantidade:', String(item.quantidade || 1));
    if (quantidadeTexto === null) return;

    const valorTexto = prompt('Editar valor unitario:', String(item.valor || 0));
    if (valorTexto === null) return;

    const quantidade = parseInt(quantidadeTexto, 10);
    const valor = parseFloat(String(valorTexto).replace(',', '.'));

    if (!novoNome.trim() || !quantidade || quantidade <= 0 || isNaN(valor) || valor < 0) {
        Toast.error('Dados invalidos para editar item.');
        return;
    }

    item.nome = novoNome.trim();
    item.quantidade = quantidade;
    item.valor = valor;
    renderItens();
    Toast.success('Item atualizado. Clique em "Salvar Comanda" para confirmar.');
}

function getDestinoPosSalvar() {
    if (window.MobileRouting && window.MobileRouting.isMobileClient()) {
        return 'index-mobile.html';
    }
    return 'index.html';
}

async function salvarComanda(options = {}) {
    const redirect = options.redirect !== false;
    const showToast = options.showToast !== false;
    const cpfInput = document.getElementById('cpfCliente');
    const cpf = cpfInput ? cpfInput.value : '';
    
    currentComanda.cliente = {
        nome: document.getElementById('nomeCliente').value,
        contato: document.getElementById('contatoCliente').value,
        cpf: cpf,
        observacoes: document.getElementById('observacoes').value
    };

    const formaPagamento = document.getElementById('formaPagamento');
    if (formaPagamento) {
        currentComanda.formaPagamento = formaPagamento.value || 'nao_definido';
    }
    
    try {
        const result = await Storage.updateComanda(currentComanda);
        if (result && result.versao_nova) {
            currentComanda.versao = Number(result.versao_nova);
        }
    } catch (error) {
        const mensagem = String(error && error.message ? error.message : 'Erro ao salvar comanda');
        if (mensagem.toLowerCase().includes('conflito') || mensagem.includes('COMANDA_VERSION_CONFLICT')) {
            Toast.error('Esta comanda foi alterada por outro atendente. Recarregue a tela para evitar sobrescrever dados.');
            return;
        }
        throw error;
    }
    if (showToast) {
        Toast.success('Comanda salva com sucesso!');
    }
    if (redirect) {
        const destino = getDestinoPosSalvar();
        setTimeout(() => { window.location.href = destino; }, 900);
    }
}

async function salvarImagem() {
    const element = document.getElementById('comandaCapture');
    
    if (typeof html2canvas === 'undefined') {
        Toast.error('Biblioteca de captura não carregada. Tente novamente em alguns segundos.');
        return;
    }
    
    try {
        const canvas = await html2canvas(element, {
            scale: 2,
            backgroundColor: '#ffffff'
        });
        const link = document.createElement('a');
        link.download = `comanda_${currentComanda.numeroMesa}_${new Date().toLocaleDateString('pt-BR').replace(/\//g, '_')}.png`;
        link.href = canvas.toDataURL();
        link.click();
        Toast.success('Imagem salva com sucesso!');
    } catch (err) {
        console.error('Erro ao gerar imagem:', err);
        Toast.error('Erro ao gerar imagem. Por favor, tente novamente.');
    }
}

function imprimirFichaCozinha() {
    const itens = Array.isArray(currentComanda?.itens) ? currentComanda.itens : [];
    const cliente = currentComanda?.cliente?.nome || currentComanda?.clienteNome || 'Sem cliente';

    const linhas = itens.map((item) => `
        <tr>
            <td style="padding:4px 0;">${Number(item.quantidade || 0)}x</td>
            <td style="padding:4px 0;">${escapeHtml(item.nome || '')}</td>
        </tr>
    `).join('');

    const win = window.open('', '_blank', 'width=480,height=720');
    if (!win) {
           Toast.error('Não foi possível abrir a impressão. Verifique bloqueador de popup.');
        return;
    }

    win.document.write(`
        <html>
        <head><title>Ficha Cozinha Mesa ${escapeHtml(String(currentComanda?.numeroMesa || ''))}</title></head>
        <body style="font-family: monospace; padding: 12px;">
            <h2 style="margin:0 0 6px;">FICHA COZINHA</h2>
            <div>Mesa: ${escapeHtml(String(currentComanda?.numeroMesa || ''))}</div>
            <div>Cliente: ${escapeHtml(String(cliente))}</div>
            <div style="margin:8px 0;">${new Date().toLocaleString('pt-BR')}</div>
            <hr>
            <table style="width:100%; border-collapse:collapse;">${linhas}</table>
            <hr>
            <small>Impressao compacta de contingencia.</small>
        </body>
        </html>
    `);
    win.document.close();
    win.focus();
    win.print();
}

async function fecharComanda() {
    if (currentComanda.status === 'cancelada') {
           Toast.warning('Esta comanda foi cancelada e não pode ser fechada.');
        return;
    }
    if (currentComanda.status === 'fechada') {
        Toast.warning('Esta comanda já está fechada.');
        return;
    }
    
    // Calcula total da comanda
    const total = currentComanda.itens ? currentComanda.itens.reduce((sum, item) => sum + (item.quantidade * item.valor), 0) : 0;
    const numItens = currentComanda.itens ? currentComanda.itens.length : 0;
    
    const mensagem = `Tem certeza que deseja fechar esta comanda?\n\n` +
        `Mesa: ${currentComanda.numeroMesa}\n` +
        `Itens: ${numItens}\n` +
        `Total: R$ ${total.toFixed(2)}`;
    
    if (!confirm(mensagem)) {
        return;
    }
    
    // Salva primeiro
    await salvarComanda({ redirect: false, showToast: false });
    
    // Fecha via API
    const result = await Storage.fecharComanda(currentComanda.id);
    
    if (result.success) {
        currentComanda.status = 'fechada';
        currentComanda.fechamento = {
            data: new Date().toISOString(),
            duracao: result.duracao
        };
        currentComanda.total = total;
        
        await carregarDados();
        desabilitarEdicao();
        
        let msg = 'Comanda fechada com sucesso!';
        if (result.pontos_ganhos > 0) {
            msg += ` Cliente ganhou ${result.pontos_ganhos} pontos!`;
        }
        Toast.success(msg);
    } else {
        Toast.error('Erro ao fechar comanda: ' + (result.error || 'Erro desconhecido'));
    }
}

async function reabrirComanda() {
    if (currentComanda.status !== 'fechada') {
        Toast.warning('Esta comanda não está fechada.');
        return;
    }
    
    if (!confirm('Tem certeza que deseja REABRIR esta comanda fechada?')) {
        return;
    }
    
    try {
        const result = await Storage.reabrirComanda(currentComanda.id);
        
        if (result.success) {
            currentComanda.status = 'aberta';
            currentComanda.fechamento = null;
            
            await carregarDados();
            habilitarEdicao();
            
            Toast.success('Comanda reabertas com sucesso!');
        } else {
            Toast.error('Erro ao reabrir comanda: ' + (result.error || 'Erro desconhecido'));
        }
    } catch (error) {
        Toast.error('Erro ao reabrir comanda: ' + error.message);
    }
}

function desabilitarEdicao(cancelada = false) {
    // Desabilita campos de cliente
    document.getElementById('nomeCliente').disabled = true;
    document.getElementById('contatoCliente').disabled = true;
    document.getElementById('cpfCliente').disabled = true;
    document.getElementById('observacoes').disabled = true;
    
    // Desabilita campos de adicionar item
    document.getElementById('categoriaItem').disabled = true;
    document.getElementById('produtoItem').disabled = true;
    document.getElementById('buscaProdutoItem').disabled = true;
    document.getElementById('quantidadeItem').disabled = true;
    document.getElementById('valorItem').disabled = true;
    
    // Desabilita forma de pagamento
    const formaPagamento = document.getElementById('formaPagamento');
    if (formaPagamento) {
        formaPagamento.disabled = true;
    }
    
    // Desabilita botões
    document.getElementById('btnAdicionarItem').disabled = true;
    document.getElementById('btnSalvar').disabled = true;
    document.getElementById('btnFechar').disabled = cancelada;
    
    // Altera texto dos botões
    document.getElementById('btnSalvar').textContent = 'Salvar Comanda';
    document.getElementById('btnFechar').textContent = cancelada ? 'Comanda Cancelada' : 'Reabrir Comanda';
}

function habilitarEdicao() {
    // Habilita campos de cliente
    document.getElementById('nomeCliente').disabled = false;
    document.getElementById('contatoCliente').disabled = false;
    document.getElementById('cpfCliente').disabled = false;
    document.getElementById('observacoes').disabled = false;
    
    // Habilita campos de adicionar item
    document.getElementById('categoriaItem').disabled = false;
    document.getElementById('produtoItem').disabled = false;
    document.getElementById('buscaProdutoItem').disabled = false;
    document.getElementById('quantidadeItem').disabled = false;
    document.getElementById('valorItem').disabled = false;
    
    // Habilita forma de pagamento
    const formaPagamento = document.getElementById('formaPagamento');
    if (formaPagamento) {
        formaPagamento.disabled = false;
    }
    
    // Habilita botões
    document.getElementById('btnAdicionarItem').disabled = false;
    document.getElementById('btnFechar').disabled = false;
    document.getElementById('btnSalvar').disabled = false;
    
    // Restaura textos
    document.getElementById('btnSalvar').textContent = 'Salvar Comanda';
    document.getElementById('btnFechar').textContent = 'Fechar Comanda';
    carregarProdutosPorCategoria(getCategoriaProdutoAtual());
}

function formatDateTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('pt-BR');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatCurrency(value) {
    return `R$ ${Number(value || 0).toFixed(2)}`;
}

function formatPaymentMethod(method) {
    const labels = {
        dinheiro: 'DINHEIRO',
        credito: 'CARTÃO CRÉDITO',
        debito: 'CARTÃO DÉBITO',
        pix: 'PIX'
    };

    return labels[method] || 'NÃO INFORMADO';
}

async function imprimirComanda() {
    const printWindow = window.open('', '_blank', 'width=420,height=760');
    if (!printWindow) {
        Toast.error('O navegador bloqueou a janela de impressão. Libere pop-ups e tente novamente.');
        return;
    }

    const empresa = await Storage.getEmpresa();
    const total = currentComanda.itens
        ? currentComanda.itens.reduce((sum, item) => sum + (item.quantidade * item.valor), 0)
        : 0;

    const nomeCliente = document.getElementById('nomeCliente')?.value?.trim() || currentComanda.clienteNome || 'CONSUMIDOR NÃO IDENTIFICADO';
    const contatoCliente = document.getElementById('contatoCliente')?.value?.trim() || '-';
    const cpfCliente = document.getElementById('cpfCliente')?.value?.trim() || '-';
    const observacoes = document.getElementById('observacoes')?.value?.trim() || '';
    const formaPagamento = document.getElementById('formaPagamento')?.value || currentComanda.formaPagamento || '';
    const dataCriacao = formatDateTime(currentComanda.createdAt);
    const dataFechamento = currentComanda.fechamento?.data ? formatDateTime(currentComanda.fechamento.data) : null;
    const operador = currentComanda.funcionarioNome || currentSession?.nome || 'NÃO INFORMADO';
    const empresaNome = empresa?.nome ? String(empresa.nome).toUpperCase() : 'ESPETARIA OLIVEIRA';

    const itensHtml = currentComanda.itens && currentComanda.itens.length > 0
        ? currentComanda.itens.map((item, index) => {
            const quantidade = Number(item.quantidade || 0);
            const valorUnitario = Number(item.valor || 0);
            const itemTotal = quantidade * valorUnitario;
            return `
                <div class="item">
                    <div class="item-top">
                        <span class="item-index">${index + 1}.</span>
                        <span class="item-name">${escapeHtml(item.nome || 'ITEM')}</span>
                    </div>
                    <div class="item-bottom">
                        <span>${quantidade} x ${formatCurrency(valorUnitario)}</span>
                        <strong>${formatCurrency(itemTotal)}</strong>
                    </div>
                </div>
            `;
        }).join('')
        : '<div class="empty">SEM ITENS LANCADOS</div>';

    printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Cupom - Mesa ${escapeHtml(currentComanda.numeroMesa)}</title>
            <style>
                @page {
                    size: 80mm auto;
                    margin: 4mm;
                }

                * {
                    box-sizing: border-box;
                }

                body {
                    margin: 0;
                    padding: 0;
                    background: #ffffff;
                    color: #000000;
                    font-family: 'Courier New', Courier, monospace;
                    font-size: 12px;
                    line-height: 1.35;
                }

                .cupom {
                    width: 72mm;
                    margin: 0 auto;
                    padding: 4mm 2mm 6mm;
                }

                .center {
                    text-align: center;
                }

                .title {
                    font-size: 15px;
                    font-weight: 700;
                    text-transform: uppercase;
                }

                .subtitle {
                    margin-top: 3px;
                    font-size: 11px;
                }

                .line {
                    border-top: 1px dashed #000;
                    margin: 8px 0;
                }

                .meta,
                .customer,
                .totals,
                .footer {
                    display: grid;
                    gap: 3px;
                }

                .row {
                    display: flex;
                    justify-content: space-between;
                    gap: 8px;
                }

                .row strong {
                    font-weight: 700;
                }

                .items-header {
                    display: flex;
                    justify-content: space-between;
                    font-weight: 700;
                }

                .item {
                    padding: 5px 0;
                    border-bottom: 1px dashed #999;
                }

                .item-top,
                .item-bottom {
                    display: flex;
                    justify-content: space-between;
                    gap: 8px;
                }

                .item-name {
                    flex: 1;
                    text-align: left;
                    text-transform: uppercase;
                }

                .item-index {
                    width: 18px;
                }

                .totals {
                    margin-top: 8px;
                }

                .grand-total {
                    font-size: 15px;
                    font-weight: 700;
                }

                .obs {
                    white-space: pre-wrap;
                }

                .print-actions {
                    margin-top: 12px;
                    text-align: center;
                }

                .print-actions button {
                    font: inherit;
                    border: 1px solid #000;
                    background: #fff;
                    padding: 8px 12px;
                    cursor: pointer;
                }

                @media print {
                    .print-actions {
                        display: none;
                    }
                }
            </style>
        </head>
        <body>
            <div class="cupom">
                <div class="center">
                    <div class="title">${escapeHtml(empresaNome)}</div>
                    <div class="subtitle">CUPOM NÃO FISCAL</div>
                    <div class="subtitle">MESA ${escapeHtml(currentComanda.numeroMesa)}</div>
                </div>

                <div class="line"></div>

                <div class="meta">
                    <div class="row"><span>DATA ABERTURA</span><strong>${escapeHtml(dataCriacao)}</strong></div>
                    ${dataFechamento ? `<div class="row"><span>DATA FECHAMENTO</span><strong>${escapeHtml(dataFechamento)}</strong></div>` : ''}
                    <div class="row"><span>OPERADOR</span><strong>${escapeHtml(String(operador).toUpperCase())}</strong></div>
                    <div class="row"><span>STATUS</span><strong>${escapeHtml(String(currentComanda.status).toUpperCase())}</strong></div>
                    <div class="row"><span>PAGAMENTO</span><strong>${escapeHtml(formatPaymentMethod(formaPagamento))}</strong></div>
                </div>

                <div class="line"></div>

                <div class="customer">
                    <div><strong>CLIENTE:</strong> ${escapeHtml(nomeCliente.toUpperCase())}</div>
                    <div><strong>CPF:</strong> ${escapeHtml(cpfCliente)}</div>
                    <div><strong>CONTATO:</strong> ${escapeHtml(contatoCliente)}</div>
                    ${observacoes ? `<div class="obs"><strong>OBS:</strong> ${escapeHtml(observacoes)}</div>` : ''}
                </div>

                <div class="line"></div>

                <div class="items-header">
                    <span>ITENS</span>
                    <span>TOTAL</span>
                </div>
                ${itensHtml}

                <div class="line"></div>

                <div class="totals">
                    <div class="row"><span>QTD. ITENS</span><strong>${currentComanda.itens ? currentComanda.itens.length : 0}</strong></div>
                    <div class="row grand-total"><span>TOTAL</span><strong>${formatCurrency(total)}</strong></div>
                </div>

                <div class="line"></div>

                <div class="footer center">
                    <div>OBRIGADO PELA PREFERENCIA</div>
                    <div>VOLTE SEMPRE</div>
                </div>

                <div class="print-actions">
                    <button type="button" id="printReceiptButton">Imprimir cupom</button>
                </div>
            </div>
        </body>
        </html>
    `);

    printWindow.document.close();

    const triggerPrint = () => {
        const button = printWindow.document.getElementById('printReceiptButton');
        if (button) {
            button.addEventListener('click', () => {
                printWindow.focus();
                printWindow.print();
            });
        }

        printWindow.focus();
        printWindow.print();
    };

    if (printWindow.document.readyState === 'complete') {
        setTimeout(triggerPrint, 150);
    } else {
        printWindow.onload = () => setTimeout(triggerPrint, 150);
    }

    Toast.success('Cupom de impressão aberto!');
}
