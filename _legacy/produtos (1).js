let produtosCache = [];

document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;

    if (!Storage.hasPermission('produtos')) {
        Toast.error('Você não tem permissão para acessar produtos.');
        window.location.href = 'index.html';
        return;
    }

    bindProductEvents();
    await carregarProdutos();
});

function bindProductEvents() {
    const form = document.getElementById('formProduto');
    const busca = document.getElementById('buscaProduto');
    const filtroCategoria = document.getElementById('filtroCategoriaProduto');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const nome = document.getElementById('nomeProduto').value.trim();
        const categoria = document.getElementById('categoriaProduto').value;
        const preco = parseFloat(document.getElementById('precoProduto').value);

        if (!nome || !categoria || isNaN(preco) || preco < 0) {
            Toast.warning('Preencha os dados corretamente.');
            return;
        }

        try {
            await Storage.addProduto({ nome, categoria, preco });
            form.reset();
            await carregarProdutos();
            Toast.success('Produto cadastrado com sucesso!');
        } catch (error) {
            Toast.error('Erro ao cadastrar produto: ' + error.message);
        }
    });

    busca.addEventListener('input', renderProdutos);
    filtroCategoria.addEventListener('change', renderProdutos);

    ['listaBebidas', 'listaEspetos', 'listaHamburgers', 'listaOutros'].forEach(id => {
        const container = document.getElementById(id);
        container.addEventListener('click', async (event) => {
            const actionEl = event.target.closest('[data-produto-action][data-produto-id]');
            if (!actionEl) return;

            const produtoId = Number(actionEl.getAttribute('data-produto-id'));
            const action = actionEl.getAttribute('data-produto-action');

            if (action === 'editar') {
                abrirModalEdicao(produtoId);
                return;
            }

            if (action === 'excluir') {
                await excluirProduto(produtoId);
            }
        });
    });

    document.getElementById('formEditarProduto').addEventListener('submit', salvarEdicaoProduto);
    document.getElementById('btnFecharModalProduto').addEventListener('click', fecharModalEdicao);
    document.getElementById('btnCancelarEdicaoProduto').addEventListener('click', fecharModalEdicao);
}

async function carregarProdutos() {
    produtosCache = await Storage.getProdutos();
    renderProdutos();
}

function renderProdutos() {
    const textoBusca = (document.getElementById('buscaProduto').value || '').trim().toLowerCase();
    const categoriaFiltro = document.getElementById('filtroCategoriaProduto').value;

    const filtrados = produtosCache.filter(produto => {
        const nomeMatch = !textoBusca || produto.nome.toLowerCase().includes(textoBusca);
        const categoriaMatch = categoriaFiltro === 'todos' || produto.categoria === categoriaFiltro;
        return nomeMatch && categoriaMatch;
    });

    const porCategoria = {
        bebidas: filtrados.filter(p => p.categoria === 'bebidas'),
        espetos: filtrados.filter(p => p.categoria === 'espetos'),
        hamburgers: filtrados.filter(p => p.categoria === 'hamburgers'),
        outros: filtrados.filter(p => p.categoria === 'outros')
    };

    renderCategoria('listaBebidas', porCategoria.bebidas, 'Nenhuma bebida cadastrada.');
    renderCategoria('listaEspetos', porCategoria.espetos, 'Nenhum espeto cadastrado.');
    renderCategoria('listaHamburgers', porCategoria.hamburgers, 'Nenhum hamburger cadastrado.');
    renderCategoria('listaOutros', porCategoria.outros, 'Nenhum produto em outros.');
}

function renderCategoria(elementId, produtos, emptyText) {
    const container = document.getElementById(elementId);

    if (!produtos.length) {
        container.innerHTML = `<p class="empty">${emptyText}</p>`;
        return;
    }

    container.innerHTML = produtos.map(produto => {
        const categoriaLabel = {
            bebidas: 'BEBIDA',
            espetos: 'ESPETO',
            hamburgers: 'HAMBURGER',
            outros: 'OUTROS'
        }[produto.categoria] || 'PRODUTO';

        return `
            <div class="produto-item produto-card" style="border-left: 4px solid #ffc107;">
                <div class="comanda-card-header">
                    <div>
                        <div class="nome">${escapeHtml(produto.nome)}</div>
                        <p style="margin-top: 4px; font-size: 0.82rem;">Categoria: ${categoriaLabel}</p>
                    </div>
                    <div class="comanda-card-right">
                        <div class="preco">R$ ${Number(produto.preco).toFixed(2)}</div>
                    </div>
                </div>
                <div class="comanda-card-actions" style="margin-top: 6px;">
                    <button class="btn btn-warning btn-small" type="button" data-produto-action="editar" data-produto-id="${produto.id}">Editar</button>
                    <button class="btn btn-danger btn-small" type="button" data-produto-action="excluir" data-produto-id="${produto.id}">Excluir</button>
                </div>
            </div>
        `;
    }).join('');
}

function abrirModalEdicao(id) {
    const produto = produtosCache.find(p => p.id == id);
    if (!produto) return;

    document.getElementById('editarProdutoId').value = produto.id;
    document.getElementById('editarNomeProduto').value = produto.nome;
    document.getElementById('editarCategoriaProduto').value = produto.categoria;
    document.getElementById('editarPrecoProduto').value = Number(produto.preco).toFixed(2);
    document.getElementById('modalEditarProduto').style.display = 'flex';
}

function fecharModalEdicao() {
    document.getElementById('modalEditarProduto').style.display = 'none';
}

async function salvarEdicaoProduto(event) {
    event.preventDefault();

    const id = Number(document.getElementById('editarProdutoId').value);
    const nome = document.getElementById('editarNomeProduto').value.trim();
    const categoria = document.getElementById('editarCategoriaProduto').value;
    const preco = parseFloat(document.getElementById('editarPrecoProduto').value);

    if (!id || !nome || !categoria || isNaN(preco) || preco < 0) {
        Toast.warning('Preencha os dados corretamente para editar.');
        return;
    }

    try {
        await Storage.updateProduto({ id, nome, categoria, preco });
        fecharModalEdicao();
        await carregarProdutos();
        Toast.success('Produto atualizado com sucesso!');
    } catch (error) {
        Toast.error('Erro ao atualizar produto: ' + error.message);
    }
}

async function excluirProduto(id) {
    if (!confirm('Tem certeza que deseja excluir este produto?')) return;

    try {
        await Storage.deleteProduto(id);
        await carregarProdutos();
        Toast.success('Produto excluído com sucesso!');
    } catch (error) {
        Toast.error('Erro ao excluir produto: ' + error.message);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}
