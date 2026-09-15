let estoqueAtual = [];

document.addEventListener('DOMContentLoaded', () => {
    if (!Storage.requireAuth()) return;
    
    carregarEstoque();
    
    document.getElementById('formEstoque').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const item = {
            nome: document.getElementById('nomeItem').value.trim(),
            categoria: document.getElementById('categoriaItem').value,
            quantidade: parseFloat(document.getElementById('quantidadeItem').value),
            unidade: document.getElementById('unidadeItem').value,
            quantidadeMinima: parseFloat(document.getElementById('quantidadeMinima').value) || 5,
            valorUnitario: parseFloat(document.getElementById('valorUnitario').value) || 0
        };
        
        await Storage.addItemEstoque(item);
        document.getElementById('formEstoque').reset();
        await carregarEstoque();
        await carregarListaCompras();
        Toast.success('Item cadastrado com sucesso!');
    });
});

async function carregarEstoque() {
    estoqueAtual = await Storage.getEstoque();
    renderEstoque(estoqueAtual);
}

async function carregarListaCompras() {
    const lista = await Storage.getListaCompras(true); // apenas pendentes
    const alertas = estoqueAtual.filter(e => e.quantidade <= e.quantidadeMinima);
    
    // Cria automaticamente itens da lista baseado nos alertas
    for (const item of alertas) {
        const jaExiste = lista.find(l => l.estoque_id == item.id && l.status === 'pendente');
        if (!jaExiste) {
            // Adiciona automaticamente à lista
            const qtdNecessaria = (item.quantidadeMinima * 2) - item.quantidade;
            await Storage.addItemListaCompras({
                estoque_id: item.id,
                nome_item: item.nome,
                quantidade_necessaria: Math.max(qtdNecessaria, item.quantidadeMinima),
                quantidade_minima: item.quantidadeMinima,
                unidade: item.unidade,
                prioridade: 'alta'
            });
        }
    }
    
    // Recarrega a lista
    const listaAtualizada = await Storage.getListaCompras(true);
    renderListaCompras(listaAtualizada);
}

function renderListaCompras(lista) {
    const container = document.getElementById('listaCompras');
    const resumo = document.getElementById('resumoListaCompras');
    const textoResumo = document.getElementById('textoResumoCompras');
    
    if (lista.length === 0) {
        container.innerHTML = '<p class="empty">Nenhum item na lista de compras. Estoque está OK!</p>';
        resumo.style.display = 'none';
        return;
    }
    
    // Mostra resumo
    resumo.style.display = 'block';
    const totalItens = lista.length;
    const prioridadeAlta = lista.filter(l => l.prioridade === 'alta').length;
    textoResumo.innerHTML = `${totalItens} item(ns) precisam ser comprados (${prioridadeAlta} de alta prioridade)`;
    
    container.innerHTML = lista.map(item => `
        <div class="lista-compra-item" style="background: ${item.prioridade === 'alta' ? '#fff5f5' : '#f0fff4'}; border: 2px solid ${item.prioridade === 'alta' ? '#feb2b2' : '#9ae6b4'}; border-radius: 8px; padding: 15px; margin-bottom: 10px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong>${escapeHtml(item.nome_item)}</strong>
                    ${item.estoque_atual !== undefined ? `<br><small style="color: #666;">Em estoque: ${item.estoque_atual} ${item.unidade}</small>` : ''}
                    <br><span style="color: ${item.prioridade === 'alta' ? '#e53e3e' : '#38a169'}; font-weight: bold;">
                        Comprar: ${item.quantidade_necessaria} ${item.unidade}
                    </span>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-secondary btn-small" onclick="marcarComprado(${item.id}, ${item.estoque_id}, ${item.quantidade_necessaria})">
                        ✓ Comprado
                    </button>
                    <button class="btn btn-danger btn-small" onclick="removerListaCompras(${item.id})">
                        ✕
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

async function marcarComprado(id, estoqueId, quantidade) {
    await Storage.marcarItemComprado(id, estoqueId, quantidade);
    await carregarEstoque();
    await carregarListaCompras();
    Toast.success('Item marcado como comprado e adicionado ao estoque!');
}

async function removerListaCompras(id) {
    await Storage.deleteItemListaCompras(id);
    await carregarListaCompras();
    Toast.info('Item removido da lista');
}

function adicionarItemListaManual() {
    const nome = prompt('Nome do item:');
    if (!nome) return;
    
    const qtd = prompt('Quantidade necessária:');
    if (!qtd || isNaN(qtd)) return;
    
    const unidade = prompt('Unidade (un, kg, L, etc):') || 'un';
    
    Storage.addItemListaCompras({
        nome_item: nome,
        quantidade_necessaria: parseFloat(qtd),
        unidade: unidade,
        prioridade: 'media'
    }).then(() => {
        carregarListaCompras();
        Toast.success('Item adicionado à lista de compras');
    });
}

function gerarListaPDF() {
    Storage.getListaCompras(true).then(lista => {
        if (lista.length === 0) {
            Toast.warning('Lista de compras está vazia');
            return;
        }
        
        let conteudo = `LISTA DE COMPRAS - ${new Date().toLocaleDateString('pt-BR')}\n\n`;
        lista.forEach((item, i) => {
            conteudo += `${i + 1}. ${item.nome_item}: ${item.quantidade_necessaria} ${item.unidade}\n`;
        });
        
        const blob = new Blob([conteudo], { type: 'text/plain' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'lista-compras.txt';
        a.click();
        window.URL.revokeObjectURL(url);
    });
}

function renderEstoque(itens) {
    const container = document.getElementById('listaEstoque');
    
    if (itens.length === 0) {
        container.innerHTML = '<p class="empty">Nenhum item em estoque.</p>';
        return;
    }
    
    container.innerHTML = itens.map(item => `
        <div class="lista-item">
            <div>
                <h3>${escapeHtml(item.nome)}</h3>
                <p>Categoria: ${escapeHtml(item.categoria || 'N/A')}</p>
                <p>Quantidade: <strong>${item.quantidade} ${item.unidade}</strong></p>
                <p style="font-size: 0.9rem; color: #999;">Mínimo: ${item.quantidadeMinima} ${item.unidade}</p>
            </div>
            <button class="btn btn-danger btn-small" onclick="removerEstoque(${item.id})">Remover</button>
        </div>
    `).join('');
}

async function removerEstoque(id) {
    if (confirm('Tem certeza que deseja remover este item?')) {
        try {
            await Storage.deleteItemEstoque(id);
            await carregarEstoque();
            Toast.success('Item removido com sucesso!');
        } catch (error) {
            Toast.error('Erro ao remover: ' + error.message);
        }
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
