let estoqueAtualMobile = [];
let mostrandoApenasAlertas = false;

document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;

    if (!Storage.hasPermission('estoque')) {
        Toast.error('Você não tem permissão para acessar estoque.');
        window.location.href = 'perfil-mobile.html';
        return;
    }

    await carregarEmpresa();
    bindEvents();
    await carregarEstoque();
    await carregarListaCompras();
});

function bindEvents() {
    document.getElementById('formEstoqueMobile').addEventListener('submit', cadastrarItem);
    document.getElementById('btnFiltrarEstoqueMobile').addEventListener('click', () => {
        mostrandoApenasAlertas = false;
        aplicarFiltros();
    });
    document.getElementById('btnMostrarAlertasMobile').addEventListener('click', async () => {
        mostrandoApenasAlertas = true;
        await mostrarAlertas();
    });
    document.getElementById('buscarItemMobile').addEventListener('input', aplicarFiltros);
    document.getElementById('filtroCategoriaMobile').addEventListener('change', aplicarFiltros);
    document.getElementById('btnAdicionarItemListaManualMobile').addEventListener('click', adicionarItemListaManual);
    document.getElementById('btnGerarListaComprasMobile').addEventListener('click', gerarListaTXT);

    document.getElementById('listaEstoqueMobile').addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-estoque-action][data-estoque-id]');
        if (!btn) return;
        const id = Number(btn.getAttribute('data-estoque-id'));
        const action = btn.getAttribute('data-estoque-action');
        if (action === 'remover') await removerEstoque(id);
    });

    document.getElementById('listaComprasMobile').addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-compra-action][data-compra-id]');
        if (!btn) return;
        const id = Number(btn.getAttribute('data-compra-id'));
        const action = btn.getAttribute('data-compra-action');
        const estoqueId = Number(btn.getAttribute('data-estoque-id')) || null;
        const quantidade = Number(btn.getAttribute('data-quantidade')) || 0;
        if (action === 'comprado') {
            await marcarComprado(id, estoqueId, quantidade);
        }
        if (action === 'remover') {
            await removerListaCompras(id);
        }
    });
}

async function carregarEmpresa() {
    try {
        const empresa = await Storage.getEmpresa();
        const el = document.getElementById('nomeEmpresaHeaderEstoqueMobile');
        if (el && empresa && empresa.nome) el.textContent = empresa.nome;
    } catch (error) {
        console.error(error);
    }
}

async function cadastrarItem(event) {
    event.preventDefault();
    const item = {
        nome: document.getElementById('nomeItemMobile').value.trim(),
        categoria: document.getElementById('categoriaItemMobile').value,
        quantidade: parseFloat(document.getElementById('quantidadeItemMobile').value),
        unidade: document.getElementById('unidadeItemMobile').value,
        quantidadeMinima: parseFloat(document.getElementById('quantidadeMinimaMobile').value) || 5,
        valorUnitario: parseFloat(document.getElementById('valorUnitarioMobile').value) || 0
    };

    if (!item.nome || !item.categoria || isNaN(item.quantidade) || item.quantidade < 0) {
        Toast.warning('Preencha os dados corretamente.');
        return;
    }

    await Storage.addItemEstoque(item);
    document.getElementById('formEstoqueMobile').reset();
    await carregarEstoque();
    await carregarListaCompras();
    Toast.success('Item cadastrado com sucesso!');
}

async function carregarEstoque() {
    estoqueAtualMobile = await Storage.getEstoque();
    aplicarFiltros();
}

function aplicarFiltros() {
    const busca = (document.getElementById('buscarItemMobile').value || '').trim().toLowerCase();
    const categoria = document.getElementById('filtroCategoriaMobile').value;

    let itens = [...estoqueAtualMobile];
    if (mostrandoApenasAlertas) {
        itens = itens.filter((item) => Number(item.quantidade) <= Number(item.quantidadeMinima));
    }
    if (categoria) {
        itens = itens.filter((item) => item.categoria === categoria);
    }
    if (busca) {
        itens = itens.filter((item) => String(item.nome || '').toLowerCase().includes(busca));
    }

    renderEstoque(itens);
}

async function mostrarAlertas() {
    const alertas = await Storage.getEstoqueAlertas();
    renderEstoque(alertas);
}

function renderEstoque(itens) {
    const container = document.getElementById('listaEstoqueMobile');
    if (!itens.length) {
        container.innerHTML = '<p class="empty">Nenhum item encontrado.</p>';
        return;
    }

    container.innerHTML = itens.map((item) => {
        const alerta = Number(item.quantidade) <= Number(item.quantidadeMinima);
        return `
            <div class="lista-item estoque-mobile-card" style="border-left: 4px solid ${alerta ? '#e53e3e' : '#48bb78'};">
                <h3>${escapeHtml(item.nome)}</h3>
                <p>Categoria: ${escapeHtml(item.categoria || 'N/A')}</p>
                <p>Quantidade: <strong>${item.quantidade} ${escapeHtml(item.unidade || '')}</strong></p>
                <p>Minimo: ${item.quantidadeMinima} ${escapeHtml(item.unidade || '')}</p>
                <div class="comanda-card-actions" style="margin-top: 8px;">
                    <button class="btn btn-danger btn-small" type="button" data-estoque-action="remover" data-estoque-id="${item.id}">Remover</button>
                </div>
            </div>
        `;
    }).join('');
}

async function carregarListaCompras() {
    const lista = await Storage.getListaCompras(true);
    const alertas = estoqueAtualMobile.filter((e) => Number(e.quantidade) <= Number(e.quantidadeMinima));

    for (const item of alertas) {
        const jaExiste = lista.find((l) => l.estoque_id == item.id && l.status === 'pendente');
        if (!jaExiste) {
            const qtdNecessaria = (Number(item.quantidadeMinima) * 2) - Number(item.quantidade);
            await Storage.addItemListaCompras({
                estoque_id: item.id,
                nome_item: item.nome,
                quantidade_necessaria: Math.max(qtdNecessaria, Number(item.quantidadeMinima)),
                quantidade_minima: item.quantidadeMinima,
                unidade: item.unidade,
                prioridade: 'alta'
            });
        }
    }

    const atualizada = await Storage.getListaCompras(true);
    renderListaCompras(atualizada);
}

function renderListaCompras(lista) {
    const container = document.getElementById('listaComprasMobile');
    const resumo = document.getElementById('textoResumoComprasMobile');

    if (!lista.length) {
        container.innerHTML = '<p class="empty">Nenhum item na lista de compras.</p>';
        resumo.textContent = 'Nenhum item em falta.';
        return;
    }

    resumo.textContent = `${lista.length} item(ns) precisam de reposicao.`;
    container.innerHTML = lista.map((item) => `
        <div class="lista-item" style="border-left: 4px solid ${item.prioridade === 'alta' ? '#e53e3e' : '#48bb78'};">
            <h3>${escapeHtml(item.nome_item)}</h3>
            <p>Comprar: <strong>${item.quantidade_necessaria} ${escapeHtml(item.unidade || 'un')}</strong></p>
            <div class="comanda-card-actions" style="margin-top: 8px;">
                <button class="btn btn-secondary btn-small" type="button" data-compra-action="comprado" data-compra-id="${item.id}" data-estoque-id="${item.estoque_id || ''}" data-quantidade="${item.quantidade_necessaria}">Comprado</button>
                <button class="btn btn-danger btn-small" type="button" data-compra-action="remover" data-compra-id="${item.id}">Remover</button>
            </div>
        </div>
    `).join('');
}

async function marcarComprado(id, estoqueId, quantidade) {
    await Storage.marcarItemComprado(id, estoqueId, quantidade);
    await carregarEstoque();
    await carregarListaCompras();
    Toast.success('Item marcado como comprado!');
}

async function removerListaCompras(id) {
    await Storage.deleteItemListaCompras(id);
    await carregarListaCompras();
    Toast.info('Item removido da lista.');
}

function adicionarItemListaManual() {
    const nome = prompt('Nome do item:');
    if (!nome) return;
    const qtd = prompt('Quantidade necessaria:');
    if (!qtd || isNaN(qtd)) return;
    const unidade = prompt('Unidade (un, kg, L, etc):') || 'un';

    Storage.addItemListaCompras({
        nome_item: nome,
        quantidade_necessaria: parseFloat(qtd),
        unidade,
        prioridade: 'media'
    }).then(async () => {
        await carregarListaCompras();
        Toast.success('Item adicionado a lista.');
    });
}

function gerarListaTXT() {
    Storage.getListaCompras(true).then((lista) => {
        if (!lista.length) {
            Toast.warning('Lista de compras esta vazia.');
            return;
        }
        let conteudo = `LISTA DE COMPRAS - ${new Date().toLocaleDateString('pt-BR')}\n\n`;
        lista.forEach((item, index) => {
            conteudo += `${index + 1}. ${item.nome_item}: ${item.quantidade_necessaria} ${item.unidade}\n`;
        });
        const blob = new Blob([conteudo], { type: 'text/plain' });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'lista-compras.txt';
        link.click();
        window.URL.revokeObjectURL(url);
    });
}

async function removerEstoque(id) {
    if (!confirm('Tem certeza que deseja remover este item?')) return;
    try {
        await Storage.deleteItemEstoque(id);
        await carregarEstoque();
        await carregarListaCompras();
        Toast.success('Item removido com sucesso!');
    } catch (error) {
        Toast.error('Erro ao remover: ' + error.message);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}
