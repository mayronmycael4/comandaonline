const ComandaModule = (() => {
    let outsideClickBound = false;

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function formatDateTime(date) {
        if (!date) return 'N/A';
        const parsedDate = new Date(date);
        return parsedDate.toLocaleDateString('pt-BR') + ' ' + parsedDate.toLocaleTimeString('pt-BR');
    }

    function getTotal(comanda) {
        return comanda.itens
            ? comanda.itens.reduce((sum, item) => sum + (Number(item.quantidade) * Number(item.valor)), 0)
            : 0;
    }

    function buildItensHtml(comanda) {
        if (!comanda.itens || comanda.itens.length === 0) {
            return '<p class="empty" style="margin: 0;">Nenhum item adicionado</p>';
        }

        return comanda.itens.map(item => `
            <div class="comanda-item-row">
                <span>${escapeHtml(item.nome)}</span>
                <span>${Number(item.quantidade)} x R$ ${Number(item.valor).toFixed(2)}</span>
            </div>
        `).join('');
    }

    function closeAllMenus() {
        document.querySelectorAll('.comanda-actions-dropdown.is-open').forEach(menu => {
            menu.classList.remove('is-open');
        });
    }

    function ensureOutsideClickBinding() {
        if (outsideClickBound) return;

        document.addEventListener('click', (event) => {
            if (event.target.closest('.comanda-actions')) return;
            closeAllMenus();
        });

        outsideClickBound = true;
    }

    function buildActionMenu(comanda, options = {}) {
        const status = comanda.status === 'cancelada' ? 'cancelada' : (comanda.status === 'fechada' ? 'fechada' : 'aberta');
        const items = [
            `<button class="comanda-action-item" type="button" data-action="abrir" data-comanda-id="${comanda.id}">${status === 'aberta' ? 'Abrir / Editar' : 'Abrir / Ver'}</button>`
        ];

        if (options.allowPrint !== false) {
            items.push(`<button class="comanda-action-item" type="button" data-action="imprimir" data-comanda-id="${comanda.id}">Imprimir comanda</button>`);
        }

        if (options.allowToggleStatus !== false) {
            if (status === 'aberta') {
                items.push(`<button class="comanda-action-item" type="button" data-action="fechar" data-comanda-id="${comanda.id}">Fechar comanda</button>`);
            } else if (status === 'fechada') {
                items.push(`<button class="comanda-action-item" type="button" data-action="reabrir" data-comanda-id="${comanda.id}">Reabrir comanda</button>`);
            }
        }

        if (options.allowDelete && status === 'aberta') {
            items.push(`<button class="comanda-action-item danger" type="button" data-action="excluir" data-comanda-id="${comanda.id}">Cancelar comanda</button>`);
        }

        return `
            <div class="comanda-actions" data-no-toggle="true">
                <button class="comanda-actions-trigger" type="button" aria-label="Ações da comanda" data-action="toggle-menu" data-comanda-id="${comanda.id}">⋮</button>
                <div class="comanda-actions-dropdown" data-menu-for="${comanda.id}">
                    ${items.join('')}
                </div>
            </div>
        `;
    }

    function buildCard(comanda, options = {}) {
        const total = getTotal(comanda);
        const responsavel = comanda.funcionarioNome || 'Desconhecido';
            const cliente = comanda.clienteNome || (comanda.cliente && comanda.cliente.nome) || 'Não informado';
        const status = comanda.status === 'cancelada' ? 'cancelada' : (comanda.status === 'fechada' ? 'fechada' : 'aberta');
        const statusLabel = status === 'aberta' ? 'ABERTA' : (status === 'fechada' ? 'FECHADA' : 'CANCELADA');
        const actionLabel = options.actionLabel || (status === 'aberta' ? 'Abrir/Editar' : 'Abrir/Ver');
        const accentColor = status === 'aberta' ? '#48bb78' : (status === 'fechada' ? '#e53e3e' : '#d69e2e');
        const compact = options.layout === 'compact';

        if (compact) {
            return `
                <div class="lista-item historico-detalhado comanda-card" data-comanda-id="${comanda.id}" style="cursor: pointer; margin-bottom: 10px; padding: 15px; border-left: 4px solid ${accentColor};">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h4 style="margin: 0 0 5px 0; color: #667eea;">Mesa ${escapeHtml(comanda.numeroMesa)}</h4>
                            <p style="margin: 0; font-size: 0.9rem; color: #666;">Responsável: ${escapeHtml(responsavel)}</p>
                            <p style="margin: 5px 0 0 0; font-size: 0.85rem; color: #888;">Cliente: ${escapeHtml(cliente)}</p>
                        </div>
                        <div style="text-align: right;">
                            ${buildActionMenu(comanda, options)}
                            <span class="status-${status}" style="font-size: 0.75rem;">${statusLabel}</span>
                            <p style="margin: 5px 0 0 0; font-weight: bold; color: #333;">R$ ${total.toFixed(2)}</p>
                        </div>
                    </div>
                    <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; font-size: 0.8rem; color: #999;">
                        ${comanda.itens ? comanda.itens.length : 0} itens • Criada em: ${formatDateTime(comanda.createdAt)}
                    </div>
                    <div class="comanda-card-body">
                        <div class="comanda-card-actions">
                            <button class="btn btn-primary btn-small" type="button" data-action="abrir" data-comanda-id="${comanda.id}">${actionLabel}</button>
                        </div>
                    </div>
                </div>
            `;
        }

        return `
            <div class="lista-item comanda-card" data-comanda-id="${comanda.id}" style="cursor: pointer; border-left: 4px solid ${accentColor}; ${status !== 'aberta' ? 'opacity: 0.9;' : ''}">
                <div class="comanda-card-header">
                    <div>
                        <h3>Mesa ${escapeHtml(comanda.numeroMesa)}</h3>
                        <p>Responsável: ${escapeHtml(responsavel)}</p>
                        <p>Cliente: ${escapeHtml(cliente)}</p>
                    </div>
                    <div class="comanda-card-right">
                        ${buildActionMenu(comanda, options)}
                        <span class="status-${status}" style="font-size: 0.8rem;">${statusLabel}</span>
                        <div class="meta">
                            ${comanda.itens ? comanda.itens.length : 0} itens • Total: R$ ${total.toFixed(2)}
                            <br>Criada em: ${formatDateTime(comanda.createdAt)}
                        </div>
                    </div>
                </div>
                <div class="comanda-card-body">
                    <div class="comanda-details-grid">
                        <div>
                            <strong>Observações</strong>
                            <div class="comanda-details-box">${escapeHtml(comanda.observacoes || '-')}</div>
                        </div>
                        <div>
                            <strong>Itens</strong>
                            <div class="comanda-details-box">${buildItensHtml(comanda)}</div>
                        </div>
                    </div>
                    <div class="comanda-card-actions">
                        <button class="btn btn-primary btn-small" type="button" data-action="abrir" data-comanda-id="${comanda.id}">${actionLabel}</button>
                    </div>
                </div>
            </div>
        `;
    }

    function renderCards(container, comandas, options = {}) {
        if (!container) return;

        if (!comandas || comandas.length === 0) {
            container.innerHTML = `<p class="empty">${options.emptyMessage || 'Nenhuma comanda encontrada.'}</p>`;
            return;
        }

        const sorted = [...comandas].sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));
        const limited = typeof options.maxItems === 'number' ? sorted.slice(0, options.maxItems) : sorted;
        container.innerHTML = limited.map(comanda => buildCard(comanda, options)).join('');
    }

    function buildFuncionarioTags(comandas) {
        if (!comandas || comandas.length === 0) {
            return '<span style="color: #999;">Nenhuma comanda</span>';
        }

        return comandas.map(comanda => `
            <span class="comanda-tag ${comanda.status}" data-action="abrir" data-comanda-id="${comanda.id}">
                Mesa ${escapeHtml(comanda.numeroMesa)}
            </span>
        `).join('');
    }

    function openComanda(id) {
        window.location.href = `comanda.html?comandaId=${id}`;
    }

    function printComanda(id) {
        const target = `comanda.html?comandaId=${id}&print=1`;
        window.open(target, '_blank');
    }

    function bindContainer(container, options = {}) {
        if (!container || container.dataset.comandaModuleBound === '1') return;

        ensureOutsideClickBinding();

        container.addEventListener('click', async (event) => {
            const actionEl = event.target.closest('[data-action][data-comanda-id]');
            if (actionEl) {
                const action = actionEl.getAttribute('data-action');
                const id = actionEl.getAttribute('data-comanda-id');
                if (!id) return;

                const dropdown = actionEl.closest('.comanda-actions-dropdown');
                if (action !== 'toggle-menu' && dropdown) {
                    dropdown.classList.remove('is-open');
                }

                if (action === 'toggle-menu') {
                    event.preventDefault();
                    event.stopPropagation();
                    const wrapper = actionEl.closest('.comanda-actions');
                    const menu = wrapper ? wrapper.querySelector('.comanda-actions-dropdown') : null;
                    if (!menu) return;
                    const willOpen = !menu.classList.contains('is-open');
                    closeAllMenus();
                    if (willOpen) {
                        menu.classList.add('is-open');
                    }
                    return;
                }

                if (action === 'abrir') {
                    if (typeof options.onOpen === 'function') {
                        options.onOpen(id);
                    } else {
                        openComanda(id);
                    }
                }

                if (action === 'imprimir') {
                    if (typeof options.onPrint === 'function') {
                        options.onPrint(id);
                    } else {
                        printComanda(id);
                    }
                }

                if (action === 'fechar') {
                    if (typeof options.onClose === 'function') {
                        await options.onClose(id);
                    }
                }

                if (action === 'reabrir') {
                    if (typeof options.onReopen === 'function') {
                        await options.onReopen(id);
                    }
                }

                if (action === 'excluir') {
                    if (typeof options.onDelete === 'function') {
                        await options.onDelete(id);
                    }
                }
                return;
            }

            if (options.expandOnCardClick === false) return;

            if (event.target.closest('[data-no-toggle="true"]')) return;

            const card = event.target.closest('.comanda-card[data-comanda-id]');
            if (!card) return;
            card.classList.toggle('is-expanded');
        });

        container.dataset.comandaModuleBound = '1';
    }

    return {
        bindContainer,
        buildFuncionarioTags,
        escapeHtml,
        formatDateTime,
        openComanda,
        printComanda,
        renderCards
    };
})();

window.ComandaModule = ComandaModule;