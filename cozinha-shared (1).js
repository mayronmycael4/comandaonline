/* ===============================================
   COZINHA — Lógica compartilhada (mobile + desktop)
   =============================================== */

const CozinhaModule = (() => {
    'use strict';

    const POLL_MS       = 8000;    // re-consulta a cada 8 s
    const SOM_KEY       = 'cozinha_som';
    const CONHECIDOS_KEY = 'cozinha_ids_conhecidos';
    const LIMPAR_KEY    = 'cozinha_limpa_em';
    const PRIORIDADE = { nova: 8, media: 15 };  // minutos

    let _listaPedidos = [];
    let _somAtivo     = localStorage.getItem(SOM_KEY) !== 'off';
    let _pollTimer    = null;
    let _audioCtx     = null;
    let _audioCustomUrl = '';
    let _audioCustomObj = null;
    let _viewMode     = 'pendente';   // 'pendente' | 'todos'
    let _groupMode    = 'comanda';    // 'comanda' | 'categoria'
    let _callbacks    = {};

    function _carregarPreferenciaSom() {
        if (typeof Storage !== 'undefined' && Storage && typeof Storage.getCozinhaSoundConfig === 'function') {
            const cfg = Storage.getCozinhaSoundConfig();
            _somAtivo = cfg.enabled !== false;
            _audioCustomUrl = cfg.audioDataUrl || '';
            _audioCustomObj = _audioCustomUrl ? new Audio(_audioCustomUrl) : null;
            return;
        }
        _somAtivo = localStorage.getItem(SOM_KEY) !== 'off';
        _audioCustomUrl = '';
        _audioCustomObj = null;
    }

    function _salvarPreferenciaSom() {
        if (typeof Storage !== 'undefined' && Storage && typeof Storage.setCozinhaSoundConfig === 'function') {
            Storage.setCozinhaSoundConfig({
                enabled: _somAtivo,
                audioDataUrl: _audioCustomUrl
            });
            return;
        }
        localStorage.setItem(SOM_KEY, _somAtivo ? 'on' : 'off');
    }

    // ---- Audio (sininho suave, max ~1.5s) ----
    function _beep() {
        if (!_somAtivo) return;

        if (_audioCustomObj) {
            try {
                _audioCustomObj.currentTime = 0;
                _audioCustomObj.play().catch(() => {
                    _audioCustomObj = null;
                });
                return;
            } catch (_customErr) {
                _audioCustomObj = null;
            }
        }

        try {
            if (!_audioCtx) _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const ctx = _audioCtx;
            // Dois tons: C6 (1047Hz) + E6 (1319Hz) — acorde de sino, decay suave
            [[1047, 0, 1.4], [1319, 0.03, 1.2]].forEach(([freq, start, dur]) => {
                const osc  = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.setValueAtTime(freq, ctx.currentTime + start);
                gain.gain.setValueAtTime(0.001, ctx.currentTime + start);
                gain.gain.linearRampToValueAtTime(0.22, ctx.currentTime + start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + start + dur);
                osc.start(ctx.currentTime + start);
                osc.stop(ctx.currentTime + start + dur + 0.05);
            });
        } catch (_e) { /* navegador sem AudioContext */ }
    }

    function _toggleSom(btnEl) {
        _somAtivo = !_somAtivo;
        _salvarPreferenciaSom();
        if (btnEl) {
            btnEl.textContent  = _somAtivo ? '🔔' : '🔕';
            btnEl.title        = _somAtivo ? 'Som ativado' : 'Som desativado';
            btnEl.classList.toggle('muted', !_somAtivo);
        }
    }

    function _initSomBtn(btnEl) {
        if (!btnEl) return;
        _carregarPreferenciaSom();
        const estado = _somAtivo;
        btnEl.textContent = estado ? '🔔' : '🔕';
        btnEl.title       = estado ? 'Som ativado' : 'Som desativado';
        btnEl.classList.toggle('muted', !estado);
        btnEl.addEventListener('click', () => _toggleSom(btnEl));
    }

    // ---- API ----
    async function _fetchPedidos() {
        const url = _viewMode === 'todos'
            ? 'cozinha.php?todos=1'
            : 'cozinha.php';
        const clientBefore = Date.now();
        const resp = await fetch(url, { cache: 'no-store' });
        if (!resp.ok) throw new Error('Erro ao buscar pedidos da cozinha');
        const data = await resp.json();
        const clientAfter = Date.now();

        // Se o servidor retornar server_time (Unix ms), calibrar o offset.
        if (data && typeof data.server_time_ms === 'number') {
            // Estimativa do horário do servidor no momento da resposta
            const clientMid = Math.round((clientBefore + clientAfter) / 2);
            _serverClientOffsetMs = clientMid - data.server_time_ms;
        }

        return Array.isArray(data) ? data : (data.pedidos || []);
    }

    async function marcarItemPronto(itemId, comandaId) {
        const resp = await fetch('cozinha.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ item_id: itemId, comanda_id: comandaId || undefined })
        });
        if (!resp.ok) throw new Error('Erro ao marcar item como pronto');
        return resp.json();
    }

    async function marcarComandaPronta(comandaId) {
        const resp = await fetch('cozinha.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ comanda_id: comandaId })
        });
        if (!resp.ok) throw new Error('Erro ao marcar comanda como pronta');
        return resp.json();
    }

    // ---- Prioridade ----
    // Offset entre relógio do servidor e relógio do cliente (ms).
    // Positivo = cliente adiantado; negativo = cliente atrasado.
    let _serverClientOffsetMs = 0;

    function _parseServerDate(isoStr) {
        if (!isoStr) return null;
        // Parse manual para garantir interpretação como hora LOCAL em TODOS os browsers.
        // new Date("YYYY-MM-DDTHH:MM:SS") sem fuso é tratado como UTC pelo
        // Safari mobile (iOS), causando erro de +240 min em fuso UTC-4.
        // new Date(ano, mes, dia, h, m, s) cria SEMPRE hora local, em qualquer browser.
        const m = String(isoStr).match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})/);
        if (!m) return null;
        const d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]);
        return isNaN(d.getTime()) ? null : d;
    }

    function _calcMinutos(isoStr) {
        if (!isoStr) return 0;
        const d = _parseServerDate(isoStr);
        if (!d) return 0;
        // Usa o horário do cliente ajustado pelo offset do servidor
        const agoraAjustado = Date.now() - _serverClientOffsetMs;
        return Math.max(0, Math.floor((agoraAjustado - d.getTime()) / 60000));
    }

    function _classePrioridade(minutos) {
        if (minutos < PRIORIDADE.nova)  return 'prioridade-nova';
        if (minutos < PRIORIDADE.media) return 'prioridade-media';
        return 'prioridade-alta';
    }

    function _textoTimer(minutos) {
        if (minutos < 1) return '⏱️ Agora';
        return `⏱️ ${minutos} min`;
    }

    function _getGrupoReferenciaTempo(grupo) {
        const itensAtivos = (grupo.itens || []).filter((item) => item.kitchen_status !== 'pronto');
        const itensComHora = itensAtivos.filter((item) => item.item_criado_em);

        if (itensComHora.length > 0) {
            return itensComHora.reduce((maisAntigo, item) => {
                if (!maisAntigo) return item.item_criado_em;
                return (_parseServerDate(item.item_criado_em) || new Date(0)) < (_parseServerDate(maisAntigo) || new Date(0)) ? item.item_criado_em : maisAntigo;
            }, null);
        }

        return grupo.comanda_criada_em;
    }

    // ---- Detecção de novos pedidos ----
    function _detectarNovos(novosPedidos) {
        const anteriores = new Set(JSON.parse(localStorage.getItem(CONHECIDOS_KEY) || '[]'));
        const novosIds   = [];
        let temNovoPendente = false;

        novosPedidos.forEach(grupo => {
            grupo.itens.forEach(item => {
                const chave = `${item.item_id}:${item.kitchen_status}`;
                if ((item.kitchen_status === 'pendente' || item.kitchen_status === 'cancelado') && !anteriores.has(chave)) {
                    novosIds.push(item.item_id);
                    if (item.kitchen_status === 'pendente') {
                        temNovoPendente = true;
                    }
                }
            });
        });

        if (temNovoPendente) {
            _beep();
            const todos = [
                ...anteriores,
                ...novosPedidos.flatMap(grupo => grupo.itens.map(item => `${item.item_id}:${item.kitchen_status}`))
            ];
            localStorage.setItem(CONHECIDOS_KEY, JSON.stringify(todos));
        }

        const todosAtuais = new Set();
        novosPedidos.forEach(g => g.itens.forEach(i => todosAtuais.add(`${i.item_id}:${i.kitchen_status}`)));
        localStorage.setItem(CONHECIDOS_KEY, JSON.stringify([...todosAtuais]));

        return novosIds;
    }

    // ---- Garçom notification ----
    function _notificarGarcom(comandaId, mesa) {
        const msg = `Mesa ${mesa} pronta. Notifique o garçom.`;
        if (typeof Toast !== 'undefined' && Toast && typeof Toast.info === 'function') {
            Toast.info(msg, 6000);
            return;
        }
        if (typeof showToast === 'function') {
            showToast(`🔔 ${msg}`, 'success', 6000);
        }
    }

    // ---- Render helper ----
    function _renderItem(item, comandaId, numeroMesa) {
        const statusClass = item.kitchen_status === 'pronto'
            ? 'item-pronto'
            : (item.kitchen_status === 'cancelado' ? 'item-cancelado' : 'item-pendente');
        const statusLabel = item.kitchen_status === 'pronto'
            ? '✔ Pronto'
            : (item.kitchen_status === 'cancelado' ? 'Cancelado' : 'Pendente');
        const obs = item.observacoes
            ? `<div class="cozinha-item-obs">⚠️ ${_esc(item.observacoes)}</div>`
            : '';
        const acaoItem = item.kitchen_status === 'pendente'
            ? `<button type="button" class="btn-pronto-item"
                  data-item-id="${item.item_id}"
                  data-comanda-id="${comandaId || ''}"
                  data-mesa="${_esc(String(numeroMesa || '?'))}"
                  title="Marcar item como pronto">✔</button>`
            : `<span class="cozinha-item-status ${statusClass}">${statusLabel}</span>`;
        return `
            <li class="cozinha-item" data-item-id="${item.item_id}">
                <span class="cozinha-item-qty">${item.quantidade}×</span>
                <div class="cozinha-item-info">
                    <div class="cozinha-item-nome">${_esc(item.nome_item)}</div>
                    ${obs}
                </div>
                ${acaoItem}
            </li>
        `;
    }

    function _renderCard(grupo, isNovo) {
        const minutos  = _calcMinutos(_getGrupoReferenciaTempo(grupo));
        const classe   = _classePrioridade(minutos);
        const timer    = _textoTimer(minutos);
        const temPendente = grupo.itens.some(i => i.kitchen_status === 'pendente');
        const temCancelado = grupo.comanda_status === 'cancelada' || grupo.itens.some(i => i.kitchen_status === 'cancelado');
        const novoClass   = isNovo ? ' novo' : '';
        const canceladoClass = temCancelado ? ' pedido-cancelado' : '';
        const itensHTML   = grupo.itens.map(i => _renderItem(i, grupo.comanda_id, grupo.numero_mesa)).join('');
        const acoes = temCancelado ? `
            <div class="cozinha-card-cancelada">🚫 Pedido cancelado. Não preparar estes itens.</div>
        ` : temPendente ? `
            <div class="cozinha-card-actions">
                <button type="button" class="btn-pronto-comanda" data-comanda-id="${grupo.comanda_id}">
                    ✔ Tudo Pronto (Mesa ${_esc(String(grupo.numero_mesa))})
                </button>
            </div>
        ` : `
            <div class="cozinha-card-done">✅ Todos os itens prontos</div>
            <div class="cozinha-garcom-alerta">🔔 Aguardando garçom</div>
        `;

        const garcom = grupo.funcionario_nome ? `<small>Garçom: ${_esc(grupo.funcionario_nome)}</small>` : '';
        const cliente = grupo.cliente_nome ? `<small>Cliente: ${_esc(grupo.cliente_nome)}</small>` : '';

        return `
            <div class="cozinha-card ${classe}${novoClass}${canceladoClass}" data-comanda-id="${grupo.comanda_id}">
                <div class="cozinha-card-header">
                    <div class="cozinha-mesa">
                        Mesa ${_esc(String(grupo.numero_mesa))}
                        ${garcom}
                        ${cliente}
                    </div>
                    <span class="cozinha-timer-badge">${timer}</span>
                </div>
                <ul class="cozinha-itens-lista">${itensHTML}</ul>
                ${acoes}
            </div>
        `;
    }

    function _renderPorCategoria(todosGrupos) {
        const mapa = {};
        todosGrupos.forEach(grupo => {
            grupo.itens.forEach(item => {
                const cat = item.categoria || 'Outros';
                if (!mapa[cat]) mapa[cat] = [];
                mapa[cat].push({ ...item, _mesa: grupo.numero_mesa, _cid: grupo.comanda_id });
            });
        });

        let html = '';
        Object.keys(mapa).sort().forEach(cat => {
            html += `<div class="cozinha-separador-categoria">${_esc(cat)}</div>`;
            mapa[cat].forEach(item => {
                const obs   = item.observacoes ? `<div class="cozinha-item-obs">⚠️ ${_esc(item.observacoes)}</div>` : '';
                const st    = item.kitchen_status === 'pronto' ? 'item-pronto' : (item.kitchen_status === 'cancelado' ? 'item-cancelado' : 'item-pendente');
                const stLbl = item.kitchen_status === 'pronto' ? '✔ Pronto' : (item.kitchen_status === 'cancelado' ? 'Cancelado' : 'Pendente');
                const canceladoClass = item.kitchen_status === 'cancelado' ? ' pedido-cancelado' : '';
                html += `
                    <div class="cozinha-card prioridade-nova${canceladoClass}">
                        <div class="cozinha-card-header">
                            <div class="cozinha-mesa">${item.quantidade}× ${_esc(item.nome_item)}<small>Mesa ${_esc(String(item._mesa))}</small></div>
                            <span class="cozinha-item-status ${st}">${stLbl}</span>
                        </div>
                        ${obs}
                        ${item.kitchen_status === 'cancelado' ? `
                            <div class="cozinha-card-cancelada" style="margin-top:10px">🚫 Item cancelado. Não preparar.</div>
                        ` : item.kitchen_status === 'pendente' ? `
                            <div class="cozinha-card-actions" style="margin-top:10px">
                                <button type="button" class="btn-pronto-item" data-item-id="${item.item_id}" data-comanda-id="${item._cid}">✔ Pronto</button>
                            </div>
                        ` : ''}
                    </div>`;
            });
        });
        return html;
    }

    function _esc(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ---- Render principal ----
    function limparPainel(btnEl) {
        const agora = Date.now();
        localStorage.setItem(LIMPAR_KEY, String(agora));
        if (btnEl) {
            btnEl.textContent = '✅ Limpo!';
            setTimeout(() => { if (btnEl) btnEl.textContent = '🧹 Limpar cozinha'; }, 2000);
        }
        if (_callbacks.onUpdate) _callbacks.onUpdate();
    }

    function _filtrarPorLimpeza(pedidos) {
        const limpezaTs = parseInt(localStorage.getItem(LIMPAR_KEY) || '0', 10);
        if (!limpezaTs) return pedidos;
        // Mantém apenas pedidos que chegaram DEPOIS do último "limpar"
        // Ou que ainda têm itens pendentes (não esconde trabalho não feito)
        return pedidos.filter(grupo => {
            const temPendente = grupo.itens.some(i => i.kitchen_status === 'pendente');
            if (temPendente) return true; // sempre mostra pedidos com itens pendentes
            const d = _parseServerDate(grupo.comanda_criada_em);
            const ts = d ? d.getTime() : 0;
            return ts > limpezaTs;
        });
    }

    function render(container, pedidos, novosIds) {
        if (!container) return;
        const novosSet = new Set(novosIds || []);
        const pedidosFiltrados = _filtrarPorLimpeza(pedidos);
        let html = '';

        if (pedidosFiltrados.length === 0) {
            html = `<div class="cozinha-empty"><span>🍽️</span><p>Nenhum pedido pendente no momento.</p></div>`;
            container.innerHTML = html;
            return;
        }

        if (_groupMode === 'categoria') {
            html = _renderPorCategoria(pedidosFiltrados);
        } else {
            pedidosFiltrados.forEach(grupo => {
                const isNovo = grupo.itens.some(i => novosSet.has(i.item_id));
                html += _renderCard(grupo, isNovo);
            });
        }

        container.innerHTML = html;

        // Botões pronto-comanda
        container.querySelectorAll('[data-comanda-id]').forEach(btn => {
            if (!btn.classList.contains('btn-pronto-comanda')) return;
            const mesaAttr = btn.textContent.match(/Mesa (\S+)/)?.[1] || '?';
            btn.addEventListener('click', async () => {
                const cid = parseInt(btn.getAttribute('data-comanda-id'), 10);
                btn.disabled = true;
                btn.textContent = '⏳ Marcando…';
                try {
                    await marcarComandaPronta(cid);
                    if (_callbacks.onUpdate) await _callbacks.onUpdate();
                    _notificarGarcom(cid, mesaAttr);
                } catch (e) {
                    btn.disabled = false;
                    btn.textContent = '✔ Tudo Pronto';
                }
            });
        });

        // Botões pronto-item
        container.querySelectorAll('.btn-pronto-item').forEach(btn => {
            btn.addEventListener('click', async () => {
                const iid  = parseInt(btn.getAttribute('data-item-id'), 10);
                const cid  = parseInt(btn.getAttribute('data-comanda-id'), 10);
                const mesa = btn.getAttribute('data-mesa');
                btn.disabled = true;
                btn.textContent = '⏳';
                try {
                    await marcarItemPronto(iid);
                    if (_callbacks.onUpdate) await _callbacks.onUpdate();
                    // Notificar garçom se todos os itens da comanda estão prontos
                    const grupoAtual = _listaPedidos.find(g => g.comanda_id === cid);
                    if (!grupoAtual || grupoAtual.itens.every(i => i.kitchen_status === 'pronto')) {
                        _notificarGarcom(cid, mesa);
                    }
                } catch (e) {
                    btn.disabled = false;
                    btn.textContent = '✔';
                }
            });
        });
    }

    // ---- Contador ----
    function atualizarContador(contadorEl, pedidos) {
        if (!contadorEl) return;
        const totalPendentes = pedidos.reduce((sum, g) => sum + g.itens.filter(i => i.kitchen_status === 'pendente').length, 0);
        const totalCancelados = pedidos.reduce((sum, g) => sum + g.itens.filter(i => i.kitchen_status === 'cancelado').length, 0);
        if (totalPendentes === 0 && totalCancelados === 0) {
            contadorEl.textContent = 'Nenhum pedido pendente';
            return;
        }
        const partes = [];
        if (totalPendentes > 0) partes.push(`${totalPendentes} ${totalPendentes === 1 ? 'item pendente' : 'itens pendentes'}`);
        if (totalCancelados > 0) partes.push(`${totalCancelados} ${totalCancelados === 1 ? 'cancelamento' : 'cancelamentos'}`);
        contadorEl.textContent = `${partes.join(' e ')} em ${pedidos.length} ${pedidos.length === 1 ? 'mesa' : 'mesas'}`;
    }

    function atualizarTimestamp(el) {
        if (!el) return;
        const now = new Date();
        el.textContent = `Atualizado ${now.getHours().toString().padStart(2,'0')}:${now.getMinutes().toString().padStart(2,'0')}:${now.getSeconds().toString().padStart(2,'0')}`;
    }

    async function marcarPendentesVisiveisProntos() {
        const grupos = _filtrarPorLimpeza(_listaPedidos || []);
        const pendentes = [];
        grupos.forEach((g) => {
            (g.itens || []).forEach((item) => {
                if (item.kitchen_status === 'pendente') {
                    pendentes.push({
                        itemId: Number(item.item_id),
                        comandaId: Number(g.comanda_id),
                        mesa: g.numero_mesa
                    });
                }
            });
        });

        if (pendentes.length === 0) {
            return { success: true, atualizados: 0 };
        }

        for (const p of pendentes) {
            await marcarItemPronto(p.itemId, p.comandaId);
        }

        if (_callbacks.onUpdate) {
            await _callbacks.onUpdate();
        }

        return { success: true, atualizados: pendentes.length };
    }

    // ---- Toolbar chips ----
    function bindToolbar(container, onUpdate) {
        if (!container) return;
        container.querySelectorAll('[data-cozinha-view]').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-cozinha-view') === _viewMode);
            btn.addEventListener('click', () => {
                _viewMode = btn.getAttribute('data-cozinha-view');
                container.querySelectorAll('[data-cozinha-view]').forEach(b => b.classList.toggle('active', b === btn));
                if (onUpdate) onUpdate();
            });
        });
        container.querySelectorAll('[data-cozinha-group]').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-cozinha-group') === _groupMode);
            btn.addEventListener('click', () => {
                _groupMode = btn.getAttribute('data-cozinha-group');
                container.querySelectorAll('[data-cozinha-group]').forEach(b => b.classList.toggle('active', b === btn));
                if (onUpdate) onUpdate();
            });
        });
    }

    // ---- Poll principal ----
    function startPolling(opts) {
        const { listContainer, contadorEl, timestampEl, somBtn, toolbarEl, onError } = opts;
        _callbacks.onUpdate = poll;

        _initSomBtn(somBtn);
        bindToolbar(toolbarEl, poll);

        async function poll() {
            try {
                const pedidos = await _fetchPedidos();
                const novosIds = _detectarNovos(pedidos);
                _listaPedidos = pedidos;
                render(listContainer, pedidos, novosIds);
                atualizarContador(contadorEl, pedidos);
                atualizarTimestamp(timestampEl);
            } catch (err) {
                if (onError) onError(err);
            } finally {
                clearTimeout(_pollTimer);
                _pollTimer = setTimeout(poll, POLL_MS);
            }
        }

        poll();
    }

    return { startPolling, marcarItemPronto, marcarComandaPronta, marcarPendentesVisiveisProntos, limparPainel };
})();
