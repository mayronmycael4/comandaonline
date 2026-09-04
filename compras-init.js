(function () {
    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtMoney(v) {
        const n = Number(v || 0);
        return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function priorityClass(v) {
        return String(v || 'media').toLowerCase();
    }

    function buildRow(item) {
        const qtd = Number(item.quantidade_necessaria || 0);
        const qtdMin = Number(item.quantidade_minima || 0);
        const estoqueAtual = item.estoque_atual == null ? '-' : Number(item.estoque_atual).toFixed(2);
        const custoMedio = item.custo_medio == null ? '-' : fmtMoney(item.custo_medio);
        const prioridade = priorityClass(item.prioridade);

        return `
            <article class="compra-item" data-id="${esc(item.id)}">
                <div class="compra-top">
                    <div>
                        <h3 class="compra-title">${esc(item.nome_item || 'Item sem nome')}</h3>
                        <p class="compra-meta">Estoque atual: ${esc(estoqueAtual)} | Custo médio: ${esc(custoMedio)}</p>
                    </div>
                    <span class="pill ${esc(prioridade)}">${esc(String(prioridade).toUpperCase())}</span>
                </div>
                <div class="compra-meta">Necessário: ${esc(qtd)} ${esc(item.unidade || 'un')} | Mínimo: ${esc(qtdMin)}</div>
                <div class="compra-actions">
                    <input type="number" min="0.01" step="0.01" placeholder="Qtd recebida" class="js-qtd" value="${esc(qtd || 1)}">
                    <input type="number" min="0" step="0.01" placeholder="Custo unitário" class="js-custo" value="${esc(item.custo_unitario_real || item.valor_unitario || '')}">
                    <input type="text" placeholder="Fornecedor" class="js-fornecedor" value="${esc(item.fornecedor_nome || '')}">
                    <input type="text" placeholder="Nota fiscal" class="js-nota" value="${esc(item.nota_fiscal || '')}">
                    <input type="datetime-local" class="js-recebido" value="${esc((item.recebido_em || '').replace(' ', 'T'))}">
                    <textarea class="js-obs" placeholder="Observações">${esc(item.observacoes || '')}</textarea>
                </div>
                <div class="compra-rodape">
                    <span class="muted">#${esc(item.id)}${item.status ? ` • ${esc(item.status)}` : ''}</span>
                    <div>
                        <button type="button" class="btn btn-primary js-receber">Marcar como recebido</button>
                        <button type="button" class="btn btn-light js-remover">Excluir</button>
                    </div>
                </div>
            </article>
        `;
    }

    async function carregar(pendentes = true) {
        if (typeof Storage === 'undefined' || !Storage.requireAuth()) return;
        const lista = document.getElementById('comprasLista');
        const resumo = document.getElementById('comprasResumo');
        lista.innerHTML = '<p class="empty">Carregando...</p>';

        const rows = await Storage.getListaCompras(pendentes);
        const itens = Array.isArray(rows) ? rows : [];

        if (!itens.length) {
            lista.innerHTML = '<p class="empty">Nenhum item encontrado.</p>';
            resumo.textContent = pendentes ? 'Sem itens pendentes.' : 'Sem registros para exibir.';
            return;
        }

        lista.innerHTML = itens.map(buildRow).join('');
        const totalQtd = itens.reduce((acc, item) => acc + Number(item.quantidade_necessaria || 0), 0);
        const totalAlto = itens.filter(item => String(item.prioridade || '').toLowerCase() === 'alta').length;
        resumo.innerHTML = `Itens: <strong>${itens.length}</strong> | Quantidade total: <strong>${totalQtd.toFixed(2)}</strong> | Prioridade alta: <strong>${totalAlto}</strong>`;

        lista.querySelectorAll('.compra-item').forEach(card => {
            card.querySelector('.js-receber').addEventListener('click', async () => {
                const id = Number(card.getAttribute('data-id'));
                const quantidade = Number(card.querySelector('.js-qtd').value || 0);
                const custo = Number(card.querySelector('.js-custo').value || 0);
                const fornecedor = card.querySelector('.js-fornecedor').value.trim();
                const notaFiscal = card.querySelector('.js-nota').value.trim();
                const recebidoEm = card.querySelector('.js-recebido').value;
                const observacoes = card.querySelector('.js-obs').value.trim();
                const item = itens.find(x => Number(x.id) === id) || {};

                await Storage.updateItemListaCompras({
                    id,
                    status: 'comprado',
                    estoque_id: Number(item.estoque_id || 0) || null,
                    quantidade_adicionada: quantidade,
                    custo_unitario_real: custo,
                    fornecedor_nome: fornecedor,
                    nota_fiscal: notaFiscal,
                    recebido_em: recebidoEm ? recebidoEm.replace('T', ' ') : null,
                    observacoes
                });

                if (typeof toast === 'function') toast('Recebimento registrado.');
                carregar(true).catch(console.error);
            });

            card.querySelector('.js-remover').addEventListener('click', async () => {
                const id = Number(card.getAttribute('data-id'));
                if (!confirm('Excluir este item da lista de compras?')) return;
                await Storage.deleteItemListaCompras(id);
                carregar(true).catch(console.error);
            });
        });
    }

    async function adicionarItem(ev) {
        ev.preventDefault();
        const payload = {
            estoque_id: Number(document.getElementById('estoqueId').value || 0) || null,
            nome_item: document.getElementById('nomeItem').value.trim(),
            quantidade_necessaria: Number(document.getElementById('quantidadeNecessaria').value || 0),
            quantidade_minima: Number(document.getElementById('quantidadeMinima').value || 0),
            unidade: document.getElementById('unidade').value.trim() || 'un',
            prioridade: document.getElementById('prioridade').value
        };
        await Storage.addItemListaCompras(payload);
        ev.target.reset();
        document.getElementById('quantidadeNecessaria').value = '1';
        document.getElementById('quantidadeMinima').value = '0';
        document.getElementById('unidade').value = 'un';
        document.getElementById('prioridade').value = 'media';
        carregar(true).catch(console.error);
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (typeof Storage !== 'undefined' && Storage.requireAuth()) {
            document.getElementById('btnAtualizarCompras').addEventListener('click', () => carregar(true).catch(console.error));
            document.getElementById('btnVerTodasCompras').addEventListener('click', () => carregar(false).catch(console.error));
            document.getElementById('formItemCompra').addEventListener('submit', adicionarItem);
            carregar(true).catch((e) => {
                console.error(e);
                alert('Falha ao carregar compras: ' + (e && e.message ? e.message : e));
            });
        }
    });
})();
