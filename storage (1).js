// Storage usando MySQL via API
const Storage = {
    SESSION_KEY: 'comanda_session',
    USER_PREFS_PREFIX: 'comanda_user_prefs_',
    PEDIDO_PRONTO_NOTIF_PREFIX: 'comanda_pedido_pronto_notif_',
    SESSION_ACTIVITY_KEY: 'comanda_session_last_activity',
    SESSION_INACTIVITY_TIMEOUT_MS: 30 * 60 * 1000,
    SESSION_STATUS_CHECK_MS: 60 * 1000,
    SESSION_NOTIFICATION_CHECK_MS: 15 * 1000,
    _sessionSecurityBound: false,
    _sessionStatusTimer: null,
    _sessionIdleTimer: null,
    _notificationTimer: null,
    useMySQL: true,

    // ========== AUTENTICAÇÃO ==========
    getSession() {
        const data = localStorage.getItem(this.SESSION_KEY);
        return data ? JSON.parse(data) : null;
    },

    setSession(funcionario) {
        const permissoes = Array.isArray(funcionario.permissoes) ? funcionario.permissoes : [];
        localStorage.setItem(this.SESSION_KEY, JSON.stringify({
            funcionarioId: funcionario.id,
            nome: funcionario.nome,
            login: funcionario.login,
            isAdmin: funcionario.is_admin || false,
            permissoes,
            sessaoVersao: Number(funcionario.sessao_versao || 1),
            loggedInAt: new Date().toISOString()
        }));
        localStorage.setItem(this.SESSION_ACTIVITY_KEY, String(Date.now()));
        this.bindSessionSecurity();
    },

    clearSession() {
        localStorage.removeItem(this.SESSION_KEY);
        localStorage.removeItem(this.SESSION_ACTIVITY_KEY);
        if (this._sessionStatusTimer) {
            clearInterval(this._sessionStatusTimer);
            this._sessionStatusTimer = null;
        }
        if (this._sessionIdleTimer) {
            clearTimeout(this._sessionIdleTimer);
            this._sessionIdleTimer = null;
        }
        if (this._notificationTimer) {
            clearInterval(this._notificationTimer);
            this._notificationTimer = null;
        }
    },

    isLoggedIn() {
        return this.getSession() !== null;
    },

    requireAuth() {
        if (!this.isLoggedIn()) {
            window.location.href = 'login.html';
            return false;
        }
        this.bindSessionSecurity();
        return true;
    },

    forceLogout(message) {
        this.clearSession();
        if (typeof Toast !== 'undefined' && Toast && typeof Toast.warning === 'function' && message) {
            Toast.warning(message, 5000);
        }
        setTimeout(() => {
            if (!window.location.pathname.toLowerCase().endsWith('login.html')) {
                window.location.href = 'login.html';
            }
        }, 250);
    },

    registerActivity() {
        if (!this.isLoggedIn()) return;
        localStorage.setItem(this.SESSION_ACTIVITY_KEY, String(Date.now()));
        this.scheduleIdleCheck();
    },

    scheduleIdleCheck() {
        if (this._sessionIdleTimer) {
            clearTimeout(this._sessionIdleTimer);
        }
        this._sessionIdleTimer = setTimeout(() => {
            const raw = localStorage.getItem(this.SESSION_ACTIVITY_KEY);
            const lastActivity = raw ? Number(raw) : Date.now();
            if ((Date.now() - lastActivity) >= this.SESSION_INACTIVITY_TIMEOUT_MS) {
                this.forceLogout('Sessão encerrada por inatividade. Faça login novamente.');
            }
        }, this.SESSION_INACTIVITY_TIMEOUT_MS + 1500);
    },

    async validateSessionGlobal() {
        const session = this.getSession();
        if (!session || !session.funcionarioId || typeof API === 'undefined') return;

        try {
            const status = await API.getSessionStatus(session.funcionarioId);
            if (!status || status.valida !== true) {
                this.forceLogout('Sessão inválida ou revogada para este usuário.');
                return;
            }

            const versaoAtual = Number(session.sessaoVersao || 1);
            const versaoServidor = Number(status.sessao_versao || 1);
            if (versaoServidor !== versaoAtual) {
                this.forceLogout('Sua sessão foi encerrada globalmente.');
                return;
            }

            const revogadaEm = status.sessao_revogada_em ? new Date(status.sessao_revogada_em).getTime() : 0;
            const loginEm = session.loggedInAt ? new Date(session.loggedInAt).getTime() : 0;
            if (revogadaEm && loginEm && revogadaEm > loginEm) {
                this.forceLogout('Sua sessão foi revogada. Faça login novamente.');
            }
        } catch (_e) {
            // Falha de rede nao deve derrubar sessao ativa imediatamente.
        }
    },

    async processarFilaNotificacoes() {
        const session = this.getSession();
        if (!session || !session.funcionarioId || typeof API === 'undefined') return;
        if (typeof Toast === 'undefined' || !Toast || typeof Toast.info !== 'function') return;

        try {
            const fila = await API.getNotificacoes(session.funcionarioId, 'pendente', 10);
            if (!Array.isArray(fila) || fila.length === 0) return;

            for (const notif of fila) {
                const titulo = String(notif.titulo || 'Notificação');
                const mensagem = String(notif.mensagem || '');
                Toast.info(`${titulo}: ${mensagem}`, 12000);
                await API.marcarNotificacaoLida(notif.id, session.funcionarioId);
            }
        } catch (_e) {
            // Falha momentanea em notificacoes nao deve quebrar fluxo principal.
        }
    },

    bindSessionSecurity() {
        if (this._sessionSecurityBound) return;
        this._sessionSecurityBound = true;

        ['click', 'keydown', 'touchstart', 'mousemove', 'scroll'].forEach((evt) => {
            window.addEventListener(evt, () => this.registerActivity(), { passive: true });
        });

        this.registerActivity();
        this.validateSessionGlobal();
        this.processarFilaNotificacoes();

        if (!this._sessionStatusTimer) {
            this._sessionStatusTimer = setInterval(async () => {
                await this.validateSessionGlobal();
            }, this.SESSION_STATUS_CHECK_MS);
        }

        if (!this._notificationTimer) {
            this._notificationTimer = setInterval(async () => {
                await this.processarFilaNotificacoes();
            }, this.SESSION_NOTIFICATION_CHECK_MS);
        }
    },

    hasPermission(permission) {
        const session = this.getSession();
        if (!session) return false;
        if (session.isAdmin) return true;
        if (!permission) return true;
        const permissoes = Array.isArray(session.permissoes) ? session.permissoes : [];
        if (permissoes.length === 0) return false;
        return permissoes.includes(permission);
    },

    getUserPreferencesKey() {
        const session = this.getSession();
        const id = session && session.funcionarioId ? String(session.funcionarioId) : 'anonimo';
        return `${this.USER_PREFS_PREFIX}${id}`;
    },

    getPedidoProntoNotifKey() {
        const session = this.getSession();
        const id = session && session.funcionarioId ? String(session.funcionarioId) : 'anonimo';
        return `${this.PEDIDO_PRONTO_NOTIF_PREFIX}${id}`;
    },

    getPedidoProntoNotifState() {
        try {
            const raw = localStorage.getItem(this.getPedidoProntoNotifKey());
            const parsed = raw ? JSON.parse(raw) : {};
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (_e) {
            return {};
        }
    },

    savePedidoProntoNotifState(state) {
        localStorage.setItem(this.getPedidoProntoNotifKey(), JSON.stringify(state || {}));
    },

    buildPedidoProntoSignature(comanda) {
        const itens = Array.isArray(comanda?.itens) ? comanda.itens : [];
        const assinaturaItens = itens
            .map((item) => {
                const nome = String(item?.nome || '').trim().toLowerCase();
                const qtd = Number(item?.quantidade || 0);
                const status = String(item?.kitchenStatus || '').toLowerCase();
                const prontoAt = String(item?.kitchenProntoAt || '');
                return `${nome}|${qtd}|${status}|${prontoAt}`;
            })
            .sort()
            .join('||');

        return [
            String(comanda?.status || ''),
            String(comanda?.statusOperacional || ''),
            String(comanda?.total || ''),
            assinaturaItens
        ].join('::');
    },

    notificarPedidosProntos(comandas) {
        if (this.useMySQL) return 0;
        if (!Array.isArray(comandas) || comandas.length === 0) return 0;

        const session = this.getSession();
        if (!session || !session.funcionarioId) return 0;

        if (typeof Toast === 'undefined' || !Toast || typeof Toast.info !== 'function') return 0;

        const funcionarioId = String(session.funcionarioId);
        const state = this.getPedidoProntoNotifState();
        let totalNotificacoes = 0;

        comandas.forEach((comanda) => {
            if (String(comanda?.funcionarioId || '') !== funcionarioId) {
                return;
            }

            const key = String(comanda?.id || '');
            if (!key) return;

            const estaPronta = comanda.status === 'aberta' && comanda.statusOperacional === 'pronta';
            if (!estaPronta) {
                delete state[key];
                return;
            }

            const assinaturaAtual = this.buildPedidoProntoSignature(comanda);
            if (state[key] === assinaturaAtual) {
                return;
            }

            const cliente = String(comanda.clienteNome || comanda?.cliente?.nome || 'Nao identificado');
            const itens = Array.isArray(comanda.itens) ? comanda.itens : [];
            const itensAtivos = itens.filter((item) => String(item?.kitchenStatus || 'pendente') !== 'cancelado');
            const listaItens = itensAtivos
                .slice(0, 4)
                .map((item) => `${Number(item?.quantidade || 0)}x ${String(item?.nome || 'Item')}`)
                .join(', ');
            const extraItens = itensAtivos.length > 4 ? ` +${itensAtivos.length - 4} itens` : '';
            const pedidoTexto = listaItens || 'Verificar itens na comanda';

            Toast.info(
                `Mesa ${comanda.numeroMesa} pronta para retirada. Cliente: ${cliente}. Pedido: ${pedidoTexto}${extraItens}.`,
                12000
            );

            state[key] = assinaturaAtual;
            totalNotificacoes += 1;
        });

        this.savePedidoProntoNotifState(state);
        return totalNotificacoes;
    },

    getUserPreferences() {
        try {
            const raw = localStorage.getItem(this.getUserPreferencesKey());
            return raw ? JSON.parse(raw) : {};
        } catch (_e) {
            return {};
        }
    },

    saveUserPreferences(partialPrefs) {
        const atual = this.getUserPreferences();
        const novo = {
            ...atual,
            ...partialPrefs,
            updatedAt: new Date().toISOString()
        };
        localStorage.setItem(this.getUserPreferencesKey(), JSON.stringify(novo));
        return novo;
    },

    getCozinhaSoundConfig() {
        const prefs = this.getUserPreferences();
        const som = prefs.cozinhaSom || {};
        return {
            enabled: som.enabled !== false,
            audioDataUrl: som.audioDataUrl || '',
            audioName: som.audioName || '',
            updatedAt: som.updatedAt || null
        };
    },

    setCozinhaSoundConfig(config) {
        const atual = this.getCozinhaSoundConfig();
        const merged = {
            ...atual,
            ...config,
            updatedAt: new Date().toISOString()
        };
        this.saveUserPreferences({ cozinhaSom: merged });
        return merged;
    },

    requirePermission(permission) {
        if (!this.requireAuth()) return false;
        return this.hasPermission(permission);
    },

    // ========== EMPRESA ==========
    async getEmpresa() {
        if (!this.useMySQL) {
            const data = localStorage.getItem('comanda_empresa');
            return data ? JSON.parse(data) : null;
        }
        try {
            return await API.getEmpresa();
        } catch (e) {
            return null;
        }
    },

    async saveEmpresa(empresa) {
        if (!this.useMySQL) {
            if (!empresa.id) {
                empresa.id = Date.now();
                empresa.createdAt = new Date().toISOString();
            }
            localStorage.setItem('comanda_empresa', JSON.stringify(empresa));
            return empresa;
        }
        return await API.saveEmpresa(empresa);
    },

    // ========== FUNCIONÁRIOS ==========
    async getFuncionarios() {
        if (!this.useMySQL) {
            const data = localStorage.getItem('comanda_funcionarios');
            return data ? JSON.parse(data) : [];
        }
        try {
            const result = await API.getFuncionarios();
            return result;
        } catch (e) {
            console.error('Storage.getFuncionarios: erro na API:', e);
            return [];
        }
    },

    async saveFuncionarios(funcionarios) {
        if (!this.useMySQL) {
            localStorage.setItem('comanda_funcionarios', JSON.stringify(funcionarios));
        }
    },

    async addFuncionario(funcionario) {
        if (!this.useMySQL) {
            const funcionarios = await this.getFuncionarios();
            funcionario.id = Date.now();
            funcionario.createdAt = new Date().toISOString();
            funcionario.isAdmin = funcionario.isAdmin || false;
            funcionarios.push(funcionario);
            await this.saveFuncionarios(funcionarios);
            return funcionario;
        }
        const payload = {
            ...funcionario,
            is_admin: funcionario.is_admin ?? funcionario.isAdmin ?? false
        };
        delete payload.isAdmin;
        const result = await API.addFuncionario(payload);
        return result.funcionario || result;
    },

    async getFuncionario(id) {
        if (!this.useMySQL) {
            const funcionarios = await this.getFuncionarios();
            return funcionarios.find(f => f.id == id);
        }
        return await API.getFuncionario(id);
    },

    async getFuncionarioByLogin(login) {
        if (!this.useMySQL) {
            const funcionarios = await this.getFuncionarios();
            return funcionarios.find(f => f.login === login);
        }
        return await API.getFuncionarioByLogin(login);
    },

    async deleteFuncionario(id) {
        if (!this.useMySQL) {
            let funcionarios = await this.getFuncionarios();
            funcionarios = funcionarios.filter(f => f.id != id);
            await this.saveFuncionarios(funcionarios);
        } else {
            await API.deleteFuncionario(id);
        }
    },

    async updateFuncionario(funcionario) {
        if (!this.useMySQL) {
            const funcionarios = await this.getFuncionarios();
            const index = funcionarios.findIndex(f => f.id == funcionario.id);
            if (index !== -1) {
                funcionarios[index] = funcionario;
                await this.saveFuncionarios(funcionarios);
            }
        } else {
            await API.updateFuncionario(funcionario);
        }
    },

    // ========== CLIENTES ==========
    async getClientes() {
        if (!this.useMySQL) return [];
        try {
            return await API.getClientes();
        } catch (e) {
            return [];
        }
    },

    async getCliente(id) {
        if (!this.useMySQL) return null;
        return await API.getCliente(id);
    },

    async getClienteByCpf(cpf) {
        if (!this.useMySQL) return null;
        if (!cpf) return null;
        return await API.getClienteByCpf(cpf);
    },

    async addCliente(cliente) {
        if (!this.useMySQL) {
            cliente.id = Date.now();
            return cliente;
        }
        return await API.addCliente(cliente);
    },

    async updateCliente(cliente) {
        if (!this.useMySQL) return cliente;
        return await API.updateCliente(cliente);
    },

    // ========== COMANDAS ==========
    async getComandas(funcionarioId = null) {
        if (!this.useMySQL) {
            const data = localStorage.getItem('comanda_comandas');
            const comandas = data ? JSON.parse(data) : [];
            if (funcionarioId) {
                return comandas.filter(c => c.funcionario_id == funcionarioId || c.funcionarioId == funcionarioId);
            }
            return comandas;
        }
        try {
            const comandas = await API.getComandas(funcionarioId);
            // Normaliza os dados para compatibilidade
            return comandas.map(c => ({
                id: c.id,
                numeroMesa: c.numero_mesa,
                funcionarioId: c.funcionario_id,
                funcionarioNome: c.funcionario_nome || null,
                clienteId: c.cliente_id,
                clienteNome: c.cliente_nome || null,
                clienteContato: c.cliente_contato || null,
                status: c.status,
                statusOperacional: c.status_operacional || c.status,
                versao: Number(c.versao || 1),
                total: c.total,
                createdAt: c.created_at,
                fechamento: c.fechamento_data ? {
                    data: c.fechamento_data,
                    duracao: c.duracao
                } : null,
                itens: (c.itens || []).map(i => ({
                    id: i.id,
                    nome: i.nome_item || i.nome,
                    categoria: i.categoria,
                    quantidade: parseFloat(i.quantidade),
                    valor: parseFloat(i.valor_unitario || i.valor),
                    produtoId: i.produto_id,
                    observacoes: i.observacoes || null,
                    kitchenStatus: i.kitchen_status || 'pendente',
                    kitchenProntoAt: i.kitchen_pronto_at || null
                }))
            }));
        } catch (e) {
            return [];
        }
    },

    async saveComandas(comandas) {
        if (!this.useMySQL) {
            localStorage.setItem('comanda_comandas', JSON.stringify(comandas));
        }
    },

    async addComanda(comanda) {
        if (!this.useMySQL) {
            const comandas = await this.getComandas();
            comanda.id = Date.now();
            comanda.createdAt = new Date().toISOString();
            comanda.status = 'aberta';
            comandas.push(comanda);
            await this.saveComandas(comandas);
            return comanda;
        }
        
        const result = await API.addComanda({
            numero_mesa: comanda.numeroMesa,
            funcionario_id: comanda.funcionarioId,
            cliente: comanda.cliente || { nome: '', cpf: '', contato: '' }
        });
        
        return {
            id: result.id,
            numeroMesa: comanda.numeroMesa,
            funcionarioId: comanda.funcionarioId,
            clienteId: result.cliente_id,
            status: 'aberta',
            createdAt: new Date().toISOString(),
            itens: []
        };
    },

    async getComanda(id) {
        if (!this.useMySQL) {
            const comandas = await this.getComandas();
            return comandas.find(c => c.id == id);
        }
        
        const c = await API.getComanda(id);
        if (!c) return null;
        
        return {
            id: c.id,
            numeroMesa: c.numero_mesa,
            funcionarioId: c.funcionario_id,
            funcionarioNome: c.funcionario_nome || null,
            clienteId: c.cliente_id,
            cliente: c.cliente_nome ? {
                nome: c.cliente_nome,
                cpf: c.cliente_cpf,
                contato: c.cliente_contato
            } : null,
            observacoes: c.observacoes,
            status: c.status,
            statusOperacional: c.status_operacional || c.status,
            versao: Number(c.versao || 1),
            total: c.total,
            createdAt: c.created_at,
            fechamento: c.fechamento_data ? {
                data: c.fechamento_data,
                duracao: c.duracao
            } : null,
            historicoCancelamentos: (c.historico_cancelamentos || []).map(i => ({
                id: i.id,
                nome: i.nome_item,
                categoria: i.categoria,
                quantidade: i.quantidade,
                valor: parseFloat(i.valor_unitario),
                observacoes: i.observacoes || null,
                canceladoEm: i.created_at || null
            })),
            itens: (c.itens || []).map(i => ({
                id: i.id,           // ID real do banco — necessário para preservar kitchen_status
                nome: i.nome_item,
                categoria: i.categoria,
                quantidade: i.quantidade,
                valor: parseFloat(i.valor_unitario),
                produtoId: i.produto_id,
                observacoes: i.observacoes || null,
                kitchenStatus: i.kitchen_status || 'pendente'
            }))
        };
    },

    async updateComanda(comanda) {
        if (!this.useMySQL) {
            const comandas = await this.getComandas();
            const index = comandas.findIndex(c => c.id == comanda.id);
            if (index !== -1) {
                comandas[index] = comanda;
                await this.saveComandas(comandas);
            }
        } else {
            const result = await API.updateComanda({
                id: comanda.id,
                versao: Number(comanda.versao || 1),
                cliente: comanda.cliente,
                forma_pagamento: comanda.formaPagamento || null,
                itens: (comanda.itens || []).map(i => ({
                    id: i.id,
                    produto_id: i.produtoId,
                    nome: i.nome,
                    categoria: i.categoria,
                    quantidade: i.quantidade,
                    valor: i.valor,
                    observacoes: i.observacoes || null
                }))
            });
            if (result && result.versao_nova) {
                comanda.versao = Number(result.versao_nova);
            }
            return result;
        }
    },

    async deleteComanda(id) {
        if (!this.useMySQL) {
            let comandas = await this.getComandas();
            comandas = comandas.filter(c => c.id != id);
            await this.saveComandas(comandas);
        } else {
            await API.deleteComanda(id);
        }
    },

    async fecharComanda(id) {
        if (!this.useMySQL) {
            const comanda = await this.getComanda(id);
            if (comanda && comanda.status !== 'fechada') {
                comanda.status = 'fechada';
                const abertura = new Date(comanda.createdAt);
                const fechamento = new Date();
                const duracaoMs = fechamento - abertura;
                const horas = Math.floor(duracaoMs / (1000 * 60 * 60));
                const minutos = Math.floor((duracaoMs % (1000 * 60 * 60)) / (1000 * 60));
                comanda.fechamento = {
                    data: fechamento.toISOString(),
                    duracao: `${horas}h ${minutos}min`
                };
                await this.updateComanda(comanda);
            }
            return { success: true };
        }
        return await API.fecharComanda(id);
    },

    async reabrirComanda(id) {
        if (!this.useMySQL) {
            const comanda = await this.getComanda(id);
            if (comanda && comanda.status === 'fechada') {
                comanda.status = 'aberta';
                comanda.fechamento = null;
                await this.updateComanda(comanda);
            }
            return { success: true };
        }
        return await API.reabrirComanda(id);
    },

    // ========== PRODUTOS ==========
    async getProdutos(categoria = null) {
        if (!this.useMySQL) {
            const data = localStorage.getItem('comanda_produtos');
            const produtos = data ? JSON.parse(data) : [];
            if (categoria) {
                return produtos.filter(p => p.categoria === categoria);
            }
            return produtos;
        }
        try {
            const response = await API.getProdutos(categoria);
            const produtos = Array.isArray(response)
                ? response
                : (response && Array.isArray(response.data) ? response.data : []);

            return produtos.map(p => ({
                id: p.id,
                nome: p.nome,
                categoria: p.categoria,
                preco: parseFloat(p.preco)
            }));
        } catch (e) {
            return [];
        }
    },

    async saveProdutos(produtos) {
        if (!this.useMySQL) {
            localStorage.setItem('comanda_produtos', JSON.stringify(produtos));
        }
    },

    async addProduto(produto) {
        if (!this.useMySQL) {
            const produtos = await this.getProdutos();
            produto.id = Date.now();
            produtos.push(produto);
            await this.saveProdutos(produtos);
            return produto;
        }
        return await API.addProduto(produto);
    },

    async updateProduto(produto) {
        if (!this.useMySQL) {
            const produtos = await this.getProdutos();
            const index = produtos.findIndex(p => p.id == produto.id);
            if (index !== -1) {
                produtos[index] = { ...produtos[index], ...produto };
                await this.saveProdutos(produtos);
            }
            return;
        }
        await API.updateProduto(produto);
    },

    async deleteProduto(id) {
        if (!this.useMySQL) {
            let produtos = await this.getProdutos();
            produtos = produtos.filter(p => p.id != id);
            await this.saveProdutos(produtos);
        } else {
            await API.deleteProduto(id);
        }
    },

    // ========== ESTOQUE ==========
    async getEstoque() {
        if (!this.useMySQL) {
            const data = localStorage.getItem('comanda_estoque');
            return data ? JSON.parse(data) : [];
        }
        try {
            const estoque = await API.getEstoque();
            return estoque.map(e => ({
                id: e.id,
                nome: e.nome,
                categoria: e.categoria,
                quantidade: parseFloat(e.quantidade),
                unidade: e.unidade,
                quantidadeMinima: parseFloat(e.quantidade_minima),
                valorUnitario: parseFloat(e.valor_unitario),
                createdAt: e.created_at,
                updatedAt: e.updated_at
            }));
        } catch (e) {
            return [];
        }
    },

    async saveEstoque(estoque) {
        if (!this.useMySQL) {
            localStorage.setItem('comanda_estoque', JSON.stringify(estoque));
        }
    },

    async addItemEstoque(item) {
        if (!this.useMySQL) {
            const estoque = await this.getEstoque();
            const existing = estoque.find(e => e.nome.toLowerCase() === item.nome.toLowerCase());
            if (existing) {
                existing.quantidade += parseFloat(item.quantidade);
                existing.updatedAt = new Date().toISOString();
            } else {
                item.id = Date.now();
                item.createdAt = new Date().toISOString();
                item.updatedAt = item.createdAt;
                estoque.push(item);
            }
            await this.saveEstoque(estoque);
            return item;
        }
        return await API.addItemEstoque(item);
    },

    async updateItemEstoque(item) {
        if (!this.useMySQL) {
            const estoque = await this.getEstoque();
            const index = estoque.findIndex(e => e.id == item.id);
            if (index !== -1) {
                item.updatedAt = new Date().toISOString();
                estoque[index] = item;
                await this.saveEstoque(estoque);
            }
        } else {
            await API.updateItemEstoque(item);
        }
    },

    async deleteItemEstoque(id) {
        if (!this.useMySQL) {
            let estoque = await this.getEstoque();
            estoque = estoque.filter(e => e.id != id);
            await this.saveEstoque(estoque);
        } else {
            await API.deleteItemEstoque(id);
        }
    },

    async getItemEstoque(id) {
        if (!this.useMySQL) {
            const estoque = await this.getEstoque();
            return estoque.find(e => e.id == id);
        }
        return await API.getEstoque();
    },

    async getEstoqueAlertas() {
        if (!this.useMySQL) {
            const estoque = await this.getEstoque();
            return estoque.filter(e => e.quantidade <= e.quantidadeMinima);
        }
        return await API.getEstoqueAlertas();
    },

    // ========== LISTA DE COMPRAS ==========
    async getListaCompras(pendentes = false) {
        if (!this.useMySQL) return [];
        try {
            return await API.getListaCompras(pendentes);
        } catch (e) {
            return [];
        }
    },

    async addItemListaCompras(item) {
        if (!this.useMySQL) return { id: Date.now() };
        return await API.addItemListaCompras(item);
    },

    async marcarItemComprado(id, estoqueId, quantidade) {
        if (!this.useMySQL) return { success: true };
        return await API.updateItemListaCompras({
            id: id,
            status: 'comprado',
            estoque_id: estoqueId,
            quantidade_adicionada: quantidade
        });
    },

    async deleteItemListaCompras(id) {
        if (!this.useMySQL) return { success: true };
        return await API.deleteItemListaCompras(id);
    },

    // ========== RELATÓRIOS ==========
    async getRelatorioDia(data = new Date()) {
        if (!this.useMySQL) {
            const inicio = new Date(data.getFullYear(), data.getMonth(), data.getDate(), 0, 0, 0);
            const fim = new Date(data.getFullYear(), data.getMonth(), data.getDate(), 23, 59, 59);
            return this.calcularTotais(await this.getVendasPorPeriodo(inicio, fim));
        }
        const result = await API.getRelatorio('dia', { data: data.toISOString().split('T')[0] });
        return this.normalizarRelatorio(result);
    },

    async getRelatorioSemana(data = new Date()) {
        if (!this.useMySQL) {
            const inicio = new Date(data);
            inicio.setDate(data.getDate() - data.getDay());
            inicio.setHours(0, 0, 0, 0);
            const fim = new Date(inicio);
            fim.setDate(fim.getDate() + 6);
            fim.setHours(23, 59, 59);
            return this.calcularTotais(await this.getVendasPorPeriodo(inicio, fim));
        }
        const result = await API.getRelatorio('semana', { data: data.toISOString().split('T')[0] });
        return this.normalizarRelatorio(result);
    },

    async getRelatorioMes(ano, mes) {
        if (!this.useMySQL) {
            const inicio = new Date(ano, mes - 1, 1, 0, 0, 0);
            const fim = new Date(ano, mes, 0, 23, 59, 59);
            return this.calcularTotais(await this.getVendasPorPeriodo(inicio, fim));
        }
        const result = await API.getRelatorio('mes', { ano, mes });
        return this.normalizarRelatorio(result);
    },

    async getRelatorioAno(ano) {
        if (!this.useMySQL) {
            const inicio = new Date(ano, 0, 1, 0, 0, 0);
            const fim = new Date(ano, 11, 31, 23, 59, 59);
            return this.calcularTotais(await this.getVendasPorPeriodo(inicio, fim));
        }
        const result = await API.getRelatorio('ano', { ano });
        return this.normalizarRelatorio(result);
    },

    normalizarRelatorio(result) {
        return {
            comandas: result.comandas,
            total: result.total,
            quantidadeItens: result.quantidade_itens,
            porCategoria: result.por_categoria || {},
            porFuncionario: result.por_funcionario || {},
            ticketMedio: result.ticket_medio
        };
    },

    async getVendasPorPeriodo(inicio, fim) {
        const comandas = await this.getComandas();
        return comandas.filter(c => {
            if (c.status !== 'fechada') return false;
            const data = new Date(c.createdAt);
            return data >= inicio && data <= fim;
        });
    },

    calcularTotais(comandas) {
        let total = 0;
        let quantidadeItens = 0;
        const porCategoria = {};
        const porFuncionario = {};

        comandas.forEach(c => {
            const valorComanda = c.itens ? c.itens.reduce((sum, item) => {
                const itemTotal = item.quantidade * item.valor;
                quantidadeItens += item.quantidade;
                
                if (item.categoria) {
                    porCategoria[item.categoria] = (porCategoria[item.categoria] || 0) + itemTotal;
                }
                
                return sum + itemTotal;
            }, 0) : 0;
            
            total += valorComanda;
            
            if (c.funcionarioId) {
                porFuncionario[c.funcionarioNome || 'Desconhecido'] = (porFuncionario[c.funcionarioNome || 'Desconhecido'] || 0) + valorComanda;
            }
        });

        return {
            comandas: comandas.length,
            total,
            quantidadeItens,
            porCategoria,
            porFuncionario,
            ticketMedio: comandas.length > 0 ? total / comandas.length : 0
        };
    },

    // ========== IMPORT/EXPORT ==========
    async getAllData() {
        return {
            empresa: await this.getEmpresa(),
            funcionarios: await this.getFuncionarios(),
            comandas: await this.getComandas(),
            produtos: await this.getProdutos(),
            estoque: await this.getEstoque()
        };
    },

    async exportFullBackup() {
        if (!this.useMySQL) {
            return {
                version: 'offline-v1',
                generated_at: new Date().toISOString(),
                data: await this.getAllData()
            };
        }
        return await API.exportFullBackup();
    },

    async restoreFullBackup(backup, login, senha) {
        if (!this.useMySQL) {
            throw new Error('Restauração completa disponível apenas no modo MySQL');
        }
        return await API.restoreFullBackup(backup, login, senha);
    },

    async importData(data) {
        // Implementação futura para importação em massa
        console.log('Importação via API ainda não implementada');
    },

    async resetDatabase(login, senha, confirm) {
        if (!this.useMySQL) {
            this.clearAll();
            return { success: true };
        }
        return await API.resetDatabase(login, senha, confirm);
    },

    clearAll() {
        localStorage.removeItem('comanda_empresa');
        localStorage.removeItem('comanda_funcionarios');
        localStorage.removeItem('comanda_comandas');
        localStorage.removeItem('comanda_produtos');
        localStorage.removeItem('comanda_estoque');
        localStorage.removeItem(this.SESSION_KEY);
    }
};

// Modo offline (fallback)
Storage.useMySQL = true;
