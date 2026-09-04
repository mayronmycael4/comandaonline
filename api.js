// API Client para comunicação com backend PHP/MySQL
const API = {
    baseUrl: (() => {
        try {
            return new URL('./', document.baseURI || window.location.href).href;
        } catch (_e) {
            return window.location.origin + window.location.pathname.replace(/[^/]*$/, '/');
        }
    })(),
    requestTimeoutMs: 10000,
    _challengeWarmupPromise: null,

    buildUrl(endpoint = '') {
        try {
            return new URL(String(endpoint || ''), this.baseUrl).href;
        } catch (_e) {
            return this.baseUrl + String(endpoint || '');
        }
    },

    isHtmlResponse(text = '') {
        const sample = String(text || '').trim().toLowerCase();
        return sample.startsWith('<!doctype html')
            || sample.startsWith('<html')
            || sample.includes('<body');
    },

    isHostingChallenge(text = '') {
        const sample = String(text || '').toLowerCase();
        return sample.includes('/aes.js')
            || sample.includes('function tonumbers(')
            || sample.includes('browser security')
            || sample.includes('ifastnet');
    },

    async warmupHostingChallenge(pathHint = 'setup_status.php') {
        if (this._challengeWarmupPromise) {
            return this._challengeWarmupPromise;
        }

        this._challengeWarmupPromise = new Promise((resolve) => {
            const iframe = document.createElement('iframe');
            iframe.style.position = 'fixed';
            iframe.style.width = '1px';
            iframe.style.height = '1px';
            iframe.style.opacity = '0';
            iframe.style.pointerEvents = 'none';
            iframe.setAttribute('aria-hidden', 'true');

            let cleaned = false;
            const cleanup = () => {
                if (cleaned) return;
                cleaned = true;
                try {
                    if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
                } catch (_e) {
                    // noop
                }
                this._challengeWarmupPromise = null;
            };

            const finish = () => {
                cleanup();
                resolve(true);
            };

            const timeoutId = window.setTimeout(() => {
                finish();
            }, 3500);

            iframe.addEventListener('load', () => {
                // Alguns gateways fazem redirecionamento JS/cookie e recarregam em seguida.
                window.setTimeout(() => {
                    window.clearTimeout(timeoutId);
                    finish();
                }, 450);
            });

            document.body.appendChild(iframe);
            iframe.src = this.buildUrl(`${pathHint}${pathHint.includes('?') ? '&' : '?'}_challenge_warmup=1&_t=${Date.now()}`);
        });

        return this._challengeWarmupPromise;
    },
    
    async request(endpoint, method = 'GET', data = null, attempt = 0) {
        const controller = new AbortController();
        const timeoutId = window.setTimeout(() => controller.abort(), this.requestTimeoutMs);
        const upperMethod = String(method || 'GET').toUpperCase();
        const options = {
            method: upperMethod,
            headers: {
                'Content-Type': 'application/json'
            },
            signal: controller.signal,
            credentials: 'same-origin',
            cache: 'no-store'
        };

        let endpointFinal = endpoint;
        if (data && (upperMethod === 'POST' || upperMethod === 'PUT')) {
            let payload = data;
            try {
                const sessionRaw = localStorage.getItem('comanda_session');
                const session = sessionRaw ? JSON.parse(sessionRaw) : null;
                if (session && typeof payload === 'object' && !Array.isArray(payload)) {
                    payload = {
                        ...payload,
                        _audit: {
                            actor_id: session.funcionarioId || null,
                            actor_nome: session.nome || null,
                            actor_login: session.login || null
                        }
                    };
                }
            } catch (_e) {
                // Se falhar em ler sessao, segue sem metadados de auditoria.
            }
            options.body = JSON.stringify(payload);
        } else if (upperMethod === 'DELETE') {
            try {
                const sessionRaw = localStorage.getItem('comanda_session');
                const session = sessionRaw ? JSON.parse(sessionRaw) : null;
                if (session && session.funcionarioId) {
                    const sep = endpoint.includes('?') ? '&' : '?';
                    endpointFinal = `${endpoint}${sep}audit_actor_id=${encodeURIComponent(session.funcionarioId)}&audit_actor_nome=${encodeURIComponent(session.nome || '')}&audit_actor_login=${encodeURIComponent(session.login || '')}`;
                }
            } catch (_e) {
                // sem sessão, segue normal
            }
        }
        
        try {
            const response = await fetch(this.buildUrl(endpointFinal), options);
            const text = await response.text();
            
            // Tenta parsear como JSON
            let result;
            try {
                result = JSON.parse(text);
            } catch (e) {
                console.error('Resposta não é JSON:', text.substring(0, 500));

                if (this.isHostingChallenge(text)) {
                    if (attempt < 1 && typeof document !== 'undefined' && document.body) {
                        await this.warmupHostingChallenge('setup_status.php');
                        return this.request(endpoint, method, data, attempt + 1);
                    }

                    throw new Error('A hospedagem retornou uma página de proteção (aes.js) no lugar da API. Atualize a página e tente novamente. Se continuar, abra uma página PHP do sistema no navegador para liberar a sessão de segurança da hospedagem.');
                }

                if (this.isHtmlResponse(text)) {
                    throw new Error('O servidor retornou HTML no lugar de JSON. Verifique URL/base da API e configuração da hospedagem.');
                }

                throw new Error('Erro no servidor. Tente atualizar a página; se persistir, verifique se Apache/MySQL estão ativos e abra o setup para validar o banco.');
            }
            
            if (!response.ok) {
                const apiError = new Error(result.error || result.message || 'Erro na requisição');
                apiError.status = response.status;
                apiError.code = result.error_code || null;
                apiError.payload = result;
                throw apiError;
            }
            
            return result;
        } catch (error) {
            if (error && error.name === 'AbortError') {
                const timeoutError = new Error('Tempo de resposta excedido. Verifique conexao com o servidor, status do MySQL e tente novamente em alguns segundos.');
                timeoutError.status = 0;
                timeoutError.code = 'REQUEST_TIMEOUT';
                throw timeoutError;
            }
            console.error('API Error:', error);
            throw error;
        } finally {
            window.clearTimeout(timeoutId);
        }
    },
    
    // Empresa
    getEmpresa() {
        return this.request('empresa.php');
    },
    
    saveEmpresa(data) {
        return this.request('empresa.php', 'POST', data);
    },
    
    // Funcionários
    getFuncionarios() {
        return this.request('funcionarios.php');
    },
    
    getFuncionario(id) {
        return this.request(`funcionarios.php?id=${id}`);
    },
    
    getFuncionarioByLogin(login) {
        return this.request(`funcionarios.php?login=${login}`);
    },
    
    addFuncionario(data) {
        return this.request('funcionarios.php', 'POST', data);
    },
    
    updateFuncionario(data) {
        return this.request('funcionarios.php', 'PUT', data);
    },
    
    deleteFuncionario(id) {
        return this.request(`funcionarios.php?id=${id}`, 'DELETE');
    },
    
    // Login
    login(login, senha) {
        return this.request('login.php', 'POST', { login, senha });
    },

    loginPin(login, pin) {
        return this.request('login_pin.php', 'POST', { login, pin });
    },

    getSessionStatus(funcionarioId) {
        return this.request(`sessao_status.php?funcionario_id=${funcionarioId}`);
    },
    
    // Clientes
    getClientes() {
        return this.request('clientes.php');
    },
    
    getCliente(id) {
        return this.request(`clientes.php?id=${id}`);
    },
    
    getClienteByCpf(cpf) {
        return this.request(`clientes.php?cpf=${cpf}`);
    },
    
    addCliente(data) {
        return this.request('clientes.php', 'POST', data);
    },
    
    updateCliente(data) {
        return this.request('clientes.php', 'PUT', data);
    },
    
    // Produtos
    getProdutos(categoria = null) {
        const url = categoria ? `produtos.php?categoria=${categoria}` : 'produtos.php';
        return this.request(url);
    },
    
    getProduto(id) {
        return this.request(`produtos.php?id=${id}`);
    },
    
    addProduto(data) {
        return this.request('produtos.php', 'POST', data);
    },
    
    updateProduto(data) {
        return this.request('produtos.php', 'PUT', data);
    },

    getProdutoRegras(tipo, params = {}) {
        const query = new URLSearchParams({ tipo, ...params }).toString();
        return this.request(`produtos_regras.php?${query}`);
    },

    criarProdutoRegra(tipo, payload) {
        return this.request('produtos_regras.php', 'POST', {
            tipo,
            ...payload
        });
    },

    atualizarProdutoRegra(tipo, payload) {
        return this.request('produtos_regras.php', 'PUT', {
            tipo,
            ...payload
        });
    },

    removerProdutoRegra(tipo, id) {
        return this.request(`produtos_regras.php?tipo=${encodeURIComponent(tipo)}&id=${id}`, 'DELETE');
    },
    
    deleteProduto(id) {
        return this.request(`produtos.php?id=${id}`, 'DELETE');
    },
    
    // Estoque
    getEstoque() {
        return this.request('estoque.php');
    },
    
    getEstoqueAlertas() {
        return this.request('estoque.php?alertas=1');
    },
    
    addItemEstoque(data) {
        return this.request('estoque.php', 'POST', data);
    },
    
    updateItemEstoque(data) {
        return this.request('estoque.php', 'PUT', data);
    },
    
    deleteItemEstoque(id) {
        return this.request(`estoque.php?id=${id}`, 'DELETE');
    },
    
    // Comandas
    getComandas(funcionarioId = null) {
        const url = funcionarioId ? `comandas.php?funcionario_id=${funcionarioId}` : 'comandas.php';
        return this.request(url);
    },
    
    getComanda(id) {
        return this.request(`comandas.php?id=${id}`);
    },
    
    addComanda(data) {
        return this.request('comandas.php', 'POST', data);
    },
    
    updateComanda(data) {
        return this.request('comandas.php', 'PUT', data);
    },
    
    deleteComanda(id, motivo = '') {
        const motivoQs = encodeURIComponent(String(motivo || '').trim());
        return this.request(`comandas.php?id=${id}&motivo=${motivoQs}`, 'DELETE');
    },
    
    fecharComanda(comandaId, pagamentos = []) {
        return this.request('comandas_fechar.php', 'POST', {
            comanda_id: comandaId,
            pagamentos: Array.isArray(pagamentos) ? pagamentos : []
        });
    },
    
    reabrirComanda(comandaId, motivo = '') {
        return this.request('comanda_reabrir.php', 'POST', {
            comanda_id: comandaId,
            motivo: String(motivo || '').trim()
        });
    },

    transferirMesa(comandaId, mesaDestino, motivo, allowOccupied = false) {
        return this.request('comanda_operacoes.php', 'POST', {
            action: 'transferir_mesa',
            comanda_id: comandaId,
            numero_mesa_destino: mesaDestino,
            motivo,
            allow_occupied: !!allowOccupied
        });
    },

    transferirGarcom(comandaId, funcionarioDestinoId, motivo) {
        return this.request('comanda_operacoes.php', 'POST', {
            action: 'transferir_garcom',
            comanda_id: comandaId,
            funcionario_destino_id: funcionarioDestinoId,
            motivo
        });
    },

    juntarComandas(comandaDestinoId, comandasOrigemIds, motivo) {
        return this.request('comanda_operacoes.php', 'POST', {
            action: 'juntar_comandas',
            comanda_destino_id: comandaDestinoId,
            comandas_origem_ids: comandasOrigemIds,
            motivo
        });
    },

    dividirComandaPorItens(comandaOrigemId, partes, motivo) {
        return this.request('comanda_operacoes.php', 'POST', {
            action: 'dividir_por_itens',
            comanda_origem_id: comandaOrigemId,
            partes,
            motivo
        });
    },

    dividirComandaPorValor(comandaOrigemId, partes, motivo) {
        return this.request('comanda_operacoes.php', 'POST', {
            action: 'dividir_por_valor',
            comanda_origem_id: comandaOrigemId,
            partes,
            motivo
        });
    },

    abrirCaixa(operadorId, valorInicial, observacao = '') {
        return this.request('caixa_operacoes.php', 'POST', {
            action: 'abrir_caixa',
            operador_id: operadorId,
            valor_inicial: valorInicial,
            observacao
        });
    },

    movimentarCaixa(caixaSessaoId, tipo, valor, motivo) {
        return this.request('caixa_operacoes.php', 'POST', {
            action: 'movimentacao',
            caixa_sessao_id: caixaSessaoId,
            tipo,
            valor,
            motivo
        });
    },

    fecharCaixa(caixaSessaoId, valorContado = null, observacao = '', forceClose = false) {
        return this.request('caixa_operacoes.php', 'POST', {
            action: 'fechar_caixa',
            caixa_sessao_id: caixaSessaoId,
            valor_contado: valorContado,
            observacao,
            force_close: !!forceClose
        });
    },

    getStatusCaixa() {
        return this.request('caixa_operacoes.php', 'POST', {
            action: 'status_caixa'
        });
    },

    estornarPagamento(pagamentoId, motivo) {
        return this.request('pagamento_operacoes.php', 'POST', {
            action: 'estornar_pagamento',
            pagamento_id: pagamentoId,
            motivo
        });
    },

    getAuditoria(params = {}) {
        const queryParams = { ...params };
        try {
            const sessionRaw = localStorage.getItem('comanda_session');
            const session = sessionRaw ? JSON.parse(sessionRaw) : null;
            if (session && session.funcionarioId) {
                queryParams.audit_actor_id = session.funcionarioId;
                queryParams.audit_actor_nome = session.nome || '';
                queryParams.audit_actor_login = session.login || '';
            }
        } catch (_e) {
            // segue sem metadados de auditoria
        }
        const query = new URLSearchParams(queryParams).toString();
        return this.request(`auditoria.php${query ? `?${query}` : ''}`);
    },

    getMonitoramento(params = {}) {
        const queryParams = { ...params };
        try {
            const sessionRaw = localStorage.getItem('comanda_session');
            const session = sessionRaw ? JSON.parse(sessionRaw) : null;
            if (session && session.funcionarioId) {
                queryParams.audit_actor_id = session.funcionarioId;
                queryParams.audit_actor_nome = session.nome || '';
                queryParams.audit_actor_login = session.login || '';
            }
        } catch (_e) {
            // segue sem metadados de auditoria
        }
        const query = new URLSearchParams(queryParams).toString();
        return this.request(`monitoramento.php${query ? `?${query}` : ''}`);
    },

    getPermissoes(tipo = 'catalogo') {
        return this.request(`permissoes.php?tipo=${encodeURIComponent(tipo)}`);
    },

    updateRolePermission(role, permissaoChave, allowed) {
        return this.request('permissoes.php', 'PUT', {
            action: 'role',
            role,
            permissao_chave: permissaoChave,
            allowed: !!allowed
        });
    },

    saveFichaTecnica(produtoId, itens) {
        return this.request('produto_fichas_tecnicas.php', 'POST', {
            produto_id: produtoId,
            itens
        });
    },

    getFichaTecnica(produtoId) {
        return this.request(`produto_fichas_tecnicas.php?produto_id=${produtoId}`);
    },

    movimentarEstoque(payload) {
        return this.request('estoque_movimentacao.php', 'POST', payload);
    },

    getMovimentacoesEstoque(estoqueId = null, limit = 100) {
        const qs = new URLSearchParams({ limit: String(limit), ...(estoqueId ? { estoque_id: String(estoqueId) } : {}) }).toString();
        return this.request(`estoque_movimentacao.php?${qs}`);
    },

    getCupons(ativos = true) {
        return this.request(`cupons.php?ativos=${ativos ? '1' : '0'}`);
    },

    saveCupom(data) {
        return this.request('cupons.php', 'POST', data);
    },

    updateCupom(data) {
        return this.request('cupons.php', 'PUT', data);
    },

    salvarConsentimentoLGPD(payload) {
        return this.request('lgpd_consentimento.php', 'POST', payload);
    },

    getConsentimentosLGPD(clienteId) {
        return this.request(`lgpd_consentimento.php?cliente_id=${clienteId}`);
    },

    getQrMenuToken(mesa) {
        return this.request(`qr_menu_token.php?mesa=${encodeURIComponent(mesa)}`);
    },

    getQrMenuInit(mesa, token) {
        return this.request(`qr_menu.php?action=init&mesa=${encodeURIComponent(mesa)}&token=${encodeURIComponent(token)}`);
    },

    getQrMenuPedidos(mesa, token) {
        return this.request(`qr_menu.php?action=pedidos&mesa=${encodeURIComponent(mesa)}&token=${encodeURIComponent(token)}`);
    },

    enviarQrMenuPedido(payload) {
        return this.request('qr_menu.php', 'POST', payload);
    },
    
    // Lista de Compras
    getListaCompras(pendentes = false) {
        const url = pendentes ? 'lista_compras.php?pendentes=1' : 'lista_compras.php';
        return this.request(url);
    },
    
    addItemListaCompras(data) {
        return this.request('lista_compras.php', 'POST', data);
    },
    
    updateItemListaCompras(data) {
        return this.request('lista_compras.php', 'PUT', data);
    },
    
    deleteItemListaCompras(id) {
        return this.request(`lista_compras.php?id=${id}`, 'DELETE');
    },

    // Zona de perigo (reset total)
    resetDatabase(login, senha, confirm) {
        return this.request('reset.php', 'POST', { login, senha, confirm });
    },

    // Backup completo (todas as tabelas)
    exportFullBackup() {
        return this.request('backup.php');
    },

    restoreFullBackup(backup, login, senha) {
        return this.request('backup.php', 'POST', {
            action: 'restore',
            backup,
            login,
            senha
        });
    },
    
    // Relatórios
    getRelatorio(tipo, params = {}) {
        const queryParams = new URLSearchParams({ tipo, ...params }).toString();
        return this.request(`relatorios.php?${queryParams}`);
    },

    getRelatorioCsvUrl(tipo, params = {}) {
        const queryParams = new URLSearchParams({ tipo, format: 'csv', ...params }).toString();
        return this.buildUrl(`relatorios.php?${queryParams}`);
    },

    getAutomacaoMarketing(tipo = 'aniversario', params = {}) {
        const query = new URLSearchParams({ tipo, ...params }).toString();
        return this.request(`automacoes_marketing.php?${query}`);
    },

    executarAutomacaoMarketing(tipo = 'aniversario', clientes = []) {
        return this.request('automacoes_marketing.php', 'POST', {
            tipo,
            clientes: Array.isArray(clientes) ? clientes : []
        });
    },

    getNotificacoes(funcionarioId, status = 'pendente', limit = 15) {
        return this.request(`notificacoes.php?funcionario_id=${funcionarioId}&status=${encodeURIComponent(status)}&limit=${limit}`);
    },

    gerarKdsTicketSetor(comandaId, setor) {
        return this.request('kds_impressao.php', 'POST', {
            action: 'gerar',
            comanda_id: comandaId,
            setor
        });
    },

    confirmarImpressaoKds(printId) {
        return this.request('kds_impressao.php', 'POST', {
            action: 'confirmar',
            print_id: printId
        });
    },

    reimprimirKds(printId) {
        return this.request('kds_impressao.php', 'POST', {
            action: 'reimprimir',
            print_id: printId
        });
    },

    marcarNotificacaoLida(id, funcionarioId) {
        return this.request('notificacoes.php', 'PUT', {
            id,
            funcionario_id: funcionarioId,
            status: 'lida'
        });
    }
};
