const params = new URLSearchParams(window.location.search);
const mesa = (params.get('mesa') || '').trim();
const token = (params.get('token') || '').trim();

const el = {
  nomeLoja: document.getElementById('nomeLoja'),
  subLoja: document.getElementById('subLoja'),
  pillMesa: document.getElementById('pillMesa'),
  pillComanda: document.getElementById('pillComanda'),
  menuCategorias: document.getElementById('menuCategorias'),
  produtosLista: document.getElementById('produtosLista'),
  abrirCarrinho: document.getElementById('abrirCarrinho'),
  cartBadge: document.getElementById('cartBadge'),
  modalProduto: document.getElementById('modalProduto'),
  modalProdutoContent: document.getElementById('modalProdutoContent'),
  modalCarrinho: document.getElementById('modalCarrinho'),
  modalCarrinhoContent: document.getElementById('modalCarrinhoContent'),
  clienteNome: document.getElementById('clienteNome'),
  clienteObs: document.getElementById('clienteObs'),
  meusPedidos: document.getElementById('meusPedidos')
};

const state = {
  comandaId: null,
  categorias: [],
  produtos: [],
  categoriaAtual: '',
  carrinho: [],
  cooldownSegundos: 0,
  pedidos: []
};

const cooldownKey = `qr_menu_last_send_${mesa}`;

function money(v) {
  return `R$ ${Number(v || 0).toFixed(2).replace('.', ',')}`;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = String(text || '');
  return div.innerHTML;
}

function showError(message) {
  document.body.innerHTML = `<div style="padding:20px;max-width:680px;margin:30px auto;background:#fff;border:1px solid #e4b1b1;border-radius:12px;color:#7b1818;font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;">${escapeHtml(message)}</div>`;
}

async function requestQrMenu(method, payload = null, action = 'init') {
  const query = method === 'GET'
    ? `?action=${encodeURIComponent(action)}&mesa=${encodeURIComponent(mesa)}&token=${encodeURIComponent(token)}`
    : '';
  const response = await fetch(`qr_menu.php${query}`, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: method === 'POST' ? JSON.stringify(payload || {}) : null,
    cache: 'no-store'
  });
  const json = await response.json();
  if (!response.ok) {
    throw new Error(json.error || 'Falha ao processar pedido no QR Menu');
  }
  return json;
}

function renderCategorias() {
  el.menuCategorias.innerHTML = state.categorias.map((c) => `
    <button class="cat-btn${c.id === state.categoriaAtual ? ' active' : ''}" data-cat="${escapeHtml(c.id)}">${escapeHtml(c.nome)}</button>
  `).join('');

  el.menuCategorias.querySelectorAll('[data-cat]').forEach((btn) => {
    btn.addEventListener('click', () => {
      state.categoriaAtual = btn.getAttribute('data-cat') || '';
      renderCategorias();
      renderProdutos();
    });
  });
}

function calcularPrecoProduto(produto, variacaoId, adicionaisIds) {
  let total = Number(produto.preco || 0);
  if (variacaoId) {
    const variacao = (produto.variacoes || []).find((v) => Number(v.id) === Number(variacaoId));
    if (variacao) total += Number(variacao.preco_delta || 0);
  }
  const adicionais = (produto.adicionais || []).filter((a) => adicionaisIds.includes(Number(a.id)));
  for (const add of adicionais) total += Number(add.preco || 0);
  return total;
}

function renderProdutos() {
  const produtosFiltrados = state.produtos.filter((p) => p.categoria === state.categoriaAtual);

  if (produtosFiltrados.length === 0) {
    el.produtosLista.innerHTML = '<div class="panel">Nenhum produto nessa categoria.</div>';
    return;
  }

  el.produtosLista.innerHTML = produtosFiltrados.map((p) => {
    const img = p.imagem_url ? escapeHtml(p.imagem_url) : 'icon-192.png';
    return `
      <article class="produto">
        <img src="${img}" alt="${escapeHtml(p.nome)}">
        <div>
          <h3>${escapeHtml(p.nome)}</h3>
          <p class="desc">${escapeHtml(p.descricao || '')}</p>
          <div class="preco">${money(p.preco)}</div>
        </div>
        <button class="btn btn-primary" data-add-prod="${p.id}">Adicionar</button>
      </article>
    `;
  }).join('');

  el.produtosLista.querySelectorAll('[data-add-prod]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const pid = Number(btn.getAttribute('data-add-prod'));
      const produto = state.produtos.find((p) => Number(p.id) === pid);
      if (!produto) return;
      abrirModalProduto(produto);
    });
  });
}

function abrirModalProduto(produto) {
  const variacoes = produto.variacoes || [];
  const adicionais = produto.adicionais || [];

  let variacaoOptions = '';
  if (variacoes.length > 0) {
    variacaoOptions = `
      <div class="field">
        <label>Variacao</label>
        <select id="produtoVariacao">
          <option value="">Sem variacao</option>
          ${variacoes.map((v) => `<option value="${v.id}" ${v.is_default ? 'selected' : ''}>${escapeHtml(v.nome)} ${Number(v.preco_delta) > 0 ? `( + ${money(v.preco_delta)} )` : ''}</option>`).join('')}
        </select>
      </div>
    `;
  }

  let adicionaisOptions = '';
  if (adicionais.length > 0) {
    adicionaisOptions = `
      <div class="field">
        <label>Adicionais</label>
        ${adicionais.map((a) => `
          <label style="display:flex;align-items:center;gap:8px;font-weight:600;">
            <input type="checkbox" value="${a.id}" data-add-opt>
            <span>${escapeHtml(a.nome)} (${money(a.preco)})</span>
          </label>
        `).join('')}
      </div>
    `;
  }

  el.modalProdutoContent.innerHTML = `
    <h2 style="margin-top:0;">${escapeHtml(produto.nome)}</h2>
    <p style="color:#7a5a3a;">${escapeHtml(produto.descricao || '')}</p>
    <div class="field">
      <label>Quantidade</label>
      <input id="produtoQtd" type="number" min="1" step="1" value="1">
    </div>
    ${variacaoOptions}
    ${adicionaisOptions}
    <div class="field">
      <label>Observacao do item (opcional)</label>
      <textarea id="produtoObs" rows="2" maxlength="255" placeholder="Ex: sem cebola"></textarea>
    </div>
    <div style="font-weight:800;margin:8px 0 12px;" id="produtoPrecoFinal">Preco: ${money(produto.preco)}</div>
    <div class="inline-actions">
      <button class="btn btn-secondary" id="btnFecharProduto">Cancelar</button>
      <button class="btn btn-primary" id="btnConfirmarProduto">Adicionar ao carrinho</button>
    </div>
  `;

  function atualizarPrecoModal() {
    const variacaoId = Number((document.getElementById('produtoVariacao') || {}).value || 0);
    const addIds = Array.from(el.modalProdutoContent.querySelectorAll('[data-add-opt]:checked')).map((n) => Number(n.value));
    const qtd = Math.max(1, Number((document.getElementById('produtoQtd') || {}).value || 1));
    const preco = calcularPrecoProduto(produto, variacaoId, addIds) * qtd;
    const precoEl = document.getElementById('produtoPrecoFinal');
    if (precoEl) precoEl.textContent = `Preco: ${money(preco)}`;
  }

  el.modalProdutoContent.querySelectorAll('input,select').forEach((node) => {
    node.addEventListener('change', atualizarPrecoModal);
    node.addEventListener('input', atualizarPrecoModal);
  });

  document.getElementById('btnFecharProduto').addEventListener('click', () => {
    el.modalProduto.classList.remove('show');
  });

  document.getElementById('btnConfirmarProduto').addEventListener('click', () => {
    const qtd = Math.max(1, Number((document.getElementById('produtoQtd') || {}).value || 1));
    const variacaoId = Number((document.getElementById('produtoVariacao') || {}).value || 0);
    const obs = String((document.getElementById('produtoObs') || {}).value || '').trim();
    const addIds = Array.from(el.modalProdutoContent.querySelectorAll('[data-add-opt]:checked')).map((n) => Number(n.value));

    const precoUnit = calcularPrecoProduto(produto, variacaoId, addIds);
    state.carrinho.push({
      produto_id: Number(produto.id),
      nome: produto.nome,
      quantidade: qtd,
      variacao_id: variacaoId || null,
      adicionais: addIds,
      observacao: obs,
      valor_unitario: precoUnit
    });

    atualizarBadgeCarrinho();
    el.modalProduto.classList.remove('show');
  });

  el.modalProduto.classList.add('show');
  atualizarPrecoModal();
}

function atualizarBadgeCarrinho() {
  const totalItens = state.carrinho.reduce((acc, i) => acc + Number(i.quantidade || 0), 0);
  el.cartBadge.textContent = String(totalItens);
}

function abrirModalCarrinho() {
  const total = state.carrinho.reduce((acc, i) => acc + (Number(i.valor_unitario) * Number(i.quantidade)), 0);

  el.modalCarrinhoContent.innerHTML = `
    <h2 style="margin-top:0;">Carrinho da Mesa</h2>
    ${state.carrinho.length === 0 ? '<p>Seu carrinho esta vazio.</p>' : ''}
    <div>
      ${state.carrinho.map((item, idx) => `
        <div style="border:1px solid rgba(139,15,20,.2);border-radius:10px;padding:10px;margin-bottom:8px;">
          <div style="font-weight:700;">${escapeHtml(item.nome)} x${item.quantidade}</div>
          <div style="font-size:.88rem;color:#7a5a3a;">${money(item.valor_unitario)} cada</div>
          ${item.observacao ? `<div style="font-size:.82rem;color:#7a5a3a;">Obs: ${escapeHtml(item.observacao)}</div>` : ''}
          <div class="inline-actions" style="margin-top:8px;">
            <button class="btn btn-secondary" data-inc-item="${idx}">+1</button>
            <button class="btn btn-secondary" data-dec-item="${idx}">-1</button>
            <button class="btn btn-secondary" data-del-item="${idx}">Remover</button>
          </div>
        </div>
      `).join('')}
    </div>
    <div style="font-weight:800;font-size:1.05rem;margin:8px 0 12px;">Total: ${money(total)}</div>
    <div class="inline-actions">
      <button class="btn btn-secondary" id="btnFecharCarrinho">Fechar</button>
      <button class="btn btn-primary" id="btnEnviarPedido" ${state.carrinho.length === 0 ? 'disabled' : ''}>Enviar Pedido</button>
    </div>
  `;

  el.modalCarrinhoContent.querySelectorAll('[data-inc-item]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const idx = Number(btn.getAttribute('data-inc-item'));
      if (!state.carrinho[idx]) return;
      state.carrinho[idx].quantidade += 1;
      atualizarBadgeCarrinho();
      abrirModalCarrinho();
    });
  });

  el.modalCarrinhoContent.querySelectorAll('[data-dec-item]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const idx = Number(btn.getAttribute('data-dec-item'));
      if (!state.carrinho[idx]) return;
      state.carrinho[idx].quantidade -= 1;
      if (state.carrinho[idx].quantidade <= 0) state.carrinho.splice(idx, 1);
      atualizarBadgeCarrinho();
      abrirModalCarrinho();
    });
  });

  el.modalCarrinhoContent.querySelectorAll('[data-del-item]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const idx = Number(btn.getAttribute('data-del-item'));
      if (!state.carrinho[idx]) return;
      state.carrinho.splice(idx, 1);
      atualizarBadgeCarrinho();
      abrirModalCarrinho();
    });
  });

  document.getElementById('btnFecharCarrinho').addEventListener('click', () => {
    el.modalCarrinho.classList.remove('show');
  });

  const btnEnviar = document.getElementById('btnEnviarPedido');
  if (btnEnviar) {
    btnEnviar.addEventListener('click', enviarPedido);
  }

  el.modalCarrinho.classList.add('show');
}

function renderPedidosMesa() {
  if (!Array.isArray(state.pedidos) || state.pedidos.length === 0) {
    el.meusPedidos.innerHTML = '<div class="pedidos-empty">Nenhum pedido enviado ainda.</div>';
    return;
  }

  el.meusPedidos.innerHTML = state.pedidos.map((pedido) => `
    <div class="pedido-item">
      <div style="font-size:.88rem;color:#7a5a3a;">
        Pedido #${pedido.id}
        <span class="status ${pedido.status}">${pedido.status.replace('_', ' ')}</span>
      </div>
      <div style="font-size:.82rem;color:#7a5a3a;margin:4px 0;">${escapeHtml(pedido.cliente_nome || '')} • ${escapeHtml(String(pedido.created_at || ''))}</div>
      <ul style="margin:0;padding-left:18px;">
        ${(pedido.itens || []).map((it) => `<li>${escapeHtml(it.produto_nome)} x${Number(it.quantidade)}</li>`).join('')}
      </ul>
    </div>
  `).join('');
}

async function carregarPedidosMesa() {
  const result = await requestQrMenu('GET', null, 'pedidos');
  state.pedidos = Array.isArray(result.pedidos) ? result.pedidos : [];
  renderPedidosMesa();
}

function segundosDesdeUltimoEnvio() {
  const raw = localStorage.getItem(cooldownKey);
  if (!raw) return 999;
  const ts = Number(raw);
  if (!ts) return 999;
  return Math.floor((Date.now() - ts) / 1000);
}

async function enviarPedido() {
  const clienteNome = (el.clienteNome.value || '').trim();
  if (!clienteNome) {
    alert('Informe o nome do cliente para enviar o pedido.');
    return;
  }

  if (state.carrinho.length === 0) {
    alert('Carrinho vazio. Adicione ao menos um item.');
    return;
  }

  const elapsed = segundosDesdeUltimoEnvio();
  if (elapsed < 45) {
    const confirmacao = window.confirm('Voce acabou de enviar um pedido. Deseja enviar outro agora?');
    if (!confirmacao) return;
  }

  const payload = {
    action: 'enviar',
    mesa,
    token,
    cliente_nome: clienteNome,
    observacao_cliente: (el.clienteObs.value || '').trim(),
    itens: state.carrinho.map((item) => ({
      produto_id: item.produto_id,
      quantidade: item.quantidade,
      variacao_id: item.variacao_id,
      adicionais: item.adicionais,
      observacao: item.observacao
    }))
  };

  try {
    const result = await requestQrMenu('POST', payload, 'enviar');
    if (result.duplicado) {
      alert('Pedido igual detectado em menos de 30 segundos. Confira em "Meus Pedidos da Mesa".');
    } else {
      alert('Pedido enviado com sucesso!');
      localStorage.setItem(cooldownKey, String(Date.now()));
      state.carrinho = [];
      atualizarBadgeCarrinho();
      el.modalCarrinho.classList.remove('show');
    }
    await carregarPedidosMesa();
  } catch (error) {
    alert(error.message || 'Erro ao enviar pedido.');
  }
}

async function iniciar() {
  if (!mesa || !token) {
    showError('URL invalida. Use um link com mesa e token do QR Code.');
    return;
  }

  try {
    const init = await requestQrMenu('GET', null, 'init');

    state.comandaId = Number(init.comanda_id || 0);
    state.categorias = Array.isArray(init.categorias) ? init.categorias : [];
    state.produtos = Array.isArray(init.produtos) ? init.produtos : [];
    state.categoriaAtual = state.categorias[0] ? state.categorias[0].id : '';
    state.cooldownSegundos = Number(init.cooldown_segundos || 0);
    state.pedidos = Array.isArray(init.pedidos) ? init.pedidos : [];

    el.nomeLoja.textContent = init.empresa_nome || 'Cardapio da Mesa';
    el.subLoja.textContent = 'Pedido direto no celular';
    el.pillMesa.textContent = `Mesa ${mesa}`;
    el.pillComanda.textContent = `Comanda ${state.comandaId || '--'}`;

    renderCategorias();
    renderProdutos();
    renderPedidosMesa();
    atualizarBadgeCarrinho();

    if (state.cooldownSegundos > 0) {
      alert(`Pedido recente detectado. Aguarde ${state.cooldownSegundos}s para novo envio automatico.`);
    }
  } catch (error) {
    showError(error.message || 'Falha ao abrir o cardapio da mesa.');
    return;
  }

  setInterval(async () => {
    try {
      await carregarPedidosMesa();
    } catch (_e) {
      // Evita quebrar a pagina por falha pontual de rede.
    }
  }, 5000);
}

el.abrirCarrinho.addEventListener('click', abrirModalCarrinho);
el.modalProduto.addEventListener('click', (e) => {
  if (e.target === el.modalProduto) el.modalProduto.classList.remove('show');
});
el.modalCarrinho.addEventListener('click', (e) => {
  if (e.target === el.modalCarrinho) el.modalCarrinho.classList.remove('show');
});

iniciar();
