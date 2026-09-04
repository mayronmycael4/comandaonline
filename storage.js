// Storage usando MySQL via API
const Storage = {
    SESSION_KEY: 'comanda_session',
    EMPRESA_CACHE_KEY: 'comanda_empresa',
    FUNCIONARIOS_CACHE_KEY: 'comanda_funcionarios_cache',
    FUNCIONARIOS_CACHE_META_KEY: 'comanda_funcionarios_cache_meta',
    PRODUTOS_CACHE_KEY: 'comanda_produtos_cache_mysql',
    PRODUTOS_CACHE_META_KEY: 'comanda_produtos_cache_meta_mysql',
    COMANDAS_CACHE_KEY: 'comanda_comandas_cache_mysql',
    COMANDA_SYNC_QUEUE_KEY: 'comanda_sync_queue_v1',
    COMANDA_UPDATE_QUEUE_KEY: 'comanda_update_sync_queue_v1',
    USER_PREFS_PREFIX: 'comanda_user_prefs_',
    PEDIDO_PRONTO_NOTIF_PREFIX: 'comanda_pedido_pronto_notif_',
    SESSION_ACTIVITY_KEY: 'comanda_session_last_activity',
    SESSION_INACTIVITY_TIMEOUT_MS: 30 * 60 * 1000,
    SESSION_STATUS_CHECK_MS: 60 * 1000,
    SESSION_NOTIFICATION_CHECK_MS: 15 * 1000,
    FUNCIONARIOS_CACHE_TTL_MS: 5 * 60 * 1000,
    PRODUTOS_CACHE_TTL_MS: 10 * 60 * 1000,
    COMANDA_QUEUE_RETRY_BASE_MS: 5000,
    COMANDA_QUEUE_RETRY_MAX_MS: 5 * 60 * 1000,
    COMANDA_QUEUE_PROCESS_INTERVAL_MS: 15000,
    _sessionSecurityBound: false,
    _sessionStatusTimer: null,
    _sessionIdleTimer: null,
    _notificationTimer: null,
    _queueTimer: null,
    _queueInFlight: false,
    _funcionariosRefreshPromise: null,
    _funcionariosMemoryCache: null,
    _funcionariosMemoryCacheAt: 0,
    _funcionariosLastBgRefreshAt: 0,
    _produtosRefreshPromise: null,
    _produtosMemoryCache: null,
    _produtosMemoryCacheAt: 0,
    _produtosLastBgRefreshAt: 0,
    _sessionPreloadPromise: null,
    _syncBadgeEl: null,
    _syncBadgeHideTimer: null,
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
        this.initOfflineSync();
    },

    clearSession() {
        localStorage.removeItem(this.SESSION_KEY);
        localStorage.removeItem(this.SESSION_ACTIVITY_KEY);
        localStorage.removeItem('comanda_admin_panel_url');
        this._funcionariosMemoryCache = null;
        this._funcionariosMemoryCacheAt = 0;
        this._funcionariosLastBgRefreshAt = 0;
        this._produtosMemoryCache = null;
        this._produtosMemoryCacheAt = 0;
        this._produtosLastBgRefreshAt = 0;
        this._sessionPreloadPromise = null;
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
        if (this._queueTimer) {
            clearInterval(this._queueTimer);
            this._queueTimer = null;
        }
    },

    isLoggedIn() {
        return this.getSession() !== null;
    },

    async loginComPin(login, pin) {
        if (!this.useMySQL) {
            throw new Error('Login por PIN indisponivel no modo local');
        }
        return await API.loginPin(login, pin);
    },

    requireAuth() {
        if (!this.isLoggedIn()) {
            window.location.href = 'login.html';
            return false;
        }
        this.bindSessionSecurity();
        this.initOfflineSync();
        return true;
    },

    initOfflineSync() {
        if (!this.useMySQL) return;
        this.ensureSyncBadge();

        if (!window.__comandaSyncOnlineBound) {
            window.addEventListener('online', () => {
                this.updateSyncBadge();
                this.notifySyncStatus('Conexao restaurada. Sincronizando fila...', 'info');
                this.processComandaQueue(true);
                this.processComandaUpdateQueue(true);
            });
            window.addEventListener('offline', () => {
                this.updateSyncBadge();
                this.notifySyncStatus('Sem conexao. Operando com fila local.', 'warn');
            });
            window.__comandaSyncOnlineBound = true;
        }

        if (!this._queueTimer) {
            this._queueTimer = setInterval(() => {
                this.processComandaQueue(false);
                this.processComandaUpdateQueue(false);
            }, this.COMANDA_QUEUE_PROCESS_INTERVAL_MS);
        }

        this.updateSyncBadge();
        this.processComandaQueue(false);
        this.processComandaUpdateQueue(false);
    },

    ensureSyncBadge() {
        if (typeof document === 'undefined') return;
        if (this._syncBadgeEl && document.body.contains(this._syncBadgeEl)) return;

        let el = document.getElementById('comandaSyncBadge');
        if (!el) {
            el = document.createElement('div');
            el.id = 'comandaSyncBadge';
            el.style.position = 'fixed';
            el.style.top = '12px';
            el.style.left = '50%';
            el.style.transform = 'translate(-50%, -14px) scale(0.98)';
            el.style.zIndex = '10020';
            el.style.padding = '10px 14px';
            el.style.borderRadius = '999px';
            el.style.fontSize = '12px';
            el.style.fontWeight = '700';
            el.style.backdropFilter = 'blur(8px)';
            el.style.boxShadow = '0 10px 24px rgba(0,0,0,0.24)';
            el.style.maxWidth = '86vw';
            el.style.pointerEvents = 'none';
            el.style.opacity = '0';
            el.style.whiteSpace = 'nowrap';
            el.style.overflow = 'hidden';
            el.style.textOverflow = 'ellipsis';
            el.style.transition = 'opacity .25s ease, transform .25s ease';
            document.body.appendChild(el);
        }
        this._syncBadgeEl = el;
    },

    notifySyncStatus(message, level = 'info', extra = {}) {
        try {
            window.dispatchEvent(new CustomEvent('comanda-sync-status', {
                detail: { message, level, ...extra }
            }));
        } catch (_e) {
            // noop
        }
        this.updateSyncBadge(message, level, true);
    },

    updateSyncBadge(overrideMessage = '', level = 'info', showFloating = false) {
        this.ensureSyncBadge();
        if (!this._syncBadgeEl) return;

        const pendingCreate = this.getComandaQueue().filter((q) => q.status !== 'enviado_com_sucesso').length;
        const pendingUpdate = this.getComandaUpdateQueue().filter((q) => q.status !== 'enviado_com_sucesso').length;
        const pending = pendingCreate + pendingUpdate;
        const online = typeof navigator !== 'undefined' ? navigator.onLine : true;
        const base = pending > 0
            ? `${pending} pendencia(s) de envio`
            : (online ? 'Sincronizacao em dia' : 'Offline');
        const text = overrideMessage || base;

        this._syncBadgeEl.textContent = text;
        if (!online || level === 'warn' || level === 'error') {
            this._syncBadgeEl.style.background = 'rgba(183, 28, 28, 0.95)';
            this._syncBadgeEl.style.color = '#fff';
        } else if (pending > 0 || level === 'info') {
            this._syncBadgeEl.style.background = 'rgba(15, 118, 110, 0.94)';
            this._syncBadgeEl.style.color = '#fff';
        } else {
            this._syncBadgeEl.style.background = 'rgba(27, 127, 59, 0.95)';
            this._syncBadgeEl.style.color = '#fff';
        }

        if (!showFloating) {
            this._syncBadgeEl.style.opacity = '0';
            this._syncBadgeEl.style.transform = 'translate(-50%, -14px) scale(0.98)';
            return;
        }

        this._syncBadgeEl.style.opacity = '1';
        this._syncBadgeEl.style.transform = 'translate(-50%, 0) scale(1)';

        if (this._syncBadgeHideTimer) {
            clearTimeout(this._syncBadgeHideTimer);
            this._syncBadgeHideTimer = null;
        }

        this._syncBadgeHideTimer = setTimeout(() => {
            if (!this._syncBadgeEl) return;
            this._syncBadgeEl.style.opacity = '0';
            this._syncBadgeEl.style.transform = 'translate(-50%, -14px) scale(0.98)';
        }, 2400);
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
            const data = localStorage.getItem(this.EMPRESA_CACHE_KEY);
            return data ? JSON.parse(data) : null;
        }
        try {
            const empresa = await API.getEmpresa();
            if (empresa) {
                localStorage.setItem(this.EMPRESA_CACHE_KEY, JSON.stringify(empresa));
            }
            return empresa;
        } catch (e) {
            const cached = localStorage.getItem(this.EMPRESA_CACHE_KEY);
            return cached ? JSON.parse(cached) : null;
        }
    },

    async saveEmpresa(empresa) {
        if (!this.useMySQL) {
            if (!empresa.id) {
                empresa.id = Date.now();
                empresa.createdAt = new Date().toISOString();
            }
            localStorage.setItem(this.EMPRESA_CACHE_KEY, JSON.stringify(empresa));
            return empresa;
        }
        const saved = await API.saveEmpresa(empresa);
        const empresaSalva = saved && typeof saved === 'object' ? saved : empresa;
        localStorage.setItem(this.EMPRESA_CACHE_KEY, JSON.stringify(empresaSalva));
        return saved;
    },

    // ========== FUNCIONÁRIOS ==========
    getFuncionariosCache() {
        try {
            const data = localStorage.getItem(this.FUNCIONARIOS_CACHE_KEY);
            return data ? JSON.parse(data) : [];
        } catch (_e) {
            return [];
        }
    },

    getFuncionariosCacheMeta() {
        try {
            const data = localStorage.getItem(this.FUNCIONARIOS_CACHE_META_KEY);
            return data ? JSON.parse(data) : { updatedAt: 0 };
        } catch (_e) {
            return { updatedAt: 0 };
        }
    },

    saveFuncionariosCache(funcionarios) {
        this._funcionariosMemoryCache = Array.isArray(funcionarios) ? [...funcionarios] : [];
        this._funcionariosMemoryCacheAt = Date.now();
        localStorage.setItem(this.FUNCIONARIOS_CACHE_KEY, JSON.stringify(funcionarios || []));
        localStorage.setItem(this.FUNCIONARIOS_CACHE_META_KEY, JSON.stringify({ updatedAt: Date.now() }));
    },

    shouldRefreshFuncionariosInBackground() {
        const minIntervalMs = 2 * 60 * 1000;
        const now = Date.now();
        return (now - Number(this._funcionariosLastBgRefreshAt || 0)) > minIntervalMs;
    },

    async refreshFuncionariosCacheInBackground() {
        if (!this.useMySQL || this._funcionariosRefreshPromise) return;
        if (!this.shouldRefreshFuncionariosInBackground()) return;

        this._funcionariosLastBgRefreshAt = Date.now();

        this._funcionariosRefreshPromise = (async () => {
            try {
                const result = await API.getFuncionarios();
                if (Array.isArray(result) && result.length >= 0) {
                    this.saveFuncionariosCache(result);
                    this.notifySyncStatus('Funcionarios atualizados do servidor.', 'ok', { source: 'server' });
                }
            } catch (_e) {
                // segue com cache local
            } finally {
                this._funcionariosRefreshPromise = null;
            }
        })();
    },

    async getFuncionarios() {
        if (!this.useMySQL) {
            const data = localStorage.getItem('comanda_funcionarios');
            return data ? JSON.parse(data) : [];
        }

        const mem = Array.isArray(this._funcionariosMemoryCache) ? this._funcionariosMemoryCache : null;
        const memAge = Date.now() - Number(this._funcionariosMemoryCacheAt || 0);
        const memValido = !!mem && mem.length > 0 && memAge < this.FUNCIONARIOS_CACHE_TTL_MS;
        if (mem && mem.length > 0) {
            if (!memValido) {
                this.refreshFuncionariosCacheInBackground();
            }
            return mem;
        }

        const cache = this.getFuncionariosCache();
        const cacheMeta = this.getFuncionariosCacheMeta();
        const cacheAge = Date.now() - Number(cacheMeta.updatedAt || 0);
        const cacheValido = cache.length > 0 && cacheAge < this.FUNCIONARIOS_CACHE_TTL_MS;

        if (cache.length > 0) {
            this._funcionariosMemoryCache = [...cache];
            this._funcionariosMemoryCacheAt = Date.now();
            if (!cacheValido) {
                this.refreshFuncionariosCacheInBackground();
            }
            return cache;
        }

        try {
            const result = await API.getFuncionarios();
            this.saveFuncionariosCache(result || []);
            this.notifySyncStatus('Funcionarios carregados do servidor.', 'ok', { source: 'server' });
            return result;
        } catch (e) {
            console.error('Storage.getFuncionarios: erro na API:', e);
            this.notifySyncStatus('Sem conexao com servidor. Usando dados locais.', 'warn', { source: 'cache' });
            return this.getFuncionariosCache();
        }
    },

    async preloadFuncionariosForSession() {
        if (!this.useMySQL) return;
        try {
            await this.getFuncionarios();
        } catch (_e) {
            // Nao bloqueia login nem navegacao.
        }
    },

    async preloadCoreDataForSession() {
        if (!this.useMySQL) return;
        if (this._sessionPreloadPromise) return this._sessionPreloadPromise;

        this._sessionPreloadPromise = (async () => {
            await Promise.allSettled([
                this.getEmpresa(),
                this.getFuncionarios(),
                this.getProdutos(),
                this.getComandas()
            ]);
        })();

        try {
            await this._sessionPreloadPromise;
        } finally {
            this._sessionPreloadPromise = null;
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

    getComandaQueue() {
        try {
            const raw = localStorage.getItem(this.COMANDA_SYNC_QUEUE_KEY);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (_e) {
            return [];
        }
    },

    getComandaUpdateQueue() {
        try {
            const raw = localStorage.getItem(this.COMANDA_UPDATE_QUEUE_KEY);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (_e) {
            return [];
        }
    },

    saveComandaUpdateQueue(queue) {
        localStorage.setItem(this.COMANDA_UPDATE_QUEUE_KEY, JSON.stringify(queue || []));
        this.updateSyncBadge();
    },

    queueComandaUpdatePayload(payload, reason = 'pendente') {
        const queue = this.getComandaUpdateQueue();
        const nowIso = new Date().toISOString();
        const id = Number(payload?.id || 0);
        if (!id) return null;

        const existenteIndex = queue.findIndex((q) => Number(q.id) === id);
        const item = {
            id,
            payload,
            updated_at: nowIso,
            tentativa_em: nowIso,
            retries: 0,
            next_retry_at: Date.now(),
            status: reason,
            ultimo_erro: null
        };

        if (existenteIndex >= 0) {
            // Mantem somente o estado mais recente da comanda para evitar conflitos de ordem.
            const existente = queue[existenteIndex];
            item.retries = Number(existente.retries || 0);
            queue[existenteIndex] = item;
        } else {
            queue.push(item);
        }

        this.saveComandaUpdateQueue(queue);
        return item;
    },

    saveComandaQueue(queue) {
        localStorage.setItem(this.COMANDA_SYNC_QUEUE_KEY, JSON.stringify(queue || []));
        this.updateSyncBadge();
    },

    generateComandaRequestId() {
        const rand = Math.random().toString(36).slice(2, 10);
        return `cmd_${Date.now()}_${rand}`;
    },

    getComandasCache() {
        try {
            const raw = localStorage.getItem(this.COMANDAS_CACHE_KEY);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (_e) {
            return [];
        }
    },

    saveComandasCache(comandas) {
        localStorage.setItem(this.COMANDAS_CACHE_KEY, JSON.stringify(comandas || []));
    },

    upsertComandaCache(comanda) {
        if (!comanda || !comanda.id) return;
        const cache = this.getComandasCache();
        const idx = cache.findIndex((c) => String(c.id) === String(comanda.id));
        if (idx >= 0) {
            cache[idx] = { ...cache[idx], ...comanda };
        } else {
            cache.unshift(comanda);
        }
        this.saveComandasCache(cache);
    },

    normalizeComandasFromApi(comandas = []) {
        return (Array.isArray(comandas) ? comandas : []).map((c) => ({
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
            itens: (c.itens || []).map((i) => ({
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
    },

    mergePendingAndComandas(comandas = []) {
        const pendentes = this.getComandaQueue()
            .filter((q) => q.status !== 'enviado_com_sucesso')
            .map((q) => this.buildPendingComandaFromQueueItem(q));
        return [...pendentes, ...(Array.isArray(comandas) ? comandas : [])];
    },

    getComandasSnapshot(funcionarioId = null) {
        if (!this.useMySQL) {
            const data = localStorage.getItem('comanda_comandas');
            const comandas = data ? JSON.parse(data) : [];
            if (funcionarioId) {
                return comandas.filter((c) => c.funcionario_id == funcionarioId || c.funcionarioId == funcionarioId);
            }
            return comandas;
        }

        const cache = this.getComandasCache();
        const merged = this.mergePendingAndComandas(cache);
        if (funcionarioId) {
            return merged.filter((c) => c.funcionarioId == funcionarioId || c.funcionario_id == funcionarioId);
        }
        return merged;
    },

    async syncComandasInBackground(funcionarioId = null, options = {}) {
        const silent = !!options.silent;

        if (!this.useMySQL) {
            return this.getComandasSnapshot(funcionarioId);
        }

        if (!silent) {
            this.notifySyncStatus('Carregando comandas em segundo plano...', 'info', { source: 'cache' });
        }

        try {
            const comandas = await API.getComandas(funcionarioId);
            const normalizadas = this.normalizeComandasFromApi(comandas);
            this.saveComandasCache(normalizadas);

            const merged = this.mergePendingAndComandas(normalizadas);
            if (!silent) {
                this.notifySyncStatus('Comandas atualizadas.', 'ok', { source: 'server' });
            }

            if (funcionarioId) {
                return merged.filter((c) => c.funcionarioId == funcionarioId || c.funcionario_id == funcionarioId);
            }
            return merged;
        } catch (_e) {
            const online = typeof navigator !== 'undefined' ? navigator.onLine : true;
            if (online) {
                this.notifySyncStatus('Servidor lento. Exibindo dados locais.', 'warn', { source: 'cache' });
            } else {
                this.notifySyncStatus('Sem conexao. Exibindo dados locais.', 'warn', { source: 'cache' });
            }
            return this.getComandasSnapshot(funcionarioId);
        }
    },

    isRetriableSendError(error) {
        const status = Number(error?.status || 0);
        if (!status) return true;
        if (status >= 500) return true;
        if (status === 429) return true;
        const code = String(error?.code || '').toUpperCase();
        return code === 'REQUEST_TIMEOUT';
    },

    queueComandaPayload(payload, reason = 'pendente') {
        const queue = this.getComandaQueue();
        const requestId = payload.request_id || this.generateComandaRequestId();
        const existente = queue.find((q) => q.request_id === requestId);
        if (existente) return existente;

        const nowIso = new Date().toISOString();
        const item = {
            id_local: `local-${requestId}`,
            request_id: requestId,
            payload,
            mesa: payload.numero_mesa,
            funcionario_id: payload.funcionario_id,
            cliente: payload.cliente || null,
            itens: Array.isArray(payload.itens) ? payload.itens : [],
            created_at: nowIso,
            updated_at: nowIso,
            tentativa_em: nowIso,
            retries: 0,
            next_retry_at: Date.now(),
            status: reason,
            ultimo_erro: null
        };
        queue.push(item);
        this.saveComandaQueue(queue);
        return item;
    },

    buildPendingComandaFromQueueItem(item) {
        return {
            id: item.id_local,
            numeroMesa: item.mesa,
            funcionarioId: item.funcionario_id,
            cliente: item.cliente || null,
            clienteNome: item.cliente?.nome || null,
            status: 'aberta',
            statusOperacional: 'na_fila',
            total: 0,
            createdAt: item.created_at,
            itens: Array.isArray(item.itens) ? item.itens : [],
            pendingSync: true,
            syncStatus: item.status,
            requestId: item.request_id
        };
    },

    async processComandaQueue(force = false) {
        if (!this.useMySQL) return;
        if (this._queueInFlight) return;
        if (!force && typeof navigator !== 'undefined' && navigator.onLine === false) return;

        this._queueInFlight = true;
        try {
            let queue = this.getComandaQueue();
            if (queue.length === 0) {
                this.updateSyncBadge();
                return;
            }

            queue.sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime());
            const now = Date.now();
            const idx = queue.findIndex((item) => now >= Number(item.next_retry_at || 0));
            if (idx < 0) {
                this.updateSyncBadge();
                return;
            }

            const item = queue[idx];
            item.status = 'enviando';
            item.updated_at = new Date().toISOString();
            queue[idx] = item;
            this.saveComandaQueue(queue);
            this.notifySyncStatus('Enviando item da fila local...', 'info');

            try {
                const res = await API.addComanda(item.payload);
                queue = this.getComandaQueue().filter((q) => q.request_id !== item.request_id);
                this.saveComandaQueue(queue);
                this.notifySyncStatus('Comanda sincronizada com sucesso.', 'ok', {
                    request_id: item.request_id,
                    comanda_id: res?.id || null
                });
                if (typeof Toast !== 'undefined' && Toast && typeof Toast.success === 'function') {
                    Toast.success(`Comanda da mesa ${item.mesa} sincronizada com sucesso.`);
                }
            } catch (error) {
                queue = this.getComandaQueue();
                const qIndex = queue.findIndex((q) => q.request_id === item.request_id);
                if (qIndex >= 0) {
                    const current = queue[qIndex];
                    current.retries = Number(current.retries || 0) + 1;
                    current.status = this.isRetriableSendError(error)
                        ? (typeof navigator !== 'undefined' && navigator.onLine === false ? 'aguardando_conexao' : 'aguardando_servidor')
                        : 'erro_ao_enviar';
                    current.ultimo_erro = error?.message || 'erro_desconhecido';
                    current.tentativa_em = new Date().toISOString();

                    if (this.isRetriableSendError(error)) {
                        const delay = Math.min(
                            this.COMANDA_QUEUE_RETRY_MAX_MS,
                            this.COMANDA_QUEUE_RETRY_BASE_MS * Math.pow(2, Math.max(0, current.retries - 1))
                        );
                        current.next_retry_at = Date.now() + delay;
                        this.notifySyncStatus('Servidor indisponivel. Nova tentativa automatica sera feita.', 'warn');
                    } else {
                        current.next_retry_at = Date.now() + this.COMANDA_QUEUE_RETRY_MAX_MS;
                        this.notifySyncStatus('Falha no envio. Item mantido na fila para nova tentativa.', 'warn');
                    }
                    current.updated_at = new Date().toISOString();
                    queue[qIndex] = current;
                    this.saveComandaQueue(queue);
                }
            }
        } finally {
            this._queueInFlight = false;
            this.updateSyncBadge();
        }
    },

    async processComandaUpdateQueue(force = false) {
        if (!this.useMySQL) return;
        if (this._queueInFlight) return;
        if (!force && typeof navigator !== 'undefined' && navigator.onLine === false) return;

        this._queueInFlight = true;
        try {
            let queue = this.getComandaUpdateQueue();
            if (queue.length === 0) {
                this.updateSyncBadge();
                return;
            }

            queue.sort((a, b) => Number(a.next_retry_at || 0) - Number(b.next_retry_at || 0));
            const now = Date.now();
            const idx = queue.findIndex((item) => now >= Number(item.next_retry_at || 0));
            if (idx < 0) {
                this.updateSyncBadge();
                return;
            }

            const item = queue[idx];
            item.status = 'enviando';
            item.updated_at = new Date().toISOString();
            queue[idx] = item;
            this.saveComandaUpdateQueue(queue);
            this.notifySyncStatus('Tentando enviar atualizacoes da comanda...', 'info');

            try {
                const res = await API.updateComanda(item.payload);
                queue = this.getComandaUpdateQueue().filter((q) => Number(q.id) !== Number(item.id));
                this.saveComandaUpdateQueue(queue);
                this.notifySyncStatus('Atualizacoes da comanda sincronizadas.', 'ok', {
                    comanda_id: item.id,
                    versao_nova: res?.versao_nova || null
                });
            } catch (error) {
                queue = this.getComandaUpdateQueue();
                const qIndex = queue.findIndex((q) => Number(q.id) === Number(item.id));
                if (qIndex >= 0) {
                    const current = queue[qIndex];
                    current.retries = Number(current.retries || 0) + 1;
                    current.status = this.isRetriableSendError(error)
                        ? (typeof navigator !== 'undefined' && navigator.onLine === false ? 'aguardando_conexao' : 'aguardando_servidor')
                        : 'erro_ao_enviar';
                    current.ultimo_erro = error?.message || 'erro_desconhecido';
                    current.tentativa_em = new Date().toISOString();

                    if (this.isRetriableSendError(error)) {
                        const delay = Math.min(
                            this.COMANDA_QUEUE_RETRY_MAX_MS,
                            this.COMANDA_QUEUE_RETRY_BASE_MS * Math.pow(2, Math.max(0, current.retries - 1))
                        );
                        current.next_retry_at = Date.now() + delay;
                        this.notifySyncStatus('Sem conexao ou servidor lento. Seguiremos tentando enviar.', 'warn');
                    } else {
                        current.next_retry_at = Date.now() + this.COMANDA_QUEUE_RETRY_MAX_MS;
                        this.notifySyncStatus('Falha no envio de atualizacao. Tentaremos novamente.', 'warn');
                    }

                    current.updated_at = new Date().toISOString();
                    queue[qIndex] = current;
                    this.saveComandaUpdateQueue(queue);
                }
            }
        } finally {
            this._queueInFlight = false;
            this.updateSyncBadge();
        }
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
            const normalizadas = this.normalizeComandasFromApi(comandas);
            this.saveComandasCache(normalizadas);
            return this.mergePendingAndComandas(normalizadas);
        } catch (e) {
            this.notifySyncStatus('Servidor lento/indisponivel. Exibindo dados locais.', 'warn');
            return this.getComandasSnapshot(funcionarioId);
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

        const requestId = comanda.requestId || this.generateComandaRequestId();
        const payload = {
            numero_mesa: comanda.numeroMesa,
            funcionario_id: comanda.funcionarioId,
            cliente: comanda.cliente || { nome: '', cpf: '', contato: '' },
            request_id: requestId
        };

        this.notifySyncStatus('Enviando comanda...', 'info');
        try {
            const result = await API.addComanda(payload);
            const comandaCriada = {
                id: result.id,
                numeroMesa: comanda.numeroMesa,
                funcionarioId: comanda.funcionarioId,
                funcionarioNome: comanda.funcionarioNome || null,
                clienteId: result.cliente_id || null,
                clienteNome: (comanda.cliente && comanda.cliente.nome) || null,
                clienteContato: (comanda.cliente && comanda.cliente.contato) || null,
                status: 'aberta',
                statusOperacional: 'aberta',
                versao: 1,
                total: 0,
                createdAt: new Date().toISOString(),
                fechamento: null,
                itens: []
            };
            this.upsertComandaCache(comandaCriada);
            this.notifySyncStatus('Comanda enviada com sucesso.', 'ok', { comanda_id: result.id });
            return comandaCriada;
        } catch (error) {
            if (this.isRetriableSendError(error)) {
                this.notifySyncStatus('Servidor indisponivel. Nao foi possivel abrir a comanda em tempo real.', 'warn');
                throw new Error('Nao foi possivel abrir a comanda agora. Verifique internet/servidor e tente novamente.');
            }
            throw error;
        }
    },

    async getComanda(id) {
        if (!this.useMySQL) {
            const comandas = await this.getComandas();
            return comandas.find(c => c.id == id);
        }

        if (String(id).startsWith('local-')) {
            const queue = this.getComandaQueue();
            const row = queue.find((q) => q.id_local === String(id));
            if (row) {
                return this.buildPendingComandaFromQueueItem(row);
            }
        }
        
        let c = null;
        try {
            c = await API.getComanda(id);
        } catch (_e) {
            const cache = this.getComandasCache();
            const cached = cache.find((x) => String(x.id) === String(id));
            if (cached) return cached;
            throw _e;
        }
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
            const payload = {
                id: comanda.id,
                versao: Number(comanda.versao || 1),
                cliente: comanda.cliente,
                forma_pagamento: comanda.formaPagamento || null,
                motivos_remocao: comanda.motivosRemocao || {},
                itens: (comanda.itens || []).map(i => ({
                    id: i.id,
                    produto_id: i.produtoId,
                    nome: i.nome,
                    categoria: i.categoria,
                    quantidade: i.quantidade,
                    valor: i.valor,
                    observacoes: i.observacoes || null
                }))
            };

            if (typeof navigator !== 'undefined' && navigator.onLine === false) {
                this.queueComandaUpdatePayload(payload, 'aguardando_conexao');
                this.notifySyncStatus('Sem conexao. Comanda salva em pendencia e sera enviada automaticamente.', 'warn');
                return { success: true, pendingSync: true };
            }

            try {
                const result = await API.updateComanda(payload);
                if (result && result.versao_nova) {
                    comanda.versao = Number(result.versao_nova);
                }
                return result;
            } catch (error) {
                if (this.isRetriableSendError(error)) {
                    this.queueComandaUpdatePayload(payload, 'aguardando_servidor');
                    this.notifySyncStatus('Tentando enviar comanda... sem resposta do servidor. Dados ficaram salvos em pendencia.', 'warn');
                    return { success: true, pendingSync: true };
                }
                throw error;
            }
        }
    },

    async deleteComanda(id, motivo = '') {
        if (!this.useMySQL) {
            let comandas = await this.getComandas();
            comandas = comandas.filter(c => c.id != id);
            await this.saveComandas(comandas);
        } else {
            await API.deleteComanda(id, motivo);
        }
    },

    async fecharComanda(id, pagamentos = []) {
        if (typeof id === 'string' && id.startsWith('local-')) {
            this.notifySyncStatus('Comanda offline ainda nao sincronizada. Aguarde envio automatico.', 'warn');
            this.processComandaQueue(true);
            throw new Error('Comanda ainda nao sincronizada com o servidor. Tente novamente em instantes.');
        }

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
        return await API.fecharComanda(id, pagamentos);
    },

    async reabrirComanda(id, motivo = '') {
        if (!this.useMySQL) {
            const comanda = await this.getComanda(id);
            if (comanda && comanda.status === 'fechada') {
                comanda.status = 'aberta';
                comanda.fechamento = null;
                await this.updateComanda(comanda);
            }
            return { success: true };
        }
        return await API.reabrirComanda(id, motivo);
    },

    async transferirMesa(comandaId, mesaDestino, motivo, allowOccupied = false) {
        if (!this.useMySQL) return { success: true };
        return await API.transferirMesa(comandaId, mesaDestino, motivo, allowOccupied);
    },

    async transferirGarcom(comandaId, funcionarioDestinoId, motivo) {
        if (!this.useMySQL) return { success: true };
        return await API.transferirGarcom(comandaId, funcionarioDestinoId, motivo);
    },

    async juntarComandas(comandaDestinoId, comandasOrigemIds, motivo) {
        if (!this.useMySQL) return { success: true, migradas: [] };
        return await API.juntarComandas(comandaDestinoId, comandasOrigemIds, motivo);
    },

    async dividirComandaPorItens(comandaOrigemId, partes, motivo) {
        if (!this.useMySQL) return { success: true, novas_comandas: [] };
        return await API.dividirComandaPorItens(comandaOrigemId, partes, motivo);
    },

    async dividirComandaPorValor(comandaOrigemId, partes, motivo) {
        if (!this.useMySQL) return { success: true, novas_comandas: [] };
        return await API.dividirComandaPorValor(comandaOrigemId, partes, motivo);
    },

    async abrirCaixa(operadorId, valorInicial, observacao = '') {
        if (!this.useMySQL) return { success: true, caixa_sessao_id: Date.now() };
        return await API.abrirCaixa(operadorId, valorInicial, observacao);
    },

    async movimentarCaixa(caixaSessaoId, tipo, valor, motivo) {
        if (!this.useMySQL) return { success: true };
        return await API.movimentarCaixa(caixaSessaoId, tipo, valor, motivo);
    },

    async fecharCaixa(caixaSessaoId, valorContado = null, observacao = '', forceClose = false) {
        if (!this.useMySQL) return { success: true, resumo: {} };
        return await API.fecharCaixa(caixaSessaoId, valorContado, observacao, forceClose);
    },

    async getStatusCaixa() {
        if (!this.useMySQL) return { success: true, caixa_aberto: false };
        return await API.getStatusCaixa();
    },

    async estornarPagamento(pagamentoId, motivo) {
        if (!this.useMySQL) return { success: true };
        return await API.estornarPagamento(pagamentoId, motivo);
    },

    async getAuditoria(params = {}) {
        if (!this.useMySQL) return { total: 0, registros: [] };
        return await API.getAuditoria(params);
    },

    async getMonitoramento(params = {}) {
        if (!this.useMySQL) return { action_log: [], api_request_log: [], error_events: [] };
        return await API.getMonitoramento(params);
    },

    async getPermissoes(tipo = 'catalogo') {
        if (!this.useMySQL) return [];
        return await API.getPermissoes(tipo);
    },

    async updateRolePermission(role, permissaoChave, allowed) {
        if (!this.useMySQL) return { success: true };
        return await API.updateRolePermission(role, permissaoChave, allowed);
    },

    async saveFichaTecnica(produtoId, itens) {
        if (!this.useMySQL) return { success: true };
        return await API.saveFichaTecnica(produtoId, itens);
    },

    async getFichaTecnica(produtoId) {
        if (!this.useMySQL) return [];
        return await API.getFichaTecnica(produtoId);
    },

    async movimentarEstoque(payload) {
        if (!this.useMySQL) return { success: true };
        return await API.movimentarEstoque(payload);
    },

    async getMovimentacoesEstoque(estoqueId = null, limit = 100) {
        if (!this.useMySQL) return [];
        return await API.getMovimentacoesEstoque(estoqueId, limit);
    },

    async getCupons(ativos = true) {
        if (!this.useMySQL) return [];
        return await API.getCupons(ativos);
    },

    async saveCupom(data) {
        if (!this.useMySQL) return { success: true, id: Date.now() };
        return await API.saveCupom(data);
    },

    async updateCupom(data) {
        if (!this.useMySQL) return { success: true };
        return await API.updateCupom(data);
    },

    async salvarConsentimentoLGPD(payload) {
        if (!this.useMySQL) return { success: true };
        return await API.salvarConsentimentoLGPD(payload);
    },

    async getConsentimentosLGPD(clienteId) {
        if (!this.useMySQL) return [];
        return await API.getConsentimentosLGPD(clienteId);
    },

    async gerarKdsTicketSetor(comandaId, setor) {
        if (!this.useMySQL) return { success: true, duplicado: false, ticket: null };
        return await API.gerarKdsTicketSetor(comandaId, setor);
    },

    async confirmarImpressaoKds(printId) {
        if (!this.useMySQL) return { success: true, print_id: printId };
        return await API.confirmarImpressaoKds(printId);
    },

    async reimprimirKds(printId) {
        if (!this.useMySQL) return { success: true, ticket: null };
        return await API.reimprimirKds(printId);
    },

    // ========== PRODUTOS ==========
    getProdutosCache() {
        try {
            const data = localStorage.getItem(this.PRODUTOS_CACHE_KEY);
            return data ? JSON.parse(data) : [];
        } catch (_e) {
            return [];
        }
    },

    getProdutosCacheMeta() {
        try {
            const data = localStorage.getItem(this.PRODUTOS_CACHE_META_KEY);
            return data ? JSON.parse(data) : { updatedAt: 0 };
        } catch (_e) {
            return { updatedAt: 0 };
        }
    },

    saveProdutosCache(produtos) {
        const lista = Array.isArray(produtos) ? [...produtos] : [];
        this._produtosMemoryCache = lista;
        this._produtosMemoryCacheAt = Date.now();
        localStorage.setItem(this.PRODUTOS_CACHE_KEY, JSON.stringify(lista));
        localStorage.setItem(this.PRODUTOS_CACHE_META_KEY, JSON.stringify({ updatedAt: Date.now() }));
    },

    shouldRefreshProdutosInBackground() {
        const minIntervalMs = 2 * 60 * 1000;
        const now = Date.now();
        return (now - Number(this._produtosLastBgRefreshAt || 0)) > minIntervalMs;
    },

    async refreshProdutosCacheInBackground() {
        if (!this.useMySQL || this._produtosRefreshPromise) return;
        if (!this.shouldRefreshProdutosInBackground()) return;

        this._produtosLastBgRefreshAt = Date.now();

        this._produtosRefreshPromise = (async () => {
            try {
                const response = await API.getProdutos();
                const produtos = Array.isArray(response)
                    ? response
                    : (response && Array.isArray(response.data) ? response.data : []);
                const normalizados = produtos.map((p) => ({
                    id: p.id,
                    nome: p.nome,
                    categoria: p.categoria,
                    preco: parseFloat(p.preco)
                }));
                this.saveProdutosCache(normalizados);
                this.notifySyncStatus('Produtos atualizados do servidor.', 'ok', { source: 'server' });
            } catch (_e) {
                // segue com cache local
            } finally {
                this._produtosRefreshPromise = null;
            }
        })();
    },

    async getProdutos(categoria = null) {
        if (!this.useMySQL) {
            const data = localStorage.getItem('comanda_produtos');
            const produtos = data ? JSON.parse(data) : [];
            if (categoria) {
                return produtos.filter(p => p.categoria === categoria);
            }
            return produtos;
        }

        const filtrarCategoria = (produtosBase) => {
            if (!categoria) return produtosBase;
            return produtosBase.filter((p) => p.categoria === categoria);
        };

        const mem = Array.isArray(this._produtosMemoryCache) ? this._produtosMemoryCache : null;
        const memAge = Date.now() - Number(this._produtosMemoryCacheAt || 0);
        const memValido = !!mem && mem.length > 0 && memAge < this.PRODUTOS_CACHE_TTL_MS;
        if (mem && mem.length > 0) {
            if (!memValido) {
                this.refreshProdutosCacheInBackground();
            }
            return filtrarCategoria(mem);
        }

        const cache = this.getProdutosCache();
        const cacheMeta = this.getProdutosCacheMeta();
        const cacheAge = Date.now() - Number(cacheMeta.updatedAt || 0);
        const cacheValido = cache.length > 0 && cacheAge < this.PRODUTOS_CACHE_TTL_MS;

        if (cache.length > 0) {
            this._produtosMemoryCache = [...cache];
            this._produtosMemoryCacheAt = Date.now();
            if (!cacheValido) {
                this.refreshProdutosCacheInBackground();
            }
            return filtrarCategoria(cache);
        }

        try {
            const response = await API.getProdutos();
            const produtos = Array.isArray(response)
                ? response
                : (response && Array.isArray(response.data) ? response.data : []);

            const normalizados = produtos.map(p => ({
                id: p.id,
                nome: p.nome,
                categoria: p.categoria,
                preco: parseFloat(p.preco)
            }));
            this.saveProdutosCache(normalizados);
            return filtrarCategoria(normalizados);
        } catch (e) {
            this.notifySyncStatus('Sem conexao com servidor. Usando catalogo local.', 'warn', { source: 'cache' });
            return filtrarCategoria(this.getProdutosCache());
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

    async getProdutoRegras(tipo, params = {}) {
        if (!this.useMySQL) return [];
        return await API.getProdutoRegras(tipo, params);
    },

    async criarProdutoRegra(tipo, payload) {
        if (!this.useMySQL) return { success: true, id: Date.now() };
        return await API.criarProdutoRegra(tipo, payload);
    },

    async atualizarProdutoRegra(tipo, payload) {
        if (!this.useMySQL) return { success: true };
        return await API.atualizarProdutoRegra(tipo, payload);
    },

    async removerProdutoRegra(tipo, id) {
        if (!this.useMySQL) return { success: true };
        return await API.removerProdutoRegra(tipo, id);
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
